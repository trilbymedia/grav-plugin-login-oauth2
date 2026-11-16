<?php

namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Grav;
use Grav\Common\Session;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Common\Utils;
use GuzzleHttp\Client;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider as LeagueGenericProvider;
use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;

class GenericProvider extends BaseProvider
{
    /** @var string */
    protected $name = 'Generic';
    /** @var string */
    protected $classname = LeagueGenericProvider::class;
    /** @var string[]|null Group names the provider sent (groups claim), null if it sent none */
    protected $idp_groups;
    /** @var bool "Extra Debugging" of the plugin, read once */
    protected $debug;

    /**
     * GenericProvider constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->debug = (bool)Grav::instance()['config']->get('plugins.login-oauth2.debug');
    }

    /**
     * @param array $options
     * @return bool
     */
    public static function checkIfActive(array $options): bool
    {
        $client_id = $options['client_id'] ?? false;
        $authorization_endpoint = $options['authorization_endpoint'] ?? false;
        $token_endpoint = $options['token_endpoint'] ?? false;
        $userinfo_endpoint = $options['userinfo_endpoint'] ?? false;

        return $client_id && $authorization_endpoint && $token_endpoint && $userinfo_endpoint && parent::checkIfActive($options);
    }

    /**
     * @param array $options
     */
    public function initProvider(array $options): void
    {
        $options += [
            'clientId'                => $this->config->get('providers.generic.client_id'),
            'clientSecret'            => $this->config->get('providers.generic.client_secret'),
            'urlAuthorize'            => $this->config->get('providers.generic.authorization_endpoint'),
            'urlAccessToken'          => $this->config->get('providers.generic.token_endpoint'),
            'urlResourceOwnerDetails' => $this->config->get('providers.generic.userinfo_endpoint'),
            'responseResourceOwnerId' => $this->config->get('providers.generic.userdata_id'),
            'scopeSeparator'          => $this->config->get('providers.generic.options.scope_separator') === 'comma' ? ',' : ' ',
            'pkceMethod'              => $this->config->get('providers.generic.options.pkce_method')
        ];

        parent::initProvider($options);

        // "Verify Certificate" off (Security section): no certificate checks of the token and userinfo requests. League
        // passes "verify" to its HTTP client only along with a proxy, hence a new client (keeping timeout and proxy).
        if (!$this->config->get('providers.generic.verify_peer', true)) {
            $client_options = ['verify' => false] + array_intersect_key($options, array_flip(['timeout', 'proxy']));
            $this->provider->setHttpClient(new Client($client_options));
        }
    }

    /**
     * @return string
     */
    public function getAuthorizationUrl(): string
    {
        $options = ['state' => $this->state];
        $options['scope'] = $this->config->get('providers.generic.options.scope');

        $authorizationUrl = $this->provider->getAuthorizationUrl($options);

        // The PKCE code is generated with the authorization URL and is needed again for the access token request
        // in the callback, which uses a new provider instance: store it in the session (null if PKCE is disabled).
        /** @var Session $session */
        $session = Grav::instance()['session'];
        $session->oauth2_pkce_code = $this->provider->getPkceCode();

        return $authorizationUrl;
    }

    /**
     * Requests an access token using a specified grant and option set.
     *
     * @param mixed $grant
     * @param array $options
     * @return AccessTokenInterface
     * @throws IdentityProviderException
     */
    public function getAccessToken($grant, array $options = []): AccessTokenInterface
    {
        // Restore the PKCE code stored in the session by getAuthorizationUrl().
        /** @var Session $session */
        $session = Grav::instance()['session'];
        $pkce_code = $session->oauth2_pkce_code;
        unset($session->oauth2_pkce_code);

        if ($pkce_code) {
            $this->provider->setPkceCode($pkce_code);
        }

        return parent::getAccessToken($grant, $options);
    }

