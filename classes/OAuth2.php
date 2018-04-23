<?php
namespace Grav\Plugin\Login\OAuth2;

use Grav\Common\Grav;

class OAuth2
{
    protected $config;
    protected $providers = [];

    public function __construct()
    {
        $this->config = Grav::instance()['config']->get('plugins.login-oauth2');
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function addEnabledProviders()
    {
        $providers = isset($this->config['providers']) ? (array)$this->config['providers'] : [];

        foreach ($providers as $provider => $options) {
            if ($options['enabled']) {
                $this->addProvider($provider);
            }
        }
    }

    public function addProvider($provider = null)
    {
        $this->providers[] = $provider;
    }

    public function getProviders()
    {
        return $this->providers;
    }

    public function isValidProvider($provider)
    {
        if (in_array($provider, $this->providers,true)) {
            return true;
        }
        return false;
    }
}