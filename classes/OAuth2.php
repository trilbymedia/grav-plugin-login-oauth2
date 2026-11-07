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
     * The settings of other plugins that "Enforce OAuth2" on a side requires off, as they let users in without OAuth2:
     * "remember_me", the Login plugin's "Remember me" (its cookie logs users back in without the identity provider),
     * and for the admin "session_passthrough", the API plugin's Session Authentication (Admin2 then accepts a site
     * session, also one from a login that "Enforce OAuth2" doesn't cover). Name => whether it's off, in the order to
     * show them. The plugin never changes them itself.
     *
     * @param bool $admin
     * @return array<string, bool>
     */
    public static function enforceRequirements(bool $admin): array
    {
        $config = Grav::instance()['config'];
        $requirements = ['remember_me' => !$config->get('plugins.login.rememberme.enabled')];
        if ($admin) {
            $requirements['session_passthrough'] = !$config->get('plugins.api.enabled') || !$config->get('plugins.api.auth.session_enabled');
        }

        return $requirements;
    }

    /**
     * The requirements of a side that aren't met (enforceRequirements()).
     *
     * @param bool $admin
     * @return string[]
     */
    public static function enforceUnmet(bool $admin): array
    {
        return array_keys(array_filter(static::enforceRequirements($admin), static function (bool $met) {
            return !$met;
        }));
    }

    /**
     * The names of requirements (enforceRequirements()) in words, e.g. "Login → 'Remember me' and API → Session
     * Authentication".
     *
     * @param string[] $requirements
     */
    public static function requirementNames(array $requirements): string
    {
        $language = Grav::instance()['language'];
        $names = array_map(static function (string $requirement) use ($language) {
            return (string)$language->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_REQUIREMENT_' . strtoupper($requirement));
        }, $requirements);
        $last = array_pop($names);

        return $names ? implode(', ', $names) . ' ' . $language->translate('PLUGIN_LOGIN_OAUTH2.AND') . ' ' . $last : (string)$last;
    }

    /**
     * The block below a side's "Enforce OAuth2" toggle (blueprint: a conditional on the toggle, so Admin2 shows it while
     * the toggle is on in the form): each requirement (enforceRequirements()) with its state, why it's needed and where
     * to change it; and why it doesn't apply yet, if so (enforceNotApplying()). Green when it all holds, red while a
     * requirement isn't met (turning it on is then refused, see LoginOauth2Plugin::onAdminSave()), amber while it only
     * doesn't apply. The states are the saved settings.
     *
     * @param mixed $admin The admin's toggle, else the site's
     */
    public static function enforceConditionsNotice($admin = false): string
    {
        // Never take the settings page down.
        try {
            $grav = Grav::instance();
            $admin = (bool)$admin;
            $language = $grav['language'];
            $config = (array)($grav['config']->get('plugins.login-oauth2') ?? []);
            $requirements = static::enforceRequirements($admin);
            $unmet = in_array(false, $requirements, true);
            $notApplying = static::enforceNotApplying($admin, $config, static::registered($admin)->getProviders());

            $url = static::adminUrl();
            $links = [
                'remember_me' => $url . '/plugins/login#options',
                'session_passthrough' => $url . '/plugins/api#tab_authentication',
            ];
            // Arguments of each requirement's explanation (ENFORCE_REQUIREMENT_<NAME>_WHY).
            $why = [
                'remember_me' => [
                    static::duration((int)$grav['config']->get('plugins.login.rememberme.timeout', 604800)),
                    static::duration((int)$grav['config']->get('system.session.timeout', 1800)),
                    htmlspecialchars($url . '/config/system#session', ENT_QUOTES),
                ],
                'session_passthrough' => [],
            ];

            $items = '';
            foreach ($requirements as $requirement => $met) {
                $key = 'PLUGIN_LOGIN_OAUTH2.ENFORCE_REQUIREMENT_' . strtoupper($requirement);
                $setting = sprintf(
                    '<a href="%s" class="font-medium underline">%s</a>',
                    htmlspecialchars($links[$requirement], ENT_QUOTES),
                    htmlspecialchars((string)$language->translate($key), ENT_QUOTES)
                );
                $items .= sprintf(
                    '<li class="flex gap-2" data-requirement="%s" data-met="%d"><span class="shrink-0 font-medium %s">%s</span><span>%s %s</span></li>',
                    $requirement,
                    $met ? 1 : 0,
                    $met ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400',
                    $met ? '✓' : '✗',
                    $language->translate(['PLUGIN_LOGIN_OAUTH2.' . ($met ? 'ENFORCE_REQUIREMENT_IS_OFF' : 'ENFORCE_REQUIREMENT_IS_ON'), $setting]),
                    $language->translate(array_merge([$key . '_WHY'], $why[$requirement]))
                );
            }

            $style = $unmet ? 'border-red-500/50 bg-red-500/10 text-red-700 dark:text-red-300'
                : ($notApplying ? 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300'
                    : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300');
            $html = sprintf(
                '<div class="rounded-lg border %s px-4 py-3 text-sm"><strong>%s</strong><ul class="mt-2 space-y-1">%s</ul>',
                $style,
                $language->translate('PLUGIN_LOGIN_OAUTH2.' . ($unmet ? 'ENFORCE_REQUIREMENTS_UNMET' : 'ENFORCE_REQUIREMENTS_MET')),
                $items
            );
            if ($notApplying) {
                $html .= '<p class="mt-2">' . $language->translate(['PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_NOT_APPLYING_YET', static::notApplyingReason($admin, $notApplying)]) . '</p>';
            }

            return $html . '</div>';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The red warning at the top of the settings (blueprint: data-content@) while "Enforce OAuth2" is on for a side
     * whose requirements aren't all met (enforceRequirements()), e.g. turned on before they existed, by hand, with the
     * CLI's --force, or one turned on again later. Empty otherwise, which hides it.
     */
    public static function enforceWarning(): string
    {
        // Never take the settings page down.
        try {
            $lines = static::enforceWarningLines();
            if (!$lines) {
                return '';
            }

            $language = Grav::instance()['language'];

            return sprintf(
                '<div class="rounded-lg border border-red-500/50 bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-300"><strong>%s</strong><ul class="mt-1 space-y-1">%s</ul><p class="mt-2">%s</p></div>',
                $language->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_WARNING'),
                implode('', array_map(static function (string $line) {
                    return '<li>' . htmlspecialchars($line, ENT_QUOTES) . '</li>';
                }, $lines)),
                $language->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_WARNING_FIX')
            );
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * For each side with "Enforce OAuth2" on and requirements not met: "In the admin, API → Session Authentication is
     * still on."
     *
     * @return string[]
     */
    public static function enforceWarningLines(): array
    {
        $grav = Grav::instance();
        $config = $grav['config'];
        $language = $grav['language'];
        $lines = [];
        foreach (['site' => false, 'admin' => true] as $side => $admin) {
            $on = $config->get('plugins.login-oauth2.' . ($admin ? 'admin.' : '') . 'enforce_oauth2');
            $unmet = $on ? static::enforceUnmet($admin) : [];
            if ($unmet) {
                $lines[] = $language->translate([
                    'PLUGIN_LOGIN_OAUTH2.ENFORCE_WARNING_' . strtoupper($side) . (count($unmet) > 1 ? '_PLURAL' : ''),
                    static::requirementNames($unmet),
                ]);
            }
        }

        return $lines;
    }

    /**
     * The notice of Admin2's dashboard (LoginOauth2Plugin::onApiDashboardNotifications()) while the red warning shows
     * (enforceWarning()), in Markdown with a link to the settings. Null otherwise.
     */
    public static function enforceDashboardNotice(): ?string
    {
        $lines = static::enforceWarningLines();
        if (!$lines) {
            return null;
        }

        return (string)Grav::instance()['language']->translate([
            'PLUGIN_LOGIN_OAUTH2.ENFORCE_DASHBOARD_NOTICE',
            implode(' ', $lines),
            static::adminUrl() . '/plugins/login-oauth2',
        ]);
    }

    /**
     * Why "Enforce OAuth2" doesn't apply on a side (enforceNotApplying()), in words.
     */
    protected static function notApplyingReason(bool $admin, string $why): string
    {
        $reason = [
            'disabled' => 'ENFORCE_OAUTH2_REASON_DISABLED',
            'admin_disabled' => 'ENFORCE_OAUTH2_REASON_ADMIN_DISABLED',
            'no_provider' => $admin ? 'ENFORCE_OAUTH2_REASON_NO_ADMIN_PROVIDER' : 'ENFORCE_OAUTH2_REASON_NO_SITE_PROVIDER',
        ][$why];

        return (string)Grav::instance()['language']->translate('PLUGIN_LOGIN_OAUTH2.' . $reason);
    }

    /**
     * Whether the settings form is shown by the classic admin, which renders it itself; Admin2 loads it through the API
     * (whose stand-in for the admin is Grav\Plugin\Api\AdminProxy).
     */
    protected static function classicAdmin(): bool
    {
        $grav = Grav::instance();

        return isset($grav['admin']) && $grav['admin'] instanceof \Grav\Plugin\Admin\Admin;
    }

    /**
     * The notice below a side's "Enforce OAuth2" toggle in the classic admin (blueprint: data-content@) while it's on
     * but doesn't apply (enforceNotApplying()), so logins with a password still work there. Empty otherwise, which
     * hides it. The classic admin evaluates a conditional field on the server, without the form's values, so it doesn't
     * show the block of enforceConditionsNotice(), which says the same in Admin2. As the form, it shows the saved
     * settings.
     *
     * @param mixed $admin The admin's toggle, else the site's
     */
    public static function enforceNotice($admin = false): string
    {
        // Never take the settings page down.
        try {
            if (!static::classicAdmin()) {
                return '';
            }
            $grav = Grav::instance();
            $admin = (bool)$admin;
            $config = (array)($grav['config']->get('plugins.login-oauth2') ?? []);
            $on = $admin ? ($config['admin']['enforce_oauth2'] ?? false) : ($config['enforce_oauth2'] ?? false);
            $why = $on ? static::enforceNotApplying($admin, $config, static::registered($admin)->getProviders()) : null;
            if ($why === null) {
                return '';
            }

            return (string)$grav['language']->translate([
                'PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_NOT_APPLYING',
                static::notApplyingReason($admin, $why),
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
        // Admin2's requests through the API set $grav['admin'] too (Grav\Plugin\Api\AdminProxy).
        $route = static::classicAdmin() ? $config->get('plugins.admin.route') : ($config->get('plugins.admin2.route') ?: $config->get('plugins.admin.route'));

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