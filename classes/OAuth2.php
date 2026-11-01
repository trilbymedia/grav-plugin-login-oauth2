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

    /**
     * OAuth2 constructor.
     * @param bool $admin
     */
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

    /**
     * Whether this login saves the user as a Grav account: "Save Grav user (site)" or "Save Grav user (admin)", which
     * follows the site one as long as it isn't set.
     */
    public function saveGravUser(): bool
    {
        $config = Grav::instance()['config'];
        $site = (bool)$config->get('plugins.login-oauth2.save_grav_user');

        return $this->admin ? (bool)($config->get('plugins.login-oauth2.admin.save_grav_user') ?? $site) : $site;
    }

    /**
     * Condition of the admin's group sync notice in the blueprint: the admin "Save Grav user" field, or the site's as
     * long as the admin one isn't set, as the form's conditions only see stored values, not the inherited one.
     */
    public static function adminSaveGravUserCondition(): string
    {
        $set = Grav::instance()['config']->get('plugins.login-oauth2.admin.save_grav_user') !== null;

        return ($set ? 'admin.' : '') . 'save_grav_user != true';
    }

    public function addEnabledProviders(): void
    {
        if ($this->admin) {
            $providers = (array)($this->config['admin']['providers'] ?? []);
        } else {
            $providers = (array)($this->config['providers'] ?? []);
        }

        foreach ($providers as $provider => $options) {
            if (ProviderFactory::checkIfActive($provider, $options)) {
                $this->addProvider($provider, $options);
            }
        }
    }

    /**
     * @param string $provider
     * @param array|null $options
     */
    public function addProvider(string $provider, ?array $options = null): void
    {
        $this->providers[$provider] = $options;
    }

    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * @param string $provider
     * @return mixed|null
     */
    public function getProviderOptions(string $provider)
    {
        return $this->providers[$provider] ?? null;
    }

    /**
     * @param string $provider
     * @return bool
     */
    public function isValidProvider(?string $provider): bool
    {
        if (is_null($provider)) {
            return false;
        }
        return array_key_exists($provider, $this->providers);
    }
}