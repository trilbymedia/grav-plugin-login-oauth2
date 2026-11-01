<?php

namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Grav;
use Grav\Common\Session;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Common\Utils;
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
     * @param ResourceOwnerInterface|GenericResourceOwner $user
     * @return array
     */
    public function getUserData(ResourceOwnerInterface $user): array
    {
        \assert($user instanceof GenericResourceOwner);

        $data = $user->toArray();
        $this->debug('userinfo response', $data);

        // Without a unique ID all users would be mapped to the same Grav user.
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

        if ($this->config->get('providers.generic.group_sync')) {
            $groups_key = (string)$this->config->get('providers.generic.userdata_groups');
            $this->idp_groups = $this->getGroupNames($data[$groups_key] ?? null);
        }

        return [
            'id'         => $user->getId(),
            'login'      => $data[(string)$this->config->get('providers.generic.userdata_login')] ?? null,
            'fullname'   => $data[(string)$this->config->get('providers.generic.userdata_fullname')] ?? null,
            'email'      => $data[(string)$this->config->get('providers.generic.userdata_email')] ?? null,
            // Only the provider data worth keeping, not the whole resource owner details.
            'generic'    => [
                'email_verified' => $email_verified,
                'avatar_url'     => $this->getAvatar($data),
            ],
        ];
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
     * Avatar URL from the picture claim (OpenID Connect: picture), if enabled. Grav uses it as the user's avatar.
     *
     * @param array $data
     * @return string|null
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

        if (!is_string($picture) || !preg_match('#^https?://#i', $picture)
            || !filter_var($picture, FILTER_VALIDATE_URL)) {
            $this->log('warning', "ignored claim \"{$claim}\", not an http(s) URL", $picture);

            return null;
        }

        return $picture;
    }

    /**
     * Group sync: adds the user to the Grav groups of the identity provider's groups and removes the ones it added
     * before; groups assigned in Grav stay.
     *
     * @param UserInterface $user
     * @return void
     */
    public function syncGroups(UserInterface $user): void
    {
        if (!$this->config->get('providers.generic.group_sync')) {
            return;
        }
        // Admin logins only with "Save Grav user": the new admin reads users from their account file, so synced groups
        // would be gone right after the login.
        $grav = Grav::instance();
        if ($grav['oauth2']->isAdmin() && !$grav['config']->get('plugins.login-oauth2.save_grav_user')) {
            $this->log('warning', 'group sync skipped for the admin login, "Save Grav user" is off');

            return;
        }
        if ($this->idp_groups === null) {
            $this->debug('group sync: no groups sent, groups unchanged');

            return;
        }

        $config = $grav['config'];
        $filter = array_filter((array)$this->config->get('providers.generic.group_filter'), 'strlen');
        $prefix = (string)$this->config->get('providers.generic.group_prefix');
        $create = (bool)$this->config->get('providers.generic.group_autocreate');

        $wanted = $not_in_filter = $no_grav_group = $missing = [];
        foreach ($this->idp_groups as $idp_group) {
            if ($filter && !$this->matchesGroupFilter($idp_group, $filter)) {
                $not_in_filter[] = $idp_group;
                continue;
            }

            $name = $this->groupName($prefix . $idp_group);
            if ($name === '') {
                continue;
            }
            if ($config->get("groups.{$name}") === null) {
                if (!$create) {
                    $no_grav_group[] = $name;
                    continue;
                }
                $missing += [$name => $idp_group];
            }
            $wanted[] = $name;
        }
        if ($missing && !$this->createGroups($missing)) {
            $wanted = array_diff($wanted, array_keys($missing));
        }
        $wanted = array_unique($wanted);

        // The user's groups: the ones assigned in Grav, plus the ones the sync manages now (`synced_groups` remembers
        // which ones the sync added, so only those follow the provider).
        $groups = (array)$user->get('groups');
        $synced = (array)$user->get('synced_groups');
        $manual = array_values(array_diff($groups, $synced));
        $added = array_values(array_diff($wanted, $groups));
        $removed = array_values(array_diff($synced, $wanted));
        $synced = array_values(array_diff($wanted, $manual));

        if ($added || $removed) {
            $groups = array_merge($manual, $synced);
            $user->set('groups', $groups);
        }
        $user->set('synced_groups', $synced);

        $this->debug('group sync', [
            'added'         => $added,
            'removed'       => $removed,
            'not in filter' => $not_in_filter,
            'no Grav group' => $no_grav_group,
            'groups'        => $groups,
        ]);
    }

    /**
     * Group names from the groups claim: a list of strings (or a single string), null if missing or invalid.
     *
     * @param mixed $claim
     * @return string[]|null
     */
    protected function getGroupNames($claim): ?array
    {
        if (is_string($claim)) {
            $claim = [$claim];
        }
        // Anything else than names (e.g. objects, or a map of roles) is invalid, not "no groups": that would remove all
        // synced groups.
        if (!is_array($claim) || array_filter($claim, 'is_string') !== $claim) {
            if ($claim !== null) {
                $this->log('warning', 'ignored groups claim, not a list of names', $claim);
            }

            return null;
        }

        return array_values(array_filter($claim, 'strlen'));
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
     * Creates Grav groups without permissions, in user/config/groups.yaml (where Grav keeps groups for both account
     * types), and in the loaded config, so they count for this login already.
     *
     * @param string[] $missing Grav group names as keys, the provider's group names as values
     * @return bool Whether they were created; if not, the error is logged
     */
    protected function createGroups(array $missing): bool
    {
        $grav = Grav::instance();

        // Locked, so logins creating groups at the same time don't overwrite each other's. free() drops content cached
        // earlier in this request, so the groups are read again under the lock.
        $file = CompiledYamlFile::instance($grav['locator']->findResource('user://config/groups.yaml', true, true));
        $file->free();
        try {
            $file->lock();
            $groups = (array)$file->content();
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
            $file->free();
        }

        foreach (array_keys($missing) as $name) {
            $grav['config']->set("groups.{$name}", (array)$groups[$name]);
            if (isset($created[$name])) {
                $this->log('info', 'group sync created Grav group', $name);
            }
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
