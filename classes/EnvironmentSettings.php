<?php

namespace Grav\Plugin\Login\OAuth2;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Utils;

/**
 * The plugin's settings as the config files give them, in the main configuration and in every environment: where a
 * change of "Enforce OAuth2" reaches and applies, and the settings of other plugins it requires off there. Read from
 * the files alone, for a change of the main file or of one environment's, so it serves both the bin/plugin commands
 * (Console\Settings, which adds what a command reads and changes) and a save by an admin (ofSave()).
 */
class EnvironmentSettings
{
    /** @var Grav */
    protected $grav;
    /** @var string|null The folder of the environment whose config file changes go to (file()); null: the main file */
    protected $folder;
    /** @var array<string, array<string, mixed>> Plugin => its default configuration, each read once */
    protected $defaults = [];
    /** @var array File => content, each config file read once (replace() keeps it current) */
    protected $contents = [];
    /** @var string[]|null The folders environments can have (environmentFolders()), found once */
    protected $environmentFolders;
    /** @var string|null The main config folder (configFile()), found once */
    protected $mainConfig;
    /** @var array<string, array<string|null>> Side => its contexts(), found once (replace() resets them) */
    protected $contexts = [];
    /** @var array<string, array> Environment folder ("" for the main configuration) => contextConfig(), found once (replace() resets them) */
    protected $contextConfigs = [];
    /** @var array<string, array<string, string>> Side => keptOff(), found once (replace() resets them) */
    protected $keptOff = [];
    /** @var array<string, array> Side => registeredProviders(), found once (replace() resets them) */
    protected $registeredProviders = [];
    /** @var array<string, string>|null environmentOverrides(), found once */
    protected $environmentOverrides;
    /** @var array<string, mixed> Config path => the value its GRAV_CONFIG__ variable gave it (environmentOverrides()) */
    protected $overrideValues = [];
    /** @var string[]|null streamFolders(), found once */
    protected $streamFolders;
    /** @var array<string, string> Config file that can't be read (unreadable()) => why */
    protected $unreadable = [];

    /**
     * @param Grav $grav
     * @param string|null $folder The folder of the environment whose config file changes go to, null for the main file
     */
    public function __construct(Grav $grav, ?string $folder = null)
    {
        $this->grav = $grav;
        $this->folder = $folder;
        // Before anything changes the configuration (a command's): the values Grav gave the overridden settings.
        $this->environmentOverrides();
    }

    /**
     * The settings as a save of the plugin's main config file by an admin (classic admin, Admin2) is about to make
     * them, to check it in every environment as a command does (LoginOauth2Plugin::onAdminSave()). Null for a save of
     * another file: an environment's only reaches that environment, which the save's own check covers.
     *
     * @param Grav $grav
     * @param string $file The config file the save goes to
     * @param array $config The plugin's settings it saves
     * @return self|null
     */
    public static function ofSave(Grav $grav, string $file, array $config): ?self
    {
        $settings = new self($grav);
        $main = $settings->mainFile();
        if (!static::sameFile($file, $main)) {
            return null;
        }
        $settings->replace($main, $config);

        return $settings;
    }

