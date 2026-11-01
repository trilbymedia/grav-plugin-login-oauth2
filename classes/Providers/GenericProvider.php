<?php

namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Grav;
use Grav\Common\Session;
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
        if (!isset($data[$id_key]) || $data[$id_key] === '') {
            throw new \RuntimeException('User ID claim "' . $id_key . '" not found in the userinfo response');
        }

        return [
            'id'         => $user->getId(),
            'login'      => $data[(string)$this->config->get('providers.generic.userdata_login')] ?? null,
            'fullname'   => $data[(string)$this->config->get('providers.generic.userdata_fullname')] ?? null,
            'email'      => $data[(string)$this->config->get('providers.generic.userdata_email')] ?? null,
            // Only the provider data worth keeping, not the whole resource owner details.
            'generic'    => [
                'email_verified' => $this->getEmailVerified($data),
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
        if (!isset($data['email_verified'])) {
            return null;
        }

        // Some providers send it as string ("true" / "false").
        $email_verified = filter_var($data['email_verified'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($email_verified === null) {
            $this->log('warning', 'ignored claim "email_verified", not a boolean', $data['email_verified']);
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

        if (!is_string($picture) || !filter_var($picture, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url($picture, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $this->log('warning', "ignored claim \"{$claim}\", not an http(s) URL", $picture);

            return null;
        }

        return $picture;
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
            if (is_string($item) && strlen($item) > 100) {
                $item = mb_strcut($item, 0, 100) . '...';
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
