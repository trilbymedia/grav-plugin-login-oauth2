<?php

namespace Grav\Plugin\Login\OAuth2\Console;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Plugin\Login\OAuth2\OAuth2;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The plugin settings for the bin/plugin commands: reads the configuration Grav uses, changes the config files (the
 * main one and those of the environments), and tells the active providers of a side and the settings a provider has.
 */
class Settings
{
    /** The plugin's config file, below a config folder's parent (user/, an environment's folder) */
    public const FILE = '/config/plugins/login-oauth2.yaml';

    /** @var Grav */
    protected $grav;
    /** @var InputInterface */
    protected $input;
    /** @var array|null The plugin's default configuration, read once */
    protected $defaults;
    /** @var array File => content, each config file read once (save() keeps it current) */
    protected $contents = [];
    /** @var string[]|null The plugin's existing config files, found once (save() can add one) */
    protected $files;

    public function __construct(Grav $grav, InputInterface $input)
    {
        $this->grav = $grav;
        $this->input = $input;
        $this->ignoreCliEnvironment();
    }

    /**
     * The sides a command applies to: both, unless only --site or only --admin is given.
     *
     * @param InputInterface $input
     * @return string[] "site" and/or "admin"
     */
    public static function sides(InputInterface $input): array
    {
        $site = (bool)$input->getOption('site');
        $admin = (bool)$input->getOption('admin');
        if ($site === $admin) {
            return ['site', 'admin'];
        }

        return $site ? ['site'] : ['admin'];
    }

    /**
     * The key of a setting on a side: as given for the site, below "admin." for the admin.
     *
     * @param string $side
     * @param string $key
     * @return string
     */
    public static function key(string $side, string $key): string
    {
        return ($side === 'admin' ? 'admin.' : '') . $key;
    }

    /**
     * A setting as Grav uses it: the plugin's default, overridden by the config files (of the environment, --env).
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->grav['config']->get('plugins.login-oauth2.' . $key, $default);
    }

    /**
     * The config file changes are saved to: the environment's if it has a folder (--env or GRAV_ENVIRONMENT), created
     * if needed, else the main one. Never the one of Grav's CLI default environment "cli", which web requests don't use.
     *
     * @return string
     */
    public function file(): string
    {
        $folder = $this->cliDefault() ? false : $this->grav['locator']->findResource('environment://', true);

        return $folder ? $folder . static::FILE : $this->mainFile();
    }

    /**
     * The main config file, which the files of the environments override: in the folder of the config stream that is
     * neither the environment's nor Grav's own, as Grav loads it (user/config, unless setup.php changes the stream).
     *
     * @return string
     */
    public function mainFile(): string
    {
        $locator = $this->grav['locator'];
        $other = [$locator->findResource('environment://config', true), $locator->findResource('system://config', true)];
        foreach ($locator->findResources('config://', true) as $folder) {
            if (!in_array($folder, $other, true)) {
                return $folder . '/plugins/login-oauth2.yaml';
            }
        }

        return $locator->findResource('user://', true) . static::FILE;
    }

    /**
     * The error for an environment given with --env that has no folder: Grav then uses the main configuration, so
     * changes would go there instead.
     *
     * @param InputInterface $input
     * @return string|null
     */
    public function missingEnvironment(InputInterface $input): ?string
    {
        // Only an --env given on the command line: Grav's default environment is "cli".
        if (!$input->hasParameterOption('--env') || $this->grav['locator']->findResource('environment://', true)) {
            return null;
        }

        return sprintf("Environment '%s' has no folder: nothing changed.", $input->getOption('env'));
    }

