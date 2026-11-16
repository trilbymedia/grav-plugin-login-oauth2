<?php

namespace Grav\Plugin\Login\OAuth2;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\PermissionResolver as ApiPermissionResolver;
use Grav\Plugin\Login\OAuth2\Providers\BaseProvider;
use ReflectionClass;
use ReflectionException;
use RocketTheme\Toolbox\Event\Event;

class OAuth2
{
    /** Settings that must be off for OAuth2 enforcement. */
    public const REQUIREMENT_KEYS = [
        'remember_me' => 'plugins.login.rememberme.enabled',
        'session_passthrough' => 'plugins.api.auth.session_enabled',
    ];

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
     * Whether this login saves the user as a Grav account ("Save Grav user" of its side).
     */
    public function saveGravUser(): bool
    {
        return $this->userSetting('save_grav_user');
    }

    /**
     * Whether this login needs an existing Grav account ("Require Existing Grav user" of its side).
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

    /** Callback URIs for the classic admin or each Admin2 provider. */
    public static function adminCallbackUris(): string
    {
        // Never take the settings page down.
        try {
            $uri = static function (string $uri): string {
                return '<code class="select-all break-all">' . htmlspecialchars($uri, ENT_QUOTES) . '</code>';
            };
            if (static::classicAdmin()) {
                return $uri(BaseProvider::getCallbackUri('admin'));
            }

            $providers = static::ownProviders();
            try {
                $providers += static::registeredFor(true) + static::registeredFor(false);
            } catch (\Throwable $e) {
                // Built-in callback URIs remain available when a satellite plugin fails.
            }
            $rows = '';
            foreach (array_keys($providers) as $provider) {
                $rows .= sprintf(
                    '<tr><td class="border-0 py-0.5 pe-4 align-top font-medium whitespace-nowrap">%s</td><td class="border-0 py-0.5 align-top">%s</td></tr>',
                    htmlspecialchars(static::providerLabel((string)$provider), ENT_QUOTES),
                    $uri(BaseProvider::getAdminCallbackUri((string)$provider))
                );
            }

            return '<table class="border-0 text-sm"><tbody>' . $rows . '</tbody></table>';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Configured provider names with a class supplied by this plugin. */
    protected static function ownProviders(): array
    {
        $config = (array)(Grav::instance()['config']->get('plugins.login-oauth2') ?? []);

        return array_filter((array)($config['providers'] ?? []) + (array)($config['admin']['providers'] ?? []), static function ($name) {
            return class_exists(__NAMESPACE__ . '\\Providers\\' . ucfirst((string)$name) . 'Provider');
        }, ARRAY_FILTER_USE_KEY);
    }

    /** Read the display name without constructing a provider with incomplete credentials. */
    public static function providerLabel(string $name): string
    {
        $classname = __NAMESPACE__ . '\\Providers\\' . ucfirst($name) . 'Provider';

        if (class_exists($classname)) {
            try {
                $defaults = (new ReflectionClass($classname))->getDefaultProperties();
                if (!empty($defaults['name'])) {
                    return (string) $defaults['name'];
                }
            } catch (ReflectionException $e) {
                // Fall through to the humanized id.
            }
        }

        return ucfirst($name);
    }

    /** The API route, including its version prefix. */
    public static function apiRoute(): string
    {
        $config = Grav::instance()['config'];

        return '/' . trim((string)$config->get('plugins.api.route', '/api'), '/') . '/' . trim((string)$config->get('plugins.api.version_prefix', 'v1'), '/');
    }

    /** Enforcement requires an enabled plugin, an enabled admin when applicable, and a configured provider. */
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

    /** Remember me must be off; admin enforcement also requires API session authentication off. */
    public static function enforceRequirements(bool $admin, ?callable $get = null): array
    {
        // By default the configuration of this request; the commands read each environment's files instead.
        $get = $get ?? [Grav::instance()['config'], 'get'];
        $requirements = ['remember_me' => !$get(static::REQUIREMENT_KEYS['remember_me'])];
        if ($admin) {
            // Not set (null) is on: the API's own default (AuthMiddleware), also without the key in its api.yaml.
            $requirements['session_passthrough'] = !$get('plugins.api.enabled') || !($get(static::REQUIREMENT_KEYS['session_passthrough']) ?? true);
        }

        return $requirements;
    }

    /** Names of requirements that are not met. */
    public static function enforceUnmet(bool $admin): array
    {
        return array_keys(array_filter(static::enforceRequirements($admin), static function (bool $met) {
            return !$met;
        }));
    }

    /** Check a logged-in copy, optionally enabling disabled accounts for sign-out. */
    public static function hasAdminAccess(UserInterface $user, bool $anyState = false): bool
    {
        $copy = clone $user;
        $copy->set('authenticated', true);
        $copy->set('authorized', true);
        if ($anyState) {
            $copy->set('state', 'enabled');
        }
        foreach (['admin.login', 'admin.super', 'api.access', 'api.super'] as $action) {
            if ($copy->authorize($action)) {
                return true;
            }
        }

        // The API also accepts inherited blanket permissions such as api: true.
        if (!class_exists(ApiPermissionResolver::class) || $copy->get('state', 'enabled') !== 'enabled') {
            return false;
        }

        try {
            return (new ApiPermissionResolver())->resolve($copy, 'api.access') === true;
        } catch (\Throwable $e) {
            // An API version that resolves it another way: what Grav answered above stands.
            Grav::instance()['log']->warning('Login OAuth2: the API plugin could not be asked for the access of an account: ' . $e->getMessage());

            return false;
        }
    }

    /** Translated requirement names joined as a list. */
    public static function requirementNames(array $requirements): string
    {
        $language = Grav::instance()['language'];

        return static::andList(array_map(static function (string $requirement) use ($language) {
            return (string)$language->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_REQUIREMENT_' . strtoupper($requirement));
        }, $requirements));
    }

    /** Join words with commas and a translated conjunction. */
    public static function andList(array $words): string
    {
        $last = array_pop($words);

        return $words ? implode(', ', $words) . ' ' . Grav::instance()['language']->translate('PLUGIN_LOGIN_OAUTH2.AND') . ' ' . $last : (string)$last;
    }

    /** Requirements and applicability below each enforcement toggle. */
    public static function enforceConditionsNotice($admin = false): string
    {
        // Never take the settings page down.
        try {
            $grav = Grav::instance();
            $admin = (bool)$admin;
            $language = $grav['language'];
            $config = (array)$grav['config']->get('plugins.login-oauth2');
            $requirements = static::enforceRequirements($admin);
            $unmet = in_array(false, $requirements, true);
            $on = !empty($admin ? ($config['admin']['enforce_oauth2'] ?? false) : ($config['enforce_oauth2'] ?? false));
            try {
                $notApplying = static::enforceNotApplying($admin, $config, static::registeredFor($admin));
            } catch (\Throwable $e) {
                // A failing plugin of other providers: unknown, and Admin2 keeps enforcing meanwhile.
                $notApplying = null;
            }

            $url = static::adminUrl();
            $links = [
                'remember_me' => $url . '/plugins/login#options',
                'session_passthrough' => $url . '/plugins/api#tab_authentication',
            ];
            $session = htmlspecialchars($url . '/config/system#session', ENT_QUOTES);

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
                    $language->translate([$key . '_WHY', $session])
                );
            }

            if (!$on) {
                $style = 'border-border bg-muted/50';
            } elseif ($unmet) {
                $style = 'border-red-500/50 bg-red-500/10 text-red-700 dark:text-red-300';
            } elseif ($notApplying) {
                $style = 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300';
            } else {
                $style = 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
            }
            $html = sprintf(
                '<div class="rounded-lg border %s px-4 py-3 text-sm"><strong>%s</strong><ul class="mt-2 space-y-1">%s</ul>',
                $style,
                $language->translate('PLUGIN_LOGIN_OAUTH2.' . ($unmet ? ($on ? 'ENFORCE_REQUIREMENTS_UNMET_ON' : 'ENFORCE_REQUIREMENTS_UNMET') : 'ENFORCE_REQUIREMENTS_MET')),
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

    /** Red settings warning when enforcement has unmet requirements. */
    public static function enforceWarning(): string
    {
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

    /** One translated warning per enforced side with unmet requirements. */
    public static function enforceWarningLines(): array
    {
        $grav = Grav::instance();
        $language = $grav['language'];
        $lines = [];
        foreach (['site' => false, 'admin' => true] as $side => $admin) {
            $on = $grav['config']->get('plugins.login-oauth2.' . ($admin ? 'admin.' : '') . 'enforce_oauth2');
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

    /** The same warning for Admin2's dashboard. */
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

    /** Translated settings explanation or short CLI wording. */
    public static function notApplyingReason(bool $admin, string $why, bool $short = false): string
    {
        // Reason => its language key (settings form), its words for the commands.
        [$key, $words] = [
            'disabled' => ['ENFORCE_OAUTH2_REASON_DISABLED', 'the plugin is disabled'],
            'admin_disabled' => ['ENFORCE_OAUTH2_REASON_ADMIN_DISABLED', "'Enabled in Admin' is off"],
            'no_provider' => [$admin ? 'ENFORCE_OAUTH2_REASON_NO_ADMIN_PROVIDER' : 'ENFORCE_OAUTH2_REASON_NO_SITE_PROVIDER', 'no active provider'],
        ][$why];

        return $short ? $words : (string)Grav::instance()['language']->translate('PLUGIN_LOGIN_OAUTH2.' . $key);
    }

    /** Admin2 uses an AdminProxy instead of the classic Admin service. */
    protected static function classicAdmin(): bool
    {
        $grav = Grav::instance();

        return isset($grav['admin']) && $grav['admin'] instanceof \Grav\Plugin\Admin\Admin;
    }

    /** Link to the admin displaying the settings. */
    protected static function adminUrl(): string
    {
        $grav = Grav::instance();
        $config = $grav['config'];
        // Admin2's requests through the API set $grav['admin'] too (Grav\Plugin\Api\AdminProxy).
        $route = static::classicAdmin() ? $config->get('plugins.admin.route') : ($config->get('plugins.admin2.route') ?: $config->get('plugins.admin.route'));

        return rtrim($grav['uri']->rootUrl(false), '/') . '/' . trim((string)($route ?: 'admin'), '/');
    }

    /** Facebook uses app_id; other providers use client_id. */
    public static function isConfigured($options): bool
    {
        return !empty($options['client_id']) || !empty($options['app_id']);
    }

    /** Register a side's providers while preserving the request's OAuth2 service. */
    public static function registered(bool $admin): self
    {
        return (new self($admin))->registerProviders();
    }

    /** Site providers come from the request's service; admin providers use the registration event. */
    public static function registeredFor(bool $admin): array
    {
        $grav = Grav::instance();
        if (!$admin && isset($grav['oauth2']) && !$grav['oauth2']->isAdmin()) {
            $config = (array)($grav['config']->get('plugins.login-oauth2') ?? []);

            return static::enabledProviders(false, $config) + array_diff_key($grav['oauth2']->getProviders(), static::ownProviders());
        }

        return static::registered($admin)->getProviders();
    }

    /** Temporarily install this service for handlers and provider constructors that read it from Grav. */
    public function registerProviders(): self
    {
        $this->addEnabledProviders();

        $grav = Grav::instance();
        // As it is by now, with the providers satellite plugins added to it in their own onPluginsInitialized.
        $own = isset($grav['oauth2']) ? $grav['oauth2'] : null;
        unset($grav['oauth2']);
        $grav['oauth2'] = $this;
        try {
            $grav->fireEvent('onOAuth2ProvidersRegister', new Event(['oauth2' => $this]));
        } finally {
            unset($grav['oauth2']);
            if ($own !== null) {
                $grav['oauth2'] = $own;
            }
        }

        return $this;
    }

    /** Providers with their client ID. */
    public static function configured(array $providers): array
    {
        return array_filter($providers, [static::class, 'isConfigured']);
    }

    /** Add valid providers; log unknown enabled names once a day. */
    public function addEnabledProviders(): void
    {
        foreach (static::enabledProviders($this->admin, $this->config, $unknown) as $provider => $options) {
            $this->addProvider((string)$provider, $options);
        }
        if ($unknown) {
            static::warnOnce(sprintf(
                'Login OAuth2: the %s settings enable a provider this plugin does not have, which is ignored: %s',
                $this->admin ? 'admin' : 'site',
                implode(', ', $unknown)
            ));
        }
    }

    /** A cache file limits repeated warnings to once a day, even when Grav's cache is off. */
    protected static function warnOnce(string $message): void
    {
        $grav = Grav::instance();
        $file = $grav['locator']->findResource('cache://', true, true) . '/login-oauth2/warned-' . md5($message);
        if (is_file($file) && filemtime($file) > time() - 86400) {
            return;
        }

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        @touch($file);
        $grav['log']->warning($message);
    }

    /** Validate configured provider classes and collect unknown enabled names. */
    public static function enabledProviders(bool $admin, array $config, ?array &$unknown = null): array
    {
        $unknown = [];
        $providers = [];
        foreach ((array)($admin ? ($config['admin']['providers'] ?? []) : ($config['providers'] ?? [])) as $name => $options) {
            $options = (array)$options;
            try {
                if (ProviderFactory::checkIfActive((string)$name, $options)) {
                    $providers[$name] = $options;
                }
            } catch (\RuntimeException $e) {
                if (!empty($options['enabled'])) {
                    $unknown[] = (string)$name;
                }
            }
        }

        return $providers;
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