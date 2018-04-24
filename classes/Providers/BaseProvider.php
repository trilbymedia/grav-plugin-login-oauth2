<?php
namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Grav;
use Grav\Common\Utils;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;

abstract class BaseProvider implements ProviderInterface
{
    /** @var string */
    protected $name;
    /** @var string */
    protected $classname;
    /** @var AbstractProvider */
    protected $provider;
    /** @var string */
    protected $state;
    /** @var stdClass */
    protected $token;

    /**
     * BaseProvider constructor.
     * @param array $options
     */
    public function __construct(array $options)
    {
        $this->provider = new $this->classname($options);
        $this->state = 'LOGIN_OAUTH2_' . Utils::generateRandomString(15);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * @param string $state
     * @return $this
     */
    public function setState($state)
    {
        $this->state = $state;

        return $this;
    }

    /**
     * @return AbstractProvider
     */
    public function getProvider()
    {
        return $this->provider;
    }

    public function getCallbackUri()
    {
        $callback_uri = Grav::instance()['config']->get('plugins.login-oauth2.callback_uri');
        $base_url = Grav::instance()['base_url_absolute'];

        return $base_url . '/' . ltrim($callback_uri, '/');
    }

    /**
     * Requests an access token using a specified grant and option set.
     *
     * @param  mixed $grant
     * @param  array $options
     * @return AccessToken
     */
    public function getAccessToken($grant, array $options = [])
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
    public function getResourceOwner(AccessToken $token)
    {
        return $this->provider->getResourceOwner($token);
    }
}