    /**
     * The plugin's existing config files: the main one and those of all environments, found where Grav looks for them
     * (Setup): the folder of GRAV_ENVIRONMENT_PATH, else the folders in GRAV_ENVIRONMENTS_PATH, else in user/env, else
     * in user/ (Grav 1.6 layout); and the folder of the current environment.
     *
     * @return string[]
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        // Grav has no API to list its environments.
        $locator = $this->grav['locator'];
        $user = (string)$locator->findResource('user://', true);
        $files = [$this->mainFile()];
        if ($current = $locator->findResource('environment://', true)) {
            $files[] = $current . static::FILE;
        }
        if (($path = static::setting('GRAV_ENVIRONMENT_PATH')) !== null) {
            $folders = [$this->path($path)];
        } else {
            $root = static::setting('GRAV_ENVIRONMENTS_PATH');
            $root = $root !== null ? $this->path($root) : (is_dir($user . '/env') ? $user . '/env' : $user);
            $folders = static::folders($root);
        }
        foreach (array_filter($folders) as $folder) {
            $files[] = $folder . static::FILE;
        }

        $found = [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $found[realpath($file) ?: $file] = $file;
            }
        }

        return $this->files = array_values($found);
    }

    /**
     * The environment config files that set one of these keys to another value than the main file: Grav reads them
     * after the main one, so on the requests of their environment they override a change of the main file. None for a
     * change of an environment's file, and never the file of Grav's CLI default environment "cli".
     *
     * @param string $file The changed file
     * @param array $values Key => value
     * @return string[] "<file>: <key> = <value>"
     */
    public function overrides(string $file, array $values): array
    {
        if ($file !== $this->mainFile()) {
            return [];
        }

        $cli = $this->cliDefault() ? $this->grav['locator']->findResource('environment://', true) : false;
        $overrides = [];
        foreach ($this->files() as $other) {
            if ($other === $file || ($cli && strpos($other, $cli . '/') === 0)) {
                continue;
            }
            foreach ($values as $key => $value) {
                $set = $this->read($other, $key);
                if ($set !== null && (is_bool($value) ? (bool)$set !== $value : $set !== $value)) {
                    $overrides[] = sprintf('%s: %s = %s', Folder::getRelativePath($other), $key, static::show($set));
                }
            }
        }

        return $overrides;
    }

    /**
     * The config files in which a setting is still on as Grav reads them: the main one (also if only the plugin's
     * default turns it on) and those of the environments that turn it on.
     *
     * @param string $key
     * @return string[]
     */
    public function stillOn(string $key): array
    {
        $main = $this->mainFile();
        $on = $this->mainValue($key) ? [Folder::getRelativePath($main)] : [];
        foreach ($this->files() as $file) {
            if ($file !== $main && $this->read($file, $key)) {
                $on[] = Folder::getRelativePath($file);
            }
        }

        return $on;
    }

    /**
     * A setting in one config file, null if it isn't set there.
     *
     * @param string $file
     * @param string $key
     * @return mixed
     */
    public function read(string $file, string $key)
    {
        return Utils::getDotNotation($this->content($file), $key);
    }

    /**
     * A setting as the main configuration gives it, without the environments: the main config file's, else the
     * plugin's default (e.g. an edited login-oauth2.yaml).
     *
     * @param string $key
     * @return mixed
     */
    public function mainValue(string $key)
    {
        return $this->read($this->mainFile(), $key) ?? Utils::getDotNotation($this->defaults(), $key);
    }

    /**
     * Saves settings to a config file, which is created if needed, only those that change: a value not set in the
     * file that it already gets from below (the main file from the plugin's default, an environment's file from the
     * main configuration) is left out. Grav saves the file as YAML again, without comments (as the admin does). Grav's
     * compiled configuration is removed, so the next request reads the file again (Grav only checks for changed config
     * files every few seconds). The configuration of this command gets the values too.
     *
     * @param string $file
     * @param array $values Key => value
     * @param string $what For the log, e.g. "turned Enforce OAuth2 off"
     * @return string|null The change ("<file>: <keys>"), null if nothing changed
     */
    public function save(string $file, array $values, string $what): ?string
    {
        $content = $this->content($file);
        $main = $file === $this->mainFile();
        $changed = [];
        foreach ($values as $key => $value) {
            $previous = Utils::getDotNotation($content, $key);
            $inherited = $main ? Utils::getDotNotation($this->defaults(), $key) : $this->mainValue($key);
            if ($previous === $value || ($previous === null && $inherited === $value)) {
                continue;
            }
            Utils::setDotNotation($content, $key, $value);
            $this->grav['config']->set('plugins.login-oauth2.' . $key, $value);
            $changed[] = $key;
        }
        if (!$changed) {
            return null;
        }

        $yaml = CompiledYamlFile::instance($file);
        $yaml->save($content);
        $yaml->free();
        $this->contents[$file] = $content;
        $this->files = null;
        $compiled = $this->grav['locator']->findResource('cache://compiled/config', true, true);
        foreach (is_dir($compiled) ? scandir($compiled) : [] as $name) {
            if (substr($name, -4) === '.php') {
                @unlink($compiled . '/' . $name);
            }
        }

        $change = Folder::getRelativePath($file) . ': ' . implode(', ', $changed);
        $this->grav['log']->notice("Login OAuth2 (bin/plugin): {$what} in {$change}");

        return $change;
    }