    /**
     * Maps the userinfo response to the user data the plugin merges into the Grav user, with the claim names set for
     * this side (site or admin login).
     *
     * Before that, it denies the login (exception) without a usable user ID, or with an email address the provider
     * doesn't confirm as verified while "Enforce Verified Email" is on. With group sync on, it also keeps the groups
     * claim for syncGroups(), which the plugin calls later, once it has the Grav user.
     *
     * Claims the provider doesn't send are null here: the plugin drops those before merging, so they don't erase the
     * values of an existing Grav user. The `generic` sub-array (only stored with "Store Provider data") replaces the
     * stored one as a whole.
     *
     * @param ResourceOwnerInterface|GenericResourceOwner $user The userinfo response
     * @return array id and login (the Grav username is generic.<id>.<login>), fullname, email, generic
     * @throws \RuntimeException To deny the login; the plugin shows the message to the user
     */
    public function getUserData(ResourceOwnerInterface $user): array
    {
        \assert($user instanceof GenericResourceOwner);

        // The whole response as the provider sent it, to check the claim names (Extra Debugging only).
        $data = $user->toArray();
        $this->debug('userinfo response', $data);

        // Without a unique ID all users would be mapped to the same Grav user. It becomes part of the Grav username,
        // so only a non-empty text or a number.
        $id_key = (string)$this->config->get('providers.generic.userdata_id');
        $id = $data[$id_key] ?? '';
        if ((!is_string($id) && !is_int($id)) || $id === '') {
            throw new \RuntimeException('User ID claim "' . $id_key . '" missing or invalid in the userinfo response');
        }

        // Otherwise anyone registering at the provider with someone else's email could log in as the Grav user with it.
        $email_verified = $this->getEmailVerified($data);
        if ($email_verified !== true && $this->config->get('providers.generic.enforce_email_verified')) {
            $this->log('warning', 'login denied, email not verified', $id);

            throw new \RuntimeException(Grav::instance()['language']->translate('PLUGIN_LOGIN_OAUTH2.ERROR_EMAIL_NOT_VERIFIED'));
        }

        // Only read with group sync on, so an invalid groups claim is only reported (warning) when it matters.
        if ($this->config->get('providers.generic.group_sync')) {
            $groups_key = (string)$this->config->get('providers.generic.userdata_groups');
            $this->idp_groups = $this->getGroupNames($data[$groups_key] ?? null);
        }

        // Only non-empty texts, so e.g. an array claim can't end up in the user's name or email.
        $text = function (string $setting) use ($data): ?string {
            $value = $data[(string)$this->config->get("providers.generic.{$setting}")] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        };

        return [
            'id'         => (string)$id,
            'login'      => $text('userdata_login'),
            'fullname'   => $text('userdata_fullname'),
            'email'      => $text('userdata_email'),
            // Only the provider data worth keeping, not the whole resource owner details.
            'generic'    => [
                'email_verified' => $email_verified,
                'avatar_url'     => $this->getAvatar($data),
            ],
        ];
    }

    /**
     * Grav username of this identity: "generic." + a hash of the token endpoint and the user ID claim. The endpoint
     * namespaces the ID per identity provider (site and admin can use different ones), the hash keeps the key
     * fixed-length and filesystem safe, so no ID or username claim can collide with another or break the account file.
     *
     * @param array $userdata From getUserData()
     * @return string
     */
    public function getUsername(array $userdata): string
    {
        $authority = (string)$this->config->get('providers.generic.token_endpoint');

        return 'generic.' . substr(hash('sha256', $authority . "\0" . $userdata['id']), 0, 32);
    }

