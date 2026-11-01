<?php

namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Plugin\Login\OAuth2\OAuth2;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

abstract class BaseProvider implements ProviderInterface
{
    /** @var string */
    const CALLBACK_URI = '/task:callback.oauth2';

    /** @var string */
    protected $name;
    /** @var string */
    protected $classname;
    /** @var AbstractProvider */
    protected $provider;
    /** @var string */
    protected $state;
    /** @var AccessTokenInterface */
    protected $token;
    /** @var Data */
    protected $config;

    /**
     * @param array $options
     * @return bool
     */
    public static function checkIfActive(array $options): bool
    {
        return (bool)($options['enabled'] ?? false);
    }

    /**
     * BaseProvider constructor.
     */
    public function __construct()
    {
        $admin = Grav::instance()['oauth2']->isAdmin();
        $this->config = new Data(Grav::instance()['config']->get('plugins.login-oauth2' . ($admin ? '.admin' : '')));
        $this->state = 'LOGIN_OAUTH2_' . Utils::generateRandomString(15);
    }

    /**
     * Initialize Provider
     *
     * @param array $options
     */
    public function initProvider(array $options): void
    {
        // Honor a caller-supplied redirectUri (the headless/admin-next SSO bridge
        // points the provider at an API callback endpoint instead of the classic
        // `/task:callback.oauth2` Twig route). Falls back to the computed callback
        // URI for the classic flow, which passes no redirectUri.
        $options['redirectUri'] = $options['redirectUri'] ?? static::getCallbackUri();
        $this->provider = new $this->classname($options);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @param string $state
     * @return $this
     */
    public function setState(string $state)
    {
        $this->state = $state;

        return $this;
    }

    /**
     * @return AbstractProvider
     */
    public function getProvider(): AbstractProvider
    {
        return $this->provider;
    }

    /**
     * @param string $admin
     * @return string
     */
    public static function getCallbackUri(string $admin = 'auto'): string
    {
        if ($admin === 'auto') {
            $admin = Grav::instance()['oauth2']->isAdmin();
        }

        $callback_uri = ($admin ? Grav::instance()['config']->get('plugins.admin.route', '') : '') . static::CALLBACK_URI;

        $base_url = rtrim(Grav::instance()['uri']->rootUrl(true), '/');

        return $base_url . '/' . ltrim($callback_uri, '/');
    }

    /**
     * Callback URI of a provider's admin logins: Admin2 logins return to the API's SSO callback of the provider, the
     * same one the API sends the provider (SsoController::callbackUrl()), classic admin logins to the admin route.
     *
     * @param string $provider Default: the default provider of the callback URIs in the blueprint
     * @return string
     */
    public static function getAdminCallbackUri(string $provider = ''): string
    {
        if ($provider === '') {
            $provider = OAuth2::callbackProvider();
        }

        $config = Grav::instance()['config'];
        if ($config->get('plugins.admin.enabled') && !$config->get('plugins.admin2.enabled')) {
            return static::getCallbackUri(true);
        }

        $api = '/' . trim((string)$config->get('plugins.api.route', '/api'), '/') . '/' . trim((string)$config->get('plugins.api.version_prefix', 'v1'), '/');
        $base_url = rtrim(Grav::instance()['uri']->rootUrl(true), '/');

        return $base_url . $api . '/auth/sso/' . rawurlencode($provider) . '/callback';
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
        $this->token = $this->provider->getAccessToken($grant, $options);

        return $this->token;
    }

    /**
     * Requests and returns the resource owner of given access token.
     *
     * @param  AccessToken $token
     * @return ResourceOwnerInterface
     */
    public function getResourceOwner(AccessToken $token): ResourceOwnerInterface
    {
        return $this->provider->getResourceOwner($token);
    }
}