    /**
     * The active providers of a side, the ones "Enforce OAuth2" counts (LoginOauth2Plugin::isOAuth2Enforced()): the
     * registered ones that have their client ID.
     *
     * @param string $side
     * @param array|null $registered The side's registeredProviders(), if known
     * @return array Name => settings
     */
    public function activeProviders(string $side, ?array $registered = null): array
    {
        return OAuth2::configured($registered ?? $this->registeredProviders($side));
    }

    /**
     * The providers a side's login gets, also the ones of satellite plugins that aren't configured yet: this plugin's
     * from the current settings, and the ones satellite plugins add on onOAuth2ProvidersRegister (as for the Admin2
     * login, LoginOauth2Plugin::ensureAdminOAuth2()). For the site also the ones they add to the site's service in their
     * own onPluginsInitialized (e.g. login-oauth2-extras before 2.2.2), as its login page shows them.
     *
     * @param string $side
     * @return array Name => settings
     */
    public function registeredProviders(string $side): array
    {
        $providers = OAuth2::registered($side === 'admin')->getProviders();

        if ($side === 'site' && isset($this->grav['oauth2']) && !$this->grav['oauth2']->isAdmin()) {
            foreach ($this->grav['oauth2']->getProviders() as $name => $options) {
                // This plugin's from the current settings only (changed by this command, or without "cli").
                if (!in_array($name, $this->providers(), true)) {
                    $providers += [$name => $options];
                }
            }
        }

        return $providers;
    }

    /**
     * Why "Enforce OAuth2" doesn't apply on a side although it's on, null if it applies: the plugin's rule
     * (OAuth2::enforceNotApplying()), in words.
     *
     * @param string $side
     * @param array|null $providers The side's registeredProviders(), if known
     * @return string|null
     */
    public function notApplying(string $side, ?array $providers = null): ?string
    {
        $config = (array)($this->grav['config']->get('plugins.login-oauth2') ?? []);
        $why = OAuth2::enforceNotApplying($side === 'admin', $config, $providers ?? $this->registeredProviders($side));

        return $why === null ? null : [
            'disabled' => 'the plugin is disabled',
            'admin_disabled' => "'Enabled in Admin' is off",
            'no_provider' => 'no active provider',
        ][$why];
    }

    /**
     * This plugin's providers (the ones its default configuration has).
     *
     * @return string[]
     */
    public function providers(): array
    {
        return array_keys((array)($this->defaults()['providers'] ?? []));
    }