    /**
     * Whether the provider verified the email address (OpenID Connect claim "email_verified"), null if it doesn't say.
     *
     * @param array $data
     * @return bool|null
     */
    public function getEmailVerified(array $data): ?bool
    {
        $claim = (string)$this->config->get('providers.generic.userdata_email_verified');
        if (!isset($data[$claim])) {
            return null;
        }

        // Some providers send it as string ("true" / "false").
        $email_verified = filter_var($data[$claim], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($email_verified === null) {
            $this->log('warning', "ignored claim \"{$claim}\", not a boolean", $data[$claim]);
        }

        return $email_verified;
    }

    /**
     * Avatar URL from the picture claim (OpenID Connect: picture), if "Avatar from Picture" is on. Stored as
     * generic.avatar_url, which Grav's getAvatarUrl() uses when the user has no uploaded avatar (Admin2 only shows
     * uploaded ones).
     *
     * Browsers load the picture directly from that URL, so only http(s) URLs are used. Anything else, e.g. a data: URI
     * carrying the whole image (often many KB, which would be stored on the user and in the session), is logged (cut,
     * see forLog()) and ignored.
     *
     * @param array $data The userinfo response
     * @return string|null Null if off, not sent or not an http(s) URL
     */
    public function getAvatar(array $data): ?string
    {
        if (!$this->config->get('providers.generic.avatar_from_picture')) {
            return null;
        }

        $claim = (string)$this->config->get('providers.generic.userdata_picture');
        $picture = $data[$claim] ?? null;
        if ($picture === null) {
            return null;
        }

        // http:// or https:// (any case), and a well-formed URL (e.g. with a host, without spaces).
        if (!is_string($picture) || !preg_match('#^https?://#i', $picture)
            || !filter_var($picture, FILTER_VALIDATE_URL)) {
            $this->log('warning', "ignored claim \"{$claim}\", not an http(s) URL", $picture);

            return null;
        }

        return $picture;
    }

    /**
     * Group sync: adds the user to the Grav groups matching the identity provider's groups, and removes the ones this
     * side's sync (site or admin login) added before that the provider no longer sends. Groups assigned in Grav are
     * never touched.
     *
     * The plugin calls it after merging the provider's user data into the Grav user, and before default groups, saving
     * the user and Grav's access check, so the groups count for this login already. It uses the groups claim kept by
     * getUserData(): no or an invalid claim leaves the groups unchanged, an empty list removes the synced ones.
     *
     * Each provider group that passes the filter becomes the Grav group named after the slug of prefix + name (see
     * groupName()). Groups missing in Grav are skipped, or created without permissions ("Create Missing Grav Groups").
     *
     * @param UserInterface $user The Grav user of this login (saved later by the plugin, if so configured)
     * @return void
     */
    public function syncGroups(UserInterface $user): void
    {
        if (!$this->config->get('providers.generic.group_sync')) {
            return;
        }
        $grav = Grav::instance();
        // Admin logins only with the admin's "Save Grav user": the new admin reads users from their account file, so
        // synced groups would be gone right after the login.
        if ($grav['oauth2']->isAdmin() && !$grav['oauth2']->saveGravUser()) {
            $this->log('warning', 'group sync skipped for the admin login, "Save Grav user" (Admin tab) is off');

            return;
        }
        if ($this->idp_groups === null) {
            return;
        }

        $config = $grav['config'];
        $patterns = $this->config->get('providers.generic.group_filter');
        // "group_filter: false" (or "no") instead of a list: no filter, as an empty one.
        $patterns = $patterns === false ? [] : (array)$patterns;
        // An entry that is a list or map typed by mistake, or a value YAML doesn't keep as written (2.0, yes, true
        // without quotes), is no group name or pattern. It's ignored, so the other entries keep working and a group
        // taken away at the provider is still taken away here; reading it as text would sync other groups (yes
        // becomes "1").
        $invalid = array_filter($patterns, static function ($pattern): bool {
            return $pattern !== null && !is_string($pattern) && !is_int($pattern);
        });
        // Patterns as YAML gives them (a whole number stays one), without the empty and the invalid ones.
        $filter = array_filter(array_diff_key($patterns, $invalid), static function ($pattern): bool {
            return $pattern !== null && (string)$pattern !== '';
        });
        if ($invalid) {
            $hint = '(put numbers with a decimal point and yes/no/true/false in quotes)';
            // No valid entry left: the sync is skipped, so the user's groups stay as they are until the setting is
            // fixed. Without a filter every group would be synced, and a filter that matches nothing would take every
            // synced group from each user who logs in, also the ones that let them into the admin.
            if (!$filter) {
                $this->log('warning', 'group sync skipped, "Provider Groups to Sync" has no entry that is a group name or pattern ' . $hint, array_values($invalid));

                return;
            }
            $this->log('warning', 'group sync: ignored the entries of "Provider Groups to Sync" that are no group name or pattern ' . $hint, array_values($invalid));
        }
        $prefix = (string)$this->config->get('providers.generic.group_prefix');
        // A prefix without letters or digits ("!", "-") would vanish from the slugs, giving the provider every Grav group.
        if ($prefix !== '' && $this->groupName($prefix) === '') {
            $this->log('warning', 'group sync skipped, "Grav Group Name Prefix" has no letters or digits', $prefix);

            return;
        }
        $create = (bool)$this->config->get('providers.generic.group_autocreate');

        // 1. The Grav groups the provider's groups stand for ($wanted, keyed by name, so each only once).
        $wanted = $not_in_filter = $no_grav_group = $missing = [];
        foreach ($this->idp_groups as $idp_group) {
            if ($filter && !$this->matchesGroupFilter($idp_group, $filter)) {
                $not_in_filter[] = $idp_group;
                continue;
            }

            // Skipped if the group's own name has no letters or digits ("★", "!!!"): with a prefix it would become a
            // group named just the prefix (e.g. "staff-" + "★" becomes "staff"), the same one for all such groups.
            if ($this->groupName($idp_group) === '') {
                continue;
            }
            $name = $this->groupName($prefix . $idp_group);

            // Not a Grav group yet: skipped, or collected to be created together below (the first provider name of a
            // Grav name becomes its readable name).
            if ($config->get("groups.{$name}") === null) {
                if (!$create) {
                    $no_grav_group[] = $name;
                    continue;
                }
                $missing += [$name => $idp_group];
            }
            $wanted[$name] = $name;
        }
        // Groups that couldn't be created (error logged) aren't assigned.
        if ($missing && !$this->createGroups($missing)) {
            $wanted = array_diff_key($wanted, $missing);
        }

        // 2. The user's groups. Site and admin logins sync with their own settings, so `synced_groups` remembers per
        // side which groups its sync added. Only those follow the provider; all others ($manual: assigned in Grav, or
        // default groups) stay as they are.
        //  - added:   wanted, but not a group of the user yet
        //  - removed: added by this side's sync before, no longer wanted, and not held by the other side's sync too
        //  - synced:  this side's groups from now on, the wanted ones except those assigned in Grav, so a later sync
        //             never removes a group the user already had
        $side = $grav['oauth2']->isAdmin() ? 'admin' : 'site';
        $other = (array)$user->get('synced_groups.' . ($side === 'admin' ? 'site' : 'admin'));
        $groups = (array)$user->get('groups');
        $synced = (array)$user->get("synced_groups.{$side}");
        $manual = array_values(array_diff($groups, $synced, $other));
        $added = array_values(array_diff($wanted, $groups));
        $removed = array_values(array_diff($synced, $wanted, $other));
        $synced = array_values(array_diff($wanted, $manual));

        // Only changed when needed: the other groups keep their order, new ones are appended.
        if ($added || $removed) {
            $groups = array_values(array_merge(array_diff($groups, $removed), $added));
            $user->set('groups', $groups);
        }
        $user->set("synced_groups.{$side}", $synced);

        $this->debug('group sync', [
            'added'         => $added,
            'removed'       => $removed,
            'not in filter' => $not_in_filter,
            'no Grav group' => $no_grav_group,
            'groups'        => $groups,
        ]);
    }

    /**
     * Group names from the groups claim: a list of strings (or a single string). A missing claim means no groups:
     * many providers (e.g. Keycloak) leave it out when the user has none, so keeping the synced groups would keep
     * access the provider took away. Anything else (objects, a map of roles) is logged and also counts as no groups.
     *
     * @param mixed $claim
     * @return string[]
     */
    protected function getGroupNames($claim): array
    {
        if ($claim === null) {
            return [];
        }
        if (is_string($claim)) {
            $claim = [$claim];
        }
        if (!is_array($claim) || array_values($claim) !== $claim || array_filter($claim, 'is_string') !== $claim) {
            $this->log('warning', 'groups claim is not a list of names, treated as no groups', $claim);

            return [];
        }

        return array_values(array_filter($claim, static function (string $group): bool {
            return $group !== '';
        }));
    }

    /**
     * Whether a group of the provider matches one of the filter patterns (* and ? as wildcards, case-insensitive), by
     * its name or its slug, so "grav-*" and "Grav *" both match "Grav Editors".
     *
     * @param string $group
     * @param string[] $filter
     * @return bool
     */
    protected function matchesGroupFilter(string $group, array $filter): bool
    {
        $slug = $this->groupName($group);
        foreach ($filter as $pattern) {
            if (Utils::matchWildcard((string)$pattern, $group) || Utils::matchWildcard((string)$pattern, $slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Grav group name: lowercase slug (e.g. "Grav Editors" becomes "grav-editors"). With the intl extension, other
     * letters are transliterated first ("Ärzte" becomes "arzte"), without it they're dropped.
     *
     * @param string $name
     * @return string
     */
    protected function groupName(string $name): string
    {
        static $transliterator;
        if ($transliterator === null && class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
        }
        if ($transliterator) {
            $name = $transliterator->transliterate($name) ?: $name;
        }

        return trim((string)preg_replace('/[^a-z0-9_-]+/', '-', mb_strtolower($name)), '-');
    }

    /**
     * Creates Grav groups for the group sync: without permissions (they're granted in Grav), with the provider's group
     * name as readable name. They're written to user/config/groups.yaml, where Grav keeps groups for both account
     * types, and set in the config loaded for this request, so Grav's access check of this login knows them already.
     *
     * syncGroups() calls it once per login with all missing groups, so the file is read and written only once.
     *
     * @param string[] $missing Grav group names as keys, the provider's group names as values
     * @return bool Whether they exist now; false if the file couldn't be written (error logged), then they're not
     *              assigned in this login
     */
    protected function createGroups(array $missing): bool
    {
        $grav = Grav::instance();

        // Read, changed and written under a lock, so logins creating groups at the same time don't overwrite each
        // other's. free() drops the content this file instance may have cached earlier in this request, so it's read
        // again under the lock. lock() waits for other logins writing the file, and fails (exception) if it can't be
        // opened for writing, e.g. read-only.
        $file = CompiledYamlFile::instance($grav['locator']->findResource('user://config/groups.yaml', true, true));
        $file->free();
        try {
            $file->lock();
            $groups = (array)$file->content();
            // Another login may have created some of them meanwhile: only the ones still missing are added, the others
            // are used as they are in the file.
            $created = array_diff_key($missing, $groups);
            foreach ($created as $name => $idp_group) {
                $groups[$name] = [
                    'groupname'    => $name,
                    'readableName' => $idp_group,
                    'description'  => 'Created by the group sync of the Login OAuth2 Generic provider',
                    'enabled'      => true,
                ];
            }
            if ($created) {
                $file->save($groups);
            }
        } catch (\RuntimeException $e) {
            $this->log('error', 'group sync could not create Grav groups, skipped them',
                ['groups' => array_keys($missing), 'error' => $e->getMessage()]);

            return false;
        } finally {
            // Releases the lock and the cached content.
            $file->free();
        }

        $this->debug('starting group sync for created Grav groups...');
        // The groups as they are in the file now, also into the config loaded for this request (it was read before).
        foreach (array_keys($missing) as $name) {
            $grav['config']->set("groups.{$name}", (array)$groups[$name]);
        }
        if ($created) {
            $this->log('info', 'group sync created Grav groups', array_keys($created));
        }

        return true;
    }

    /**
     * Writes a debug message to the Grav log, if "Extra Debugging" is enabled in the plugin; the value is only encoded
     * then.
     *
     * @param string $message
     * @param mixed $value
     * @return void
     */
    protected function debug(string $message, $value = null): void
    {
        if ($this->debug) {
            $this->log('debug', $message, $value);
        }
    }

    /**
     * Writes a log line with the "OIDC GenericProvider:" prefix, followed by the value (see forLog()) if given.
     *
     * @param string $level
     * @param string $message
     * @param mixed $value
     * @return void
     */
    protected function log(string $level, string $message, $value = null): void
    {
        Grav::instance()['log']->{$level}('OIDC GenericProvider: ' . $message . ($value !== null ? ': ' . $this->forLog($value) : ''));
    }

    /**
     * A value for the log: JSON-encoded, so line breaks from the provider can't forge extra log lines. Texts longer than
     * 100 characters are cut, also inside arrays: e.g. a data: URI carries the whole image base64-encoded (often many
     * KB) and would flood the log.
     *
     * @param mixed $value
     * @return string
     */
    protected function forLog($value): string
    {
        $cut = static function (&$item) {
            if (is_string($item)) {
                $item = Utils::truncate($item, 100, false, ' ', '...');
            }
        };
        if (is_array($value)) {
            array_walk_recursive($value, $cut);
        } else {
            $cut($value);
        }

        return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