    /**
     * Whether two paths name the same file, also one that doesn't exist yet, or written in another way (separators,
     * links).
     */
    protected static function sameFile(string $one, string $other): bool
    {
        $path = static function (string $file): string {
            $folder = realpath(dirname($file));

            return str_replace('\\', '/', ($folder !== false ? $folder : dirname($file)) . '/' . basename($file));
        };

        return $path($one) === $path($other);
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
     * The config file changes go to: the one of the environment given, else the main one.
     *
     * @return string
     */
    public function file(): string
    {
        return $this->folder !== null ? $this->configFile('login-oauth2', $this->folder) : $this->mainFile();
    }

    /**
     * The main config file, which the files of the environments override: in the folder of the config stream that is
     * neither the environment's nor Grav's own, as Grav loads it (user/config, unless setup.php changes the stream).
     *
     * @return string
     */
    public function mainFile(): string
    {
        return $this->configFile('login-oauth2');
    }

    /**
     * A plugin's config file: the main one (mainFile()), or the one in an environment's folder.
     *
     * @param string $plugin
     * @param string|null $folder The environment's folder, null for the main config file
     * @return string
     */
    public function configFile(string $plugin, ?string $folder = null): string
    {
        if ($folder !== null) {
            return "{$folder}/config/plugins/{$plugin}.yaml";
        }

        if ($this->mainConfig === null) {
            $locator = $this->grav['locator'];
            $other = [$locator->findResource('environment://config', true), $locator->findResource('system://config', true)];
            $this->mainConfig = $locator->findResource('user://', true) . '/config';
            foreach ($locator->findResources('config://', true) as $config) {
                if (!in_array($config, $other, true)) {
                    $this->mainConfig = $config;
                    break;
                }
            }
        }

        return "{$this->mainConfig}/plugins/{$plugin}.yaml";
    }

    /**
     * The folders environments can have, found where Grav looks for them (Setup): the folder of
     * GRAV_ENVIRONMENT_PATH, else the folders in GRAV_ENVIRONMENTS_PATH, else in user/env, else in user/ (Grav 1.6
     * layout, so also folders that are no environment); and the folder of the current environment, first. Without the
     * folders Grav never uses as one (streamFolder()), e.g. user/pages in the Grav 1.6 layout.
     *
     * @return string[]
     */
    protected function environmentFolders(): array
    {
        if ($this->environmentFolders !== null) {
            return $this->environmentFolders;
        }

        // Grav has no API to list its environments.
        $locator = $this->grav['locator'];
        $user = (string)$locator->findResource('user://', true);
        $folders = [];
        $current = $locator->findResource('environment://', true);
        if ($current) {
            $folders[] = $current;
        }
        if (($path = static::setting('GRAV_ENVIRONMENT_PATH')) !== null) {
            $folders[] = $this->path($path);
        } else {
            $root = static::setting('GRAV_ENVIRONMENTS_PATH');
            $root = $root !== null ? $this->path($root) : (is_dir($user . '/env') ? $user . '/env' : $user);
            $folders = array_merge($folders, static::folders($root));
        }

        // The current one is Grav's own choice.
        return $this->environmentFolders = array_values(array_filter($folders, function ($folder) use ($current) {
            return $folder && ($folder === $current || !$this->streamFolder($folder));
        }));
    }

    /**
     * Whether a folder is, or lies in, one that another stream serves from the user folder (pages, data, accounts,
     * config, plugins, themes, and what setup.php adds): Grav never uses such a folder as an environment (Setup, Grav
     * 1.8 or newer), e.g. user/pages with a config folder in the Grav 1.6 layout.
     *
     * @param string $folder
     * @return bool
     */
    protected function streamFolder(string $folder): bool
    {
        $real = realpath($folder);
        if ($real === false) {
            return false;
        }
        $real .= DIRECTORY_SEPARATOR;
        foreach ($this->streamFolders() as $root) {
            if (strncasecmp($real, $root, strlen($root)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The folders in the user folder that streams other than the environment's serve (streamFolder()), as Grav's setup
     * has them, each with a trailing separator ("user/pages" isn't "user/pages.example.com").
     *
     * @return string[]
     */
    protected function streamFolders(): array
    {
        if ($this->streamFolders !== null) {
            return $this->streamFolders;
        }

        $this->streamFolders = [];
        $user = (string)$this->grav['locator']->findResource('user://', true);
        foreach ((array)$this->grav['setup']->get('streams.schemes', []) as $scheme => $config) {
            if ($scheme === 'environment') {
                continue;
            }
            $prefixes = (array)($config['prefixes'] ?? []);
            $prefixes[] = $config['paths'] ?? [];
            foreach ($prefixes as $paths) {
                foreach ((array)$paths as $path) {
                    // "user://" alone is the parent of every environment folder in the Grav 1.6 layout.
                    if (!is_string($path) || strpos($path, 'user://') !== 0 || trim(substr($path, 7), '/') === '') {
                        continue;
                    }
                    $root = realpath($user . '/' . substr($path, 7));
                    if ($root !== false) {
                        $this->streamFolders[] = $root . DIRECTORY_SEPARATOR;
                    }
                }
            }
        }

        return $this->streamFolders;
    }

    /**
     * Whether a change written to file() reaches every web request: written to the main file, or to the folder of
     * GRAV_ENVIRONMENT_PATH, which all requests use. Not for the file of one environment (--env, GRAV_ENVIRONMENT, or
     * setup.php, which may choose it by the request's host).
     *
     * @return bool
     */
    public function reachesEveryEnvironment(): bool
    {
        return $this->file() === $this->mainFile() || static::setting('GRAV_ENVIRONMENT_PATH') !== null;
    }

    /**
     * The configurations of the web requests a change of "Enforce OAuth2" on a side reaches and where it applies, as
     * their environment's folder (null: the main configuration alone, for the requests without one). A change of the
     * environment's file (--env, GRAV_ENVIRONMENT or GRAV_ENVIRONMENT_PATH) reaches its requests only, if it applies
     * there. A change of the main file reaches the main configuration if it applies there, and the environments of
     * reachedEnvironments() except those of keptOff().
     *
     * @param string $side
     * @return array<string|null>
     */
    protected function contexts(string $side): array
    {
        if (isset($this->contexts[$side])) {
            return $this->contexts[$side];
        }

        if ($this->file() !== $this->mainFile()) {
            return $this->contexts[$side] = $this->notApplyingIn($this->folder, $side) === null ? [$this->folder] : [];
        }

        $contexts = $this->notApplyingIn(null, $side) === null ? [null] : [];
        $keptOff = $this->keptOff($side);
        foreach ($this->reachedEnvironments() as $folder) {
            if (!isset($keptOff[$folder])) {
                $contexts[] = $folder;
            }
        }

        return $this->contexts[$side] = $contexts;
    }

    /**
     * Why "Enforce OAuth2" on a side would apply in none of the configurations a change of it reaches, null if it
     * would in at least one: for a change of the main file the main configuration or an environment it reaches
     * (contexts()), e.g. one that enables a provider in its own file; for a change of an environment's file that one.
     *
     * @param string $side
     * @return string|null Why it doesn't apply in the main configuration or that environment, e.g. "no active provider"
     */
    public function appliesNowhere(string $side): ?string
    {
        if ($this->file() !== $this->mainFile()) {
            return $this->notApplyingIn($this->folder, $side);
        }

        return $this->contexts($side) ? null : $this->notApplyingIn(null, $side);
    }

    /**
     * Why "Enforce OAuth2" on a side wouldn't apply in the main configuration (the requests without an environment
     * folder) although a change of the main file reaches environments where it would (appliesIn()); null if it would
     * apply there too, or nowhere, and for a change of an environment's file.
     *
     * @param string $side
     * @return string|null
     */
    public function mainNotApplying(string $side): ?string
    {
        return $this->appliesIn($side) ? $this->notApplyingIn(null, $side) : null;
    }

    /**
     * The environments a change of "Enforce OAuth2" in the main file reaches where it would apply (contexts()), as
     * their folders, e.g. "user/env/example.com". None for a change of an environment's file.
     *
     * @param string $side
     * @return string[]
     */
    public function appliesIn(string $side): array
    {
        if ($this->file() !== $this->mainFile()) {
            return [];
        }

        return array_map([Folder::class, 'getRelativePath'], array_values(array_filter($this->contexts($side), 'is_string')));
    }

    /**
     * The folders of the environments a change of the main file reaches: those with a config folder (the others use
     * the main configuration alone), except the one of Grav's CLI default environment "cli" (cliFolder()). None for a
     * change of an environment's file.
     *
     * @return string[]
     */
    protected function reachedEnvironments(): array
    {
        if ($this->file() !== $this->mainFile()) {
            return [];
        }

        $seen = [];
        $folders = [];
        foreach ($this->environmentFolders() as $folder) {
            $real = realpath($folder) ?: $folder;
            if (isset($seen[$real]) || !is_dir($folder . '/config') || $this->cliFolder($folder)) {
                continue;
            }
            $seen[$real] = true;
            $folders[] = $folder;
        }

        return $folders;
    }

    /**
     * The environments a change of the main file reaches (reachedEnvironments()) where "Enforce OAuth2" on a side
     * wouldn't apply, so their requests still allow logins with a password: they turn it off themselves, or it
     * doesn't apply there (notApplyingIn()). None turns it off itself while a GRAV_CONFIG__ variable sets it: that
     * overrides their files too (contextValue()).
     *
     * @param string $side
     * @return array<string, string> Folder => why, e.g. "the plugin is disabled"
     */
    public function keptOff(string $side): array
    {
        if (isset($this->keptOff[$side])) {
            return $this->keptOff[$side];
        }

        $key = static::key($side, 'enforce_oauth2');
        $overridden = $this->environmentOverride('plugins.login-oauth2.' . $key) !== null;
        $keptOff = [];
        foreach ($this->reachedEnvironments() as $folder) {
            $own = $overridden ? null : $this->read($this->configFile('login-oauth2', $folder), $key);
            $why = $own !== null && !$own ? 'turns it off' : $this->notApplyingIn($folder, $side);
            if ($why !== null) {
                $keptOff[$folder] = $why;
            }
        }

        return $this->keptOff[$side] = $keptOff;
    }

    /**
     * Why "Enforce OAuth2" on a side wouldn't apply in a configuration, null if it would: the plugin's rule
     * (OAuth2::enforceNotApplying()) with the plugin's settings there (contextConfig()), in words. This plugin's
     * providers count as they're set there, those of satellite plugins as this command gets them (their settings
     * aren't read per environment).
     *
     * @param string|null $folder The environment's folder, null for the main configuration
     * @param string $side
     * @return string|null
     */
    protected function notApplyingIn(?string $folder, string $side): ?string
    {
        $config = $this->contextConfig($folder);

        return static::whyNotApplying($side, $config, OAuth2::enabledProviders($side === 'admin', $config) + $this->satelliteProviders($side));
    }

    /**
     * The plugin's settings in a configuration, as Grav merges them: the plugin's defaults, the main config file, the
     * environment's file and the GRAV_CONFIG__ variables.
     *
     * @param string|null $folder The environment's folder, null for the main configuration
     * @return array
     */
    protected function contextConfig(?string $folder): array
    {
        $key = (string)$folder;
        if (!isset($this->contextConfigs[$key])) {
            $config = array_replace_recursive($this->defaults(), $this->content($this->mainFile()));
            if ($folder !== null) {
                $config = array_replace_recursive($config, $this->content($this->configFile('login-oauth2', $folder)));
            }
            foreach ($this->pluginOverrides() as $setting => $value) {
                Utils::setDotNotation($config, $setting, $value);
            }
            $this->contextConfigs[$key] = $config;
        }

        return $this->contextConfigs[$key];
    }

    /**
     * A setting in one of the configurations of contexts(), by its config path (e.g. "plugins.login.rememberme.enabled"),
     * as Grav merges it: the environment's config file, else the main one, else the plugin's default. With where to
     * change it: the file that sets it, else (the plugin's default) the main file, for the messages.
     *
     * @param string|null $folder The environment's folder, null for the main configuration
     * @param string $path "plugins.<plugin>.<key>"
     * @return array{0: mixed, 1: string} The value and where to change it, e.g. "user/env/example.com/config/plugins/login.yaml"
     *                                    or "by default; set it in user/config/plugins/login.yaml"
     */
    protected function contextValue(?string $folder, string $path): array
    {
        // Above every config file, in every environment: Grav applies it to this command's configuration too.
        if (($variable = $this->environmentOverride($path)) !== null) {
            return [$this->overrideValues[$path], "environment variable {$variable}"];
        }

        [, $plugin, $key] = explode('.', $path, 3) + ['', '', ''];
        $main = $this->configFile($plugin);
        foreach ($folder !== null ? [$this->configFile($plugin, $folder), $main] : [$main] as $file) {
            $value = $this->read($file, $key);
            if ($value !== null) {
                return [$value, Folder::getRelativePath($file)];
            }
        }

        return [Utils::getDotNotation($this->pluginDefaults($plugin), $key), 'by default; set it in ' . Folder::getRelativePath($main)];
    }

    /**
     * The config files of the plugin that exist but can't be read (e.g. invalid YAML), which the commands take as
     * empty: whether they turn a setting on is unknown, and they're never saved to.
     *
     * @return array<string, string> File (e.g. "user/env/example.com/config/plugins/login-oauth2.yaml") => why
     */
    public function unreadable(): array
    {
        $unreadable = [];
        foreach ($this->unreadable as $file => $why) {
            $unreadable[Folder::getRelativePath($file)] = $why;
        }

        return $unreadable;
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
     * The providers a side's login gets, also the ones of satellite plugins that aren't configured yet, as the
     * settings form counts them (OAuth2::registeredFor()): this plugin's from the current settings (changed by this
     * command, or without "cli"), and the ones satellite plugins add. Registered once per side (again after
     * replace()).
     *
     * @param string $side
     * @return array Name => settings
     */
    public function registeredProviders(string $side): array
    {
        if (!isset($this->registeredProviders[$side])) {
            $this->registeredProviders[$side] = OAuth2::registeredFor($side === 'admin');
        }

        return $this->registeredProviders[$side];
    }

    /**
     * Why "Enforce OAuth2" on a side wouldn't apply with these settings and providers, null if it would: the plugin's
     * rule (OAuth2::enforceNotApplying()), in the words of the commands (OAuth2::notApplyingReason()).
     *
     * @param string $side
     * @param array $config The plugin's settings (plugins.login-oauth2)
     * @param array $providers The side's providers, name => settings
     * @return string|null
     */
    protected static function whyNotApplying(string $side, array $config, array $providers): ?string
    {
        $admin = $side === 'admin';
        $why = OAuth2::enforceNotApplying($admin, $config, $providers);

        return $why === null ? null : OAuth2::notApplyingReason($admin, $why, true);
    }

    /**
     * The providers satellite plugins (e.g. login-oauth2-extras) add to a side: registeredProviders() without this
     * plugin's.
     *
     * @param string $side
     * @return array Name => settings
     */
    protected function satelliteProviders(string $side): array
    {
        return array_diff_key($this->registeredProviders($side), array_flip($this->providers()));
    }

    /**
     * The settings "Enforce OAuth2" on a side requires off (OAuth2::enforceRequirements()), in every configuration a
     * change of it reaches where it applies (contexts()), not the one of this command; while it applies nowhere yet,
     * in the configuration the change goes to (the main one, or the environment's). Name => where it's on
     * (contextValue(); empty: off everywhere) => whether "Enforce OAuth2" is on already in a configuration that gets
     * it from there, and applies.
     *
     * @param string $side
     * @param bool $changed Only in the configurations the change turns it on in: without the environments a change
     *                      of the main file reaches that set it themselves, where it stays as it is
     * @return array<string, array<string, bool>>
     */
    public function requirements(string $side, bool $changed = false): array
    {
        $key = static::key($side, 'enforce_oauth2');
        $enforce = 'plugins.login-oauth2.' . $key;
        $main = $this->file() === $this->mainFile();
        $contexts = $this->contexts($side);
        $on = [];
        foreach ($contexts ?: [$main ? null : $this->folder] as $folder) {
            // To on: contexts() has none that turn it off (keptOff()).
            if ($changed && $main && $folder !== null && $this->read($this->configFile('login-oauth2', $folder), $key) !== null) {
                continue;
            }
            $enforced = $contexts && $this->contextValue($folder, $enforce)[0];
            $where = [];
            $get = function (string $path) use ($folder, &$where) {
                [$value, $where[$path]] = $this->contextValue($folder, $path);

                return $value;
            };
            foreach (OAuth2::enforceRequirements($side === 'admin', $get) as $requirement => $met) {
                $on[$requirement] = $on[$requirement] ?? [];
                if (!$met) {
                    // Read while checking it: the setting that turns it on.
                    $file = $where[OAuth2::REQUIREMENT_KEYS[$requirement]];
                    $on[$requirement][$file] = !empty($on[$requirement][$file]) || $enforced;
                }
            }
        }

        $requirements = [];
        foreach ($on as $requirement => $files) {
            $requirements[OAuth2::requirementNames([$requirement])] = $files;
        }

        return $requirements;
    }

    /**
     * The settings "Enforce OAuth2" on a side requires off that are on where a change turns it on (requirements()),
     * in words with the files that turn them on ("A (file) and B (file)"); null if none: what turning it on is
     * refused for. Not where an environment sets it itself: the change doesn't reach it.
     *
     * @param string $side
     * @return string|null
     */
    public function unmetRequirements(string $side): ?string
    {
        $unmet = [];
        foreach ($this->requirements($side, true) as $name => $where) {
            if ($where) {
                $unmet[] = $name . ' (' . implode(', ', array_keys($where)) . ')';
            }
        }

        return $unmet ? OAuth2::andList($unmet) : null;
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
     * @return array
     */
    protected function defaults(): array
    {
        return $this->pluginDefaults('login-oauth2');
    }

    /**
     * A plugin's default configuration, empty if it isn't installed.
     *
     * @param string $plugin
     * @return array<string, mixed>
     */
    protected function pluginDefaults(string $plugin): array
    {
        if (!isset($this->defaults[$plugin])) {
            $file = $this->grav['locator']->findResource("plugins://{$plugin}/{$plugin}.yaml");
            $this->defaults[$plugin] = is_string($file) ? $this->content($file) : [];
        }

        return $this->defaults[$plugin];
    }

    /**
     * Takes a config file's settings as they are from now on (saved, or about to be), and forgets what was found
     * with the old ones, so every answer after it is for the new ones.
     *
     * @param string $file
     * @param array $content
     */
    protected function replace(string $file, array $content): void
    {
        $this->contents[$file] = $content;
        $this->contexts = [];
        $this->contextConfigs = [];
        $this->keptOff = [];
        $this->registeredProviders = [];
    }

    /**
     * A config file's settings, each file read once; empty for one that doesn't exist or can't be read (unreadable()).
     *
     * @param string $file
     * @return array
     */
    protected function content(string $file): array
    {
        if (!isset($this->contents[$file])) {
            $content = [];
            if (is_file($file)) {
                $yaml = CompiledYamlFile::instance($file);
                try {
                    $content = (array)$yaml->content();
                } catch (\Throwable $e) {
                    // E.g. invalid YAML in one environment's file: the others are still read and changed (unreadable()).
                    $this->unreadable[$file] = $e->getMessage();
                } finally {
                    $yaml->free();
                }
            }
            $this->contents[$file] = $content;
        }

        return $this->contents[$file];
    }

    /**
     * Whether an environment folder is the one of Grav's CLI default environment "cli", which web requests don't use:
     * named "cli", unless GRAV_ENVIRONMENT_PATH makes it the folder of every request.
     *
     * @param string $folder
     * @return bool
     */
    protected function cliFolder(string $folder): bool
    {
        return basename($folder) === 'cli' && static::setting('GRAV_ENVIRONMENT_PATH') === null;
    }

    /**
     * The settings of this plugin GRAV_CONFIG__ variables override (environmentOverrides()), with the values Grav gave
     * them.
     *
     * @return array<string, mixed> Key (e.g. "admin.enabled") => value
     */
    protected function pluginOverrides(): array
    {
        $overrides = [];
        foreach ($this->overrideValues as $path => $value) {
            if (strpos($path, 'plugins.login-oauth2.') === 0) {
                $overrides[substr($path, strlen('plugins.login-oauth2.'))] = $value;
            }
        }

        return $overrides;
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

        return preg_match('`^(/|\w+:)`', $path) ? $path : $locator->getBase() . '/' . $path;
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
     * The GRAV_CONFIG__ environment variable that overrides a setting (by its config path) above the config files
     * (environmentOverrides()), null if none.
     *
     * @param string $path E.g. "plugins.login.rememberme.enabled"
     * @return string|null The variable's name
     */
    protected function environmentOverride(string $path): ?string
    {
        return $this->environmentOverrides()[$path] ?? null;
    }

    /**
     * The GRAV_CONFIG__ environment variables, by the setting they override above the config files, as Grav reads them
     * (InitializeProcessor): only while GRAV_CONFIG is set, with the GRAV_CONFIG_ALIAS__ aliases. The web server may
     * set other variables than this command gets. Found once, with the values Grav gave the settings ($overrideValues):
     * the constructor asks before the command changes its configuration.
     *
     * @return array<string, string> Config path (e.g. "plugins.login.rememberme.enabled") => the variable's name
     */
    protected function environmentOverrides(): array
    {
        if ($this->environmentOverrides !== null) {
            return $this->environmentOverrides;
        }

        $this->environmentOverrides = [];
        $vars = $_ENV + $_SERVER;
        if (empty($vars['GRAV_CONFIG']) && !getenv('GRAV_CONFIG')) {
            return $this->environmentOverrides;
        }

        $aliases = [];
        foreach ($vars as $name => $value) {
            if (strpos((string)$name, 'GRAV_CONFIG_ALIAS__') === 0) {
                $aliases[substr((string)$name, strlen('GRAV_CONFIG_ALIAS__'))] = (string)$value;
            }
        }
        foreach (array_keys($vars) as $name) {
            if (strpos((string)$name, 'GRAV_CONFIG__') !== 0) {
                continue;
            }
            $key = str_replace('__', '.', substr((string)$name, strlen('GRAV_CONFIG__')));
            foreach ($aliases as $alias => $real) {
                $key = str_replace($alias, $real, $key);
            }
            // The last one wins, as in Grav.
            $this->environmentOverrides[$key] = (string)$name;
        }
        foreach (array_keys($this->environmentOverrides) as $path) {
            $this->overrideValues[$path] = $this->grav['config']->get($path);
        }

        return $this->environmentOverrides;
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
