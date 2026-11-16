<?php

namespace Grav\Plugin\Login\OAuth2;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Common\Utils;
use Grav\Plugin\Api\PermissionResolver as ApiPermissionResolver;
use Grav\Plugin\Api\Services\ConfigStore as ApiConfigStore;
use Grav\Plugin\Login\OAuth2\Providers\BaseProvider;
use ReflectionClass;
use ReflectionException;
use RocketTheme\Toolbox\Event\Event;

class OAuth2
{
    /**
     * The setting that turns each requirement of "Enforce OAuth2" (enforceRequirements()) on, which has to be off.
     * Session Authentication only counts while the API plugin is enabled.
     */
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
     * The configuration the settings form shows and saves, as a reader of its settings by their config path (e.g.
     * "plugins.login.rememberme.enabled", or "plugins.login" for all of a plugin's). The classic admin shows and saves
     * this request's configuration. Admin2 shows and saves the environment chosen in its selector (it sends
     * X-Config-Environment with every request, "Default" for the main configuration), which needn't be the one this
     * request runs in: its settings come from the API's ConfigStore, as its config endpoint reads them (API 1.0.50 or
     * newer; this request's configuration without it, or if it fails). The notices of the form and the check of a
     * save (LoginOauth2Plugin::onAdminSave()) read the same one, so they agree.
     *
     * @return callable
     */
    public static function formConfig(): callable
    {
        $grav = Grav::instance();
        $live = static::liveConfig();
        if (static::classicAdmin() || !class_exists(ApiConfigStore::class) || !isset($grav['request'])) {
            return $live;
        }

        try {
            $store = new ApiConfigStore($grav);
            $target = $store->targetFor($grav['request']);
        } catch (\Throwable $e) {
            return $live;
        }

        $plugins = [];

        return static function (string $path) use ($store, $target, $live, &$plugins) {
            [$type, $plugin, $key] = explode('.', $path, 3) + ['', '', ''];
            if ($type !== 'plugins' || $plugin === '') {
                return $live($path);
            }
            if (!isset($plugins[$plugin])) {
                try {
                    $plugins[$plugin] = $store->effective('plugins/' . $plugin, $target);
                } catch (\Throwable $e) {
                    return $live($path);
                }
            }

            return $key === '' ? $plugins[$plugin] : Utils::getDotNotation($plugins[$plugin], $key);
        };
    }

