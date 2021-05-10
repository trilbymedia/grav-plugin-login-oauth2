<?php

namespace Grav\Plugin\Login\OAuth2;

use Grav\Common\Grav;

class OAuth2
{
    /** @var array */
    protected $config;
    /** @var array */
    protected $providers = [];
    /** @var bool */
    protected $admin;

    public function __construct($admin = false)
    {
        $this->config = (array)(Grav::instance()['config']->get('plugins.login-oauth2') ?? []);
        $this->admin = (bool)$admin;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function addEnabledProviders(): void
    {
        if ($this->admin) {
            $providers = (array)($this->config['admin']['providers'] ?? []);
        } else {
            $providers = (array)($this->config['providers'] ?? []);
        }

        foreach ($providers as $provider => $options) {
            if (!empty($options['enabled'])) {
                $this->addProvider($provider, $options);
            }
        }
    }

    public function addProvider($provider = null, $options = null): void
    {
        $this->providers[$provider] = $options;
    }

    public function getProviders(): array
    {
        return $this->providers;
    }

    public function getProviderOptions($provider)
    {
        return $this->providers[$provider] ?? null;
    }

    public function isValidProvider($provider): bool
    {
        return array_key_exists($provider, $this->providers);
    }
}