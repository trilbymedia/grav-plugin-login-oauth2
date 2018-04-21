<?php
namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Utils;
use League\OAuth2\Client\Provider\AbstractProvider;

abstract class BaseProvider implements ProviderInterface
{
    protected $name;
    protected $classname;
    protected $provider;
    protected $state;

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

    public function getAccessToken($code, $options)
    {
        return $this->provider->getAccessToken($code, $options);
    }

    public function getResourceOwner($token)
    {
        return $this->provider->getResourceOwner($token);
    }
}