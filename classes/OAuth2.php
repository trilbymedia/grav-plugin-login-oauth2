<?php

namespace Grav\Plugin\Login\OAuth2;

use Grav\Common\Grav;
use RocketTheme\Toolbox\Event\Event;

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
     * Whether this login saves the user as a Grav account ("Save Grav user (site)" / "(admin)").
     */
    public function saveGravUser(): bool
    {
        return $this->userSetting('save_grav_user');
    }

    /**
     * Whether this login needs an existing Grav account ("Require Existing Grav user (site)" / "(admin)").
     */
    public function requireGravUser(): bool
    {
        return $this->userSetting('require_grav_user');
    }

    /**
     * A user account setting of this login: the site one, or for admin logins the admin one, which follows the site
     * one as long as it isn't set.
     */
    protected function userSetting(string $key): bool
    {
        $site = (bool)($this->config[$key] ?? false);

        return $this->admin ? (bool)($this->config['admin'][$key] ?? $site) : $site;
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

    /**
     * Default provider of the callback URIs in the blueprint: the first admin provider that is enabled and has a
     * client ID, else the first such site provider, else the Generic provider.
     */
    public static function callbackProvider(): string
    {
        $config = (array)(Grav::instance()['config']->get('plugins.login-oauth2') ?? []);

        foreach ([$config['admin']['providers'] ?? [], $config['providers'] ?? []] as $providers) {
            foreach ((array)$providers as $provider => $options) {
                if (!empty($options['enabled']) && static::isConfigured($options)) {
                    return (string)$provider;
                }
            }
        }

        return 'generic';
    }

    /**
     * The notice above the plugin's tabs (blueprint: data-content@) while the Login plugin's "Remember Me" is on and
     * OAuth2 logins are in use: an active provider on the site, or in the admin with "Enabled in Admin". OAuth2 logins
     * get its cookie too, which logs users back in without the identity provider. Empty otherwise, which hides it.
     */
    public static function rememberMeNotice(): string
    {
        // Never take the settings page down.
        try {
            $grav = Grav::instance();
            $config = $grav['config'];
            if (!$config->get('plugins.login-oauth2.enabled') || !$config->get('plugins.login.rememberme.enabled')) {
                return '';
            }

            $inUse = false;
            foreach ([false, true] as $admin) {
                if ($admin && !$config->get('plugins.login-oauth2.admin.enabled')) {
                    continue;
                }
                $oauth2 = new static($admin);
                $oauth2->addEnabledProviders();
                // Satellite plugins (e.g. login-oauth2-extras) add theirs on this event.
                $grav->fireEvent('onOAuth2ProvidersRegister', new Event(['oauth2' => $oauth2]));
                if ($oauth2->getConfiguredProviders()) {
                    $inUse = true;
                    break;
                }
            }
            if (!$inUse) {
                return '';
            }

            $admin = static::adminUrl();

            return (string)$grav['language']->translate([
                'PLUGIN_LOGIN_OAUTH2.REMEMBER_ME_NOTICE',
                static::duration((int)$config->get('plugins.login.rememberme.timeout', 604800)),
                static::duration((int)$config->get('system.session.timeout', 1800)),
                htmlspecialchars($admin . '/plugins/login#options', ENT_QUOTES),
                htmlspecialchars($admin . '/config/system#session', ENT_QUOTES),
            ]);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The URL path of the admin that shows the settings form: the classic admin (which renders the form itself), else
     * Admin2 (which loads it through the API). Both serve the pages below it at the same paths, e.g. /plugins/login;
     * Admin2 opens the tab named in the URL's hash (e.g. #session).
     */
    protected static function adminUrl(): string
    {
        $grav = Grav::instance();
        $config = $grav['config'];
        $route = isset($grav['admin']) ? $config->get('plugins.admin.route') : ($config->get('plugins.admin2.route') ?: $config->get('plugins.admin.route'));

        return rtrim($grav['uri']->rootUrl(false), '/') . '/' . trim((string)($route ?: 'admin'), '/');
    }

    /**
     * A duration in its largest whole unit, e.g. "1 week" or "30 minutes" (Grav's NICETIME units).
     */
    protected static function duration(int $seconds): string
    {
        $language = Grav::instance()['language'];
        foreach (['WEEK' => 604800, 'DAY' => 86400, 'HOUR' => 3600, 'MINUTE' => 60, 'SECOND' => 1] as $unit => $length) {
            if ($seconds >= $length && $seconds % $length === 0) {
                $count = intdiv($seconds, $length);

                return $count . ' ' . $language->translate('GRAV.NICETIME.' . $unit . ($count === 1 ? '' : '_PLURAL'));
            }
        }

        return $seconds . ' ' . $language->translate('GRAV.NICETIME.SECOND_PLURAL');
    }

    /**
     * Whether a provider's settings have its client ID. Facebook names it "app_id".
     *
     * @param mixed $options
     */
    public static function isConfigured($options): bool
    {
        return !empty($options['client_id']) || !empty($options['app_id']);
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
     * The providers a login can use: the added ones that have their client ID. This plugin adds only those, satellite
     * plugins (e.g. login-oauth2-extras) add theirs as soon as they're enabled.
     */
    public function getConfiguredProviders(): array
    {
        return array_filter($this->providers, [static::class, 'isConfigured']);
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