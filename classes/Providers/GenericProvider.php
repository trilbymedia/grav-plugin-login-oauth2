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

        // Without a unique ID all users would be mapped to the same Grav user.
        $id_key = (string)$this->config->get('providers.generic.userdata_id');
        if (!isset($data[$id_key]) || $data[$id_key] === '') {
            throw new \RuntimeException('User ID "' . $id_key . '" not found in the resource owner details');
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
        return filter_var($data['email_verified'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Avatar URL from the OpenID Connect claim "picture", if enabled. Grav uses it as the user's avatar.
     *
     * @param array $data
     * @return string|null
     */
    public function getAvatar(array $data): ?string
    {
        if (!$this->config->get('providers.generic.avatar_from_picture')) {
            return null;
        }

        $picture = $data['picture'] ?? null;
        if ($picture === null) {
            return null;
        }

        if (!is_string($picture) || !filter_var($picture, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url($picture, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            // Cut to 100 characters: a data: URI carries the whole image base64-encoded (often many KB), which would
            // flood the log. JSON-encoded, so line breaks from the provider can't forge extra log lines.
            $value = is_string($picture)
                ? json_encode(substr($picture, 0, 100) . (strlen($picture) > 100 ? '...' : ''), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                : gettype($picture);
            Grav::instance()['log']->warning('GenericProvider: ignored claim "picture", not an http(s) URL: ' . $value);

            return null;
        }

        return $picture;
    }
}