    /**
     * Why a provider of this plugin isn't active on a side: "disabled", or which of the settings it needs are empty.
     * The keys mirror the providers' checkIfActive(), which only answer yes or no.
     *
     * @param string $side
     * @param string $provider
     * @return string
     */
    public function inactiveReason(string $side, string $provider): string
    {
        $options = (array)$this->get(static::key($side, "providers.{$provider}"), []);
        if (empty($options['enabled'])) {
            return 'disabled';
        }

        $missing = [];
        foreach (['client_id', 'app_id', 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
            if (array_key_exists($key, $options) && empty($options[$key])) {
                $missing[] = $key;
            }
        }

        return $missing ? 'missing ' . implode(', ', $missing) : 'incomplete settings';
    }

    /**
     * Whether a provider has a setting on a side, i.e. the plugin's default configuration has it: e.g. "verify_peer"
     * (Verify Certificate), which only the Generic provider has.
     *
     * @param string $side
     * @param string $provider
     * @param string $setting
     * @return bool
     */
    public function defines(string $side, string $provider, string $setting): bool
    {
        return Utils::getDotNotation($this->defaults(), static::key($side, "providers.{$provider}.{$setting}")) !== null;
    }

    /**
     * @return array
     */
    protected function defaults(): array
    {
        if ($this->defaults === null) {
            $this->defaults = $this->content((string)$this->grav['locator']->findResource('plugins://login-oauth2/login-oauth2.yaml'));
        }

        return $this->defaults;
    }

    /**
     * @param string $file
     * @return array
     */
    protected function content(string $file): array
    {
        if (!isset($this->contents[$file])) {
            $content = [];
            if (is_file($file)) {
                $yaml = CompiledYamlFile::instance($file);
                $content = (array)$yaml->content();
                $yaml->free();
            }
            $this->contents[$file] = $content;
        }

        return $this->contents[$file];
    }

    /**
     * Whether Grav runs in its CLI default environment "cli", which web requests don't use: no environment chosen by
     * --env, GRAV_ENVIRONMENT, GRAV_ENVIRONMENT_PATH (a folder all requests use) or setup.php ("environment").
     *
     * @return bool
     */
    protected function cliDefault(): bool
    {
        return !$this->input->hasParameterOption('--env')
            && $this->grav['setup']->get('environment') === 'cli'
            && static::setting('GRAV_ENVIRONMENT') === null
            && static::setting('GRAV_ENVIRONMENT_PATH') === null;
    }

    /**
     * If the CLI default environment "cli" has its own config file of the plugin, reads the plugin's settings as the
     * main configuration gives them, without it: the commands show and change the main configuration, not "cli".
     */
    protected function ignoreCliEnvironment(): void
    {
        $folder = $this->cliDefault() ? $this->grav['locator']->findResource('environment://', true) : false;
        if (!$folder || !is_file($folder . static::FILE)) {
            return;
        }

        // As Grav loads it: the main config file, completed with the plugin's defaults.
        $file = $this->mainFile();
        $config = $this->grav['config'];
        $config->set('plugins.login-oauth2', $this->content($file));
        $config->joinDefaults('plugins.login-oauth2', $this->defaults());
    }

    /**
     * A folder Grav takes from GRAV_ENVIRONMENT_PATH or GRAV_ENVIRONMENTS_PATH: a stream (e.g. user://), an absolute
     * path, or a path relative to Grav's folder. Null for a stream without a folder.
     *
     * @param string $path
     * @return string|null
     */
    protected function path(string $path): ?string
    {
        $locator = $this->grav['locator'];
        // Before trimming: "user://" without its slashes is no stream.
        if ($locator->isStream($path)) {
            $folder = $locator->findResource($path, true, true);

            return $folder ? rtrim($folder, '/\\') : null;
        }

        $path = rtrim($path, '/\\');

        return preg_match('`^/|\w+:`', $path) ? $path : $locator->getBase() . '/' . $path;
    }

    /**
     * The folders in a folder (no glob(), which reads characters such as "[" in the path as a pattern).
     *
     * @param string|null $root
     * @return string[]
     */
    protected static function folders(?string $root): array
    {
        $folders = [];
        foreach ($root && is_dir($root) ? scandir($root) : [] as $name) {
            if ($name[0] !== '.' && is_dir($root . '/' . $name)) {
                $folders[] = $root . '/' . $name;
            }
        }

        return $folders;
    }

    /**
     * A setting's value for the messages: on/off for a toggle.
     *
     * @param mixed $value
     * @return string
     */
    public static function show($value): string
    {
        return is_bool($value) ? ($value ? 'on' : 'off') : json_encode($value);
    }

    /**
     * A setting Grav reads from a constant or the environment variables, e.g. GRAV_ENVIRONMENTS_PATH (as Setup does).
     *
     * @param string $name
     * @return string|null
     */
    protected static function setting(string $name): ?string
    {
        if (defined($name)) {
            return (string)constant($name);
        }
        foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
