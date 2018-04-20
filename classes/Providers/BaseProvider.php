<?php
namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Grav;
use League\OAuth2\Client\Provider\AbstractProvider;

abstract class BaseProvider implements ProviderInterface
{
    protected $config;
    protected $name;
    protected $provider;
    protected $state;

    /**
     * BaseProvider constructor.
     * @param array $options
     */
    public function __construct(array $options)
    {
        $this->config = Grav::instance()['config'];
        $provider_classname = 'League\\OAuth2\\Client\\Provider\\' . $this->name;
        $this->provider = $provider_classname($options);
        $this->state = 'LOGIN_OAUTH2';
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
}