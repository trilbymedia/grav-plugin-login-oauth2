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
     * OAuth2 logins that get its cookie are in use: an active provider on the site, or in the classic admin with
     * "Enabled in Admin" (Admin2's logins, through the API, get none). The cookie logs users back in without the
     * identity provider. Empty otherwise, which hides it.
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
                if ($admin && !($config->get('plugins.login-oauth2.admin.enabled') && $config->get('plugins.admin.enabled'))) {
                    continue;
                }
                if (static::registered($admin)->getConfiguredProviders()) {
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
     * Why "Enforce OAuth2" doesn't apply on a side, null if it applies: the one rule of the login checks
     * (LoginOauth2Plugin::isOAuth2Enforced()), the settings notice (enforceNotice()) and the CLI. "disabled": the
     * plugin is off; "admin_disabled": "Enabled in Admin" is off; "no_provider": none of the side's providers has its
     * client ID. Whether the setting itself is on, its callers ask first.
     *
     * @param bool $admin
     * @param array $config The plugin's settings (plugins.login-oauth2)
     * @param array $providers The side's providers, name => settings (e.g. getProviders() of registered())
     * @return string|null
     */
    public static function enforceNotApplying(bool $admin, array $config, array $providers): ?string
    {
        if (empty($config['enabled'])) {
            return 'disabled';
        }
        if ($admin && empty($config['admin']['enabled'])) {
            return 'admin_disabled';
        }

        return static::configured($providers) ? null : 'no_provider';
    }

    /**
     * The notice below a side's "Enforce OAuth2" toggle (blueprint: data-content@) while it's on but doesn't apply
     * (enforceNotApplying()), so logins with a password still work there. Empty otherwise, which hides it. As the form,
     * it shows the saved settings.
     *
     * @param mixed $admin The admin's toggle, else the site's
     */
    public static function enforceNotice($admin = false): string
    {
        // Never take the settings page down.
        try {
            $grav = Grav::instance();
            $admin = (bool)$admin;
            $config = (array)($grav['config']->get('plugins.login-oauth2') ?? []);
            $on = $admin ? ($config['admin']['enforce_oauth2'] ?? false) : ($config['enforce_oauth2'] ?? false);
            $why = $on ? static::enforceNotApplying($admin, $config, static::registered($admin)->getProviders()) : null;
            if ($why === null) {
                return '';
            }

            $reason = [
                'disabled' => 'ENFORCE_OAUTH2_REASON_DISABLED',
                'admin_disabled' => 'ENFORCE_OAUTH2_REASON_ADMIN_DISABLED',
                'no_provider' => $admin ? 'ENFORCE_OAUTH2_REASON_NO_ADMIN_PROVIDER' : 'ENFORCE_OAUTH2_REASON_NO_SITE_PROVIDER',
            ][$why];
            $language = $grav['language'];

            return (string)$language->translate([
                'PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_NOT_APPLYING',
                $language->translate('PLUGIN_LOGIN_OAUTH2.' . $reason),
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

    /**
     * The providers of a side's login: a service with this plugin's enabled providers and the ones satellite plugins
     * add (registerProviders()).
     *
     * @return static
     */
    public static function registered(bool $admin): self
    {
        return (new static($admin))->registerProviders();
    }

    /**
     * Adds this plugin's enabled providers, then lets satellite plugins (e.g. login-oauth2-extras) add theirs on
     * onOAuth2ProvidersRegister, which they listen to from their onPluginsInitialized.
     *
     * @return $this
     */
    public function registerProviders(): self
    {
        $this->addEnabledProviders();
        Grav::instance()->fireEvent('onOAuth2ProvidersRegister', new Event(['oauth2' => $this]));

        return $this;
    }

    /**
     * The providers that have their client ID, of a name => settings list.
     */
    public static function configured(array $providers): array
    {
        return array_filter($providers, [static::class, 'isConfigured']);
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
        return static::configured($this->providers);
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