    /**
     * The configuration this request runs with, as a reader of its settings by their config path (formConfig()).
     *
     * @return callable
     */
    public static function liveConfig(): callable
    {
        $config = Grav::instance()['config'];

        return static function (string $path) use ($config) {
            return $config->get($path);
        };
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
     * The "Admin Callback URI" of the settings form (blueprint: data-content@), for the admin that shows it. Admin2
     * logins return to the API plugin, which has one URI per provider (BaseProvider::getAdminCallbackUri()): they're
     * listed for the providers of callbackProviders(), as a table without lines (provider, URI). Classic admin logins
     * return to the admin's route, the same for every provider.
     */
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

            $rows = '';
            foreach (static::callbackProviders() as $provider => $label) {
                $rows .= sprintf(
                    '<tr><td class="border-0 py-0.5 pe-4 align-top font-medium whitespace-nowrap">%s</td><td class="border-0 py-0.5 align-top">%s</td></tr>',
                    htmlspecialchars($label, ENT_QUOTES),
                    $uri(BaseProvider::getAdminCallbackUri((string)$provider))
                );
            }

            return '<table class="border-0 text-sm"><tbody>' . $rows . '</tbody></table>';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The providers the admin callback URIs are listed for (adminCallbackUris()): this plugin's, and the ones
     * satellite plugins (e.g. login-oauth2-extras) add to the admin's or the site's login in this request, i.e. their
     * enabled ones.
     *
     * @return array<string, string> Name => label
     */
    protected static function callbackProviders(): array
    {
        $names = array_keys(static::ownProviders());
        // Never take the settings page down.
        try {
            $names = array_merge($names, array_keys(static::registeredFor(true) + static::registeredFor(false)));
        } catch (\Throwable $e) {
            // E.g. a satellite plugin fails: this plugin's own are still listed.
        }

        $providers = [];
        foreach ($names as $name) {
            $providers[(string)$name] = static::providerLabel((string)$name);
        }

        return $providers;
    }

    /**
     * This plugin's providers: the ones its settings have (site or admin) that have a provider class.
     *
     * @return array<string, mixed> Name => settings
     */
    protected static function ownProviders(): array
    {
        $config = (array)(Grav::instance()['config']->get('plugins.login-oauth2') ?? []);

        return array_filter((array)($config['providers'] ?? []) + (array)($config['admin']['providers'] ?? []), static function ($name) {
            return class_exists(__NAMESPACE__ . '\\Providers\\' . ucfirst((string)$name) . 'Provider');
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Display label for a provider id.
     *
     * Provider classes carry a properly-cased `$name` ('LinkedIn', 'Keycloak'),
     * which reads better on a button than `ucfirst()` on the config key. Read
     * as a class default so no provider gets constructed just to render the
     * login screen — the League provider classes validate their options in the
     * constructor and would throw here on partially-configured credentials.
     */
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

    /**
     * The URL path of the API plugin's routes, e.g. "/api/v1", as the API builds it.
     */
    public static function apiRoute(): string
    {
        $config = Grav::instance()['config'];

        return '/' . trim((string)$config->get('plugins.api.route', '/api'), '/') . '/' . trim((string)$config->get('plugins.api.version_prefix', 'v1'), '/');
    }

    /**
     * Why "Enforce OAuth2" doesn't apply on a side, null if it applies: the one rule of the login checks
     * (LoginOauth2Plugin::isOAuth2Enforced()), the settings notice (enforceConditionsNotice()) and the CLI. "disabled": the
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
     * session, also one from a login that "Enforce OAuth2" doesn't cover; on unless set to off, as the API reads it).
     * Name => whether it's off, in the order to show them. The plugin never changes them itself.
     *
     * @param bool $admin
     * @param callable|null $get Reads a setting by its config path, e.g. of one environment (the CLI); default: Grav's
     *                           configuration of this request
     * @return array<string, bool>
     */
    public static function enforceRequirements(bool $admin, ?callable $get = null): array
    {
        $get = $get ?? static::liveConfig();
        $requirements = ['remember_me' => !$get(static::REQUIREMENT_KEYS['remember_me'])];
        if ($admin) {
            // Not set (null) is on: the API's own default (AuthMiddleware), also without the key in its api.yaml.
            $requirements['session_passthrough'] = !$get('plugins.api.enabled') || !($get(static::REQUIREMENT_KEYS['session_passthrough']) ?? true);
        }

        return $requirements;
    }

    /**
     * The requirements of a side that aren't met (enforceRequirements()).
     *
     * @param bool $admin
     * @param callable|null $get Reads a setting by its config path (enforceRequirements())
     * @return string[]
     */
    public static function enforceUnmet(bool $admin, ?callable $get = null): array
    {
        return array_keys(array_filter(static::enforceRequirements($admin, $get), static function (bool $met) {
            return !$met;
        }));
    }

    /**
     * Whether an account can use the admin: the classic admin (admin.login, admin.super) or the API that Admin2 uses
     * (api.access, api.super). Asked of a copy that is logged in and its login authorized, so the account itself stays
     * as it is: until then Grav only answers login actions, and an account loaded from its file (e.g. by a command)
     * answers nothing (Grav's "test" scope, which would skip both, only exists for Flex accounts). With $anyState the
     * copy is also enabled (Grav answers nothing for a disabled account either), e.g. to sign a disabled account out
     * before it's enabled again. An account the API lets in counts too (apiGrantsAccess()).
     *
     * @param UserInterface $user
     * @param bool $anyState
     * @return bool
     */
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

        return static::apiGrantsAccess($copy);
    }

    /**
     * Whether the API plugin gives an account "api.access", asked as the API itself decides it (its PermissionResolver,
     * while the plugin is enabled): also from a parent key, i.e. a blanket "api: true" of the account or one of its
     * groups, which Grav doesn't count for regular accounts (only for Flex accounts). Not for a disabled account, which
     * the resolver doesn't look at.
     */
    protected static function apiGrantsAccess(UserInterface $user): bool
    {
        if (!class_exists(ApiPermissionResolver::class) || $user->get('state', 'enabled') !== 'enabled') {
            return false;
        }

        try {
            return (new ApiPermissionResolver())->resolve($user, 'api.access') === true;
        } catch (\Throwable $e) {
            // An API version that resolves it another way: what Grav answered above stands.
            Grav::instance()['log']->warning('Login OAuth2: the API plugin could not be asked for the access of an account: ' . $e->getMessage());

            return false;
        }
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

        return static::andList(array_map(static function (string $requirement) use ($language) {
            return (string)$language->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_REQUIREMENT_' . strtoupper($requirement));
        }, $requirements));
    }

    /**
     * Words as a list: "A", "A and B", "A, B and C".
     *
     * @param string[] $words
     */
    public static function andList(array $words): string
    {
        $last = array_pop($words);

        return $words ? implode(', ', $words) . ' ' . Grav::instance()['language']->translate('PLUGIN_LOGIN_OAUTH2.AND') . ' ' . $last : (string)$last;
    }

    /**
     * The block below a side's "Enforce OAuth2" toggle (blueprint: data-content@), in both admins: each requirement
     * (enforceRequirements()) with its state, why it's needed and where to change it; and why it doesn't apply yet,
     * if so (enforceNotApplying()). While the toggle is off it's plain: what turning it on needs (it's refused while a
     * requirement isn't met, see LoginOauth2Plugin::onAdminSave()). While it's on: green when it all holds, red while
     * a requirement isn't met, amber while it only doesn't apply. Red too in Admin2 while the admin has no active
     * provider of this plugin and those of satellite plugins can't be registered: the checks of Admin2's logins then
     * keep it in effect (LoginOauth2Plugin::isOAuth2Enforced()). The states are the saved settings of the
     * configuration the form shows (formConfig()).
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
            $get = static::formConfig();
            $config = (array)($get('plugins.login-oauth2') ?? []);
            $requirements = static::enforceRequirements($admin, $get);
            $unmet = in_array(false, $requirements, true);
            $on = !empty($admin ? ($config['admin']['enforce_oauth2'] ?? false) : ($config['enforce_oauth2'] ?? false));
            $notApplying = static::enforceNotApplying($admin, $config, static::noticeProviders($admin, $config, $failed));
            // Only Admin2's logins ask the satellite plugins on the spot, and only for a side without an active
            // provider of this plugin: there it stays in effect if they fail.
            if ($notApplying !== 'no_provider' || !$admin || static::classicAdmin()) {
                $failed = null;
            }

            $url = static::adminUrl();
            $links = [
                'remember_me' => $url . '/plugins/login#options',
                'session_passthrough' => $url . '/plugins/api#tab_authentication',
            ];
            // Arguments of each requirement's explanation (ENFORCE_REQUIREMENT_<NAME>_WHY).
            $why = [
                'remember_me' => [
                    static::duration((int)($get('plugins.login.rememberme.timeout') ?? 604800)),
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

            if (!$on) {
                $style = 'border-border bg-muted/50';
            } elseif ($unmet || $failed !== null) {
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
            if ($failed !== null) {
                $html .= '<p class="mt-2">' . $language->translate(['PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_PROVIDERS_FAILED', htmlspecialchars($failed, ENT_QUOTES)]) . '</p>';
            } elseif ($notApplying) {
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
     * @param callable|null $get Reads a setting by its config path; default: the configuration the form shows
     *                           (formConfig())
     * @return string[]
     */
    public static function enforceWarningLines(?callable $get = null): array
    {
        $get = $get ?? static::formConfig();
        $language = Grav::instance()['language'];
        $lines = [];
        foreach (['site' => false, 'admin' => true] as $side => $admin) {
            $on = $get('plugins.login-oauth2.' . ($admin ? 'admin.' : '') . 'enforce_oauth2');
            $unmet = $on ? static::enforceUnmet($admin, $get) : [];
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
     * The notice of Admin2's dashboard (LoginOauth2Plugin::onApiDashboardNotifications()) while "Enforce OAuth2" is on
     * with requirements not met, as the red warning of the settings (enforceWarning()), in Markdown with a link to the
     * settings. Null otherwise. For the configuration this request runs with, as the site's logins do: the dashboard
     * has no form, and the environment chosen in Admin2's selector may be another one.
     */
    public static function enforceDashboardNotice(): ?string
    {
        $lines = static::enforceWarningLines(static::liveConfig());
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
     * Why "Enforce OAuth2" doesn't apply on a side (enforceNotApplying()), in words: for the settings form, which
     * tells where to change it there, or in short for the commands, which name the side themselves. The one list of
     * the reasons, so a new one gets both wordings here.
     *
     * @param bool $admin
     * @param string $why
     * @param bool $short For the commands, else for the settings form
     */
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
     * add (registerProviders()). Afterwards Grav's "oauth2" service is the request's own again, which may be the other
     * side's (e.g. the admin's "Enforce OAuth2" checked on a site login).
     *
     * @return self
     */
    public static function registered(bool $admin): self
    {
        return (new self($admin))->registerProviders();
    }

    /**
     * A side's providers for the notice of the settings form, as its login checks count them: this plugin's as the
     * form's configuration has them (formConfig()), and those of satellite plugins (registeredFor()). If those can't
     * be registered (a satellite plugin fails), the ones the request's service has for that side, and why in $failed.
     *
     * @param bool $admin
     * @param array $config The plugin's settings in the form's configuration
     * @param string|null $failed
     * @return array Name => settings
     */
    protected static function noticeProviders(bool $admin, array $config, ?string &$failed = null): array
    {
        $own = (array)($config['providers'] ?? []) + (array)($config['admin']['providers'] ?? []);
        $failed = null;
        try {
            $others = static::registeredFor($admin);
        } catch (\Throwable $e) {
            $failed = $e->getMessage();
            $grav = Grav::instance();
            $others = isset($grav['oauth2']) && $grav['oauth2']->isAdmin() === $admin ? $grav['oauth2']->getProviders() : [];
        }

        return static::enabledProviders($admin, $config) + array_diff_key($others, $own);
    }

    /**
     * The providers a side's login gets in this request, also the ones of satellite plugins that aren't configured
     * yet: the one list for the settings form and the commands (EnvironmentSettings::registeredProviders()).
     *
     * For the site, where the request's own service is the site's (the site, Admin2, the commands): this plugin's
     * from the current settings, which a command may have changed since, and from that service the ones satellite
     * plugins added in their own onPluginsInitialized, as its login page and login checks count them. Without
     * onOAuth2ProvidersRegister: up to 2.3.0 it only fired for the admin's service, so a handler may add its admin
     * providers without looking at the side, and they would count for the site.
     *
     * For the admin, and for the site where the request's service is the admin's (classic admin) or there is none:
     * the ones registered with that event (registered()).
     *
     * @return array Name => settings
     */
    public static function registeredFor(bool $admin): array
    {
        $grav = Grav::instance();
        if (!$admin && isset($grav['oauth2']) && !$grav['oauth2']->isAdmin()) {
            $config = (array)($grav['config']->get('plugins.login-oauth2') ?? []);

            return static::enabledProviders(false, $config) + array_diff_key($grav['oauth2']->getProviders(), static::ownProviders());
        }

        return static::registered($admin)->getProviders();
    }

    /**
     * Adds this plugin's enabled providers, then lets satellite plugins (e.g. login-oauth2-extras) add theirs on
     * onOAuth2ProvidersRegister, which they listen to from their onPluginsInitialized. The event carries this service
     * ("oauth2"): they add to it, and its isAdmin() tells them the side. While the event fires, it's also Grav's
     * "oauth2" service, for handlers that take that one, and for the provider classes they construct, which read its
     * isAdmin() to pick their settings (BaseProvider). Afterwards the request's own service is back, also if a handler
     * throws.
     *
     * @return $this
     */
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

    /**
     * The providers that have their client ID, of a name => settings list.
     */
    public static function configured(array $providers): array
    {
        return array_filter($providers, [static::class, 'isConfigured']);
    }

    /**
     * Adds this plugin's enabled providers of the side (enabledProviders()). One the settings enable that this plugin
     * doesn't have is left out, with a warning in the log (once a day, warnOnce()).
     */
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

    /**
     * Logs a warning about something that stays as it is until the settings change, once a day instead of on every
     * request: a file in Grav's cache folder tells when it was logged last (not Grav's cache, which a site can turn
     * off). If the file can't be written, it's logged every time.
     */
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

    /**
     * This plugin's providers that are enabled on a side, with the settings they need (their checkIfActive()), from
     * the plugin's settings: the one list for the logins, the notices of the settings form and the commands. A name
     * without a provider class (e.g. a typo) is no provider: it's left out, and named in $unknown if it's enabled.
     *
     * @param bool $admin
     * @param array $config The plugin's settings (plugins.login-oauth2)
     * @param string[] $unknown
     * @return array<string, array> Name => settings
     */
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