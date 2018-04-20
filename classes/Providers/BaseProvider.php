<?php
namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Utils;

class BaseProvider
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
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return mixed
     */
    public function getProvider()
    {
        return $this->provider;
    }


}