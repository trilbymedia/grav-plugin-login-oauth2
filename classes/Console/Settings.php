<?php

namespace Grav\Plugin\Login\OAuth2\Console;

use Grav\Common\Config\Setup;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\Yaml;
use Grav\Plugin\Login\OAuth2\EnvironmentSettings;
use Grav\Plugin\Login\OAuth2\OAuth2;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The plugin settings for the bin/plugin commands: reads the configuration Grav uses, changes the config files (the
 * main one and those of the environments), and tells the active providers of a side, the settings a provider has and,
 * as EnvironmentSettings, the settings of other plugins "Enforce OAuth2" requires off, in every environment.
 */
class Settings extends EnvironmentSettings
{
    /** @var InputInterface The command's input */
    protected $input;
    /** @var string[]|null The plugin's existing config files, found once (save() can add one) */
    protected $files;
    /** @var array<string, string> Side => why the providers of other plugins couldn't be registered (registeredProviders()) */
    protected $unregistered = [];

    /**
     * Changes go to the environment's config file if it has a folder (--env or GRAV_ENVIRONMENT), created if needed,
     * else to the main one. Never to the one of Grav's CLI default environment "cli", which web requests don't use.
     */
    public function __construct(Grav $grav, InputInterface $input)
    {
        // What cliDefault() asks.
        $this->grav = $grav;
        $this->input = $input;
        $folder = $this->cliDefault() ? false : $grav['locator']->findResource('environment://', true);
        parent::__construct($grav, $folder ?: null);
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
     * The environment whose file alone changes go to, as a later command needs it for --env to reach the same file;
     * null if they go to the main file, or to a folder all requests use (reachesEveryEnvironment()).
     *
     * @return string|null
     */
    public function environment(): ?string
    {
        return $this->reachesEveryEnvironment() ? null : (string)$this->grav['setup']->get('environment');
    }

    /**
     * The providers a side's login gets (EnvironmentSettings::registeredProviders()). If a plugin that adds providers
     * fails (its handler of onOAuth2ProvidersRegister throws), this plugin's own enabled ones alone, so the commands
     * still work: they warn about it (providersFailed()).
     *
     * @param string $side
     * @return array Name => settings
     */
    public function registeredProviders(string $side): array
    {
        try {
            return parent::registeredProviders($side);
        } catch (\Throwable $e) {
            $this->unregistered[$side] = $e->getMessage();
            $config = (array)($this->grav['config']->get('plugins.login-oauth2') ?? []);

            // Kept until the settings change (replace()), instead of asking the failing plugin each time.
            return $this->registeredProviders[$side] = OAuth2::enabledProviders($side === 'admin', $config);
        }
    }

    /**
     * Why the providers of other plugins couldn't be registered (registeredProviders()), null if they could: asked
     * for both sides when a command starts, for its warning.
     *
     * @return string|null
     */
    public function providersFailed(): ?string
    {
        foreach (['site', 'admin'] as $side) {
            $this->registeredProviders($side);
        }

        return $this->unregistered ? implode('; ', array_unique($this->unregistered)) : null;
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

        $files = [$this->mainFile()];
        foreach ($this->environmentFolders() as $folder) {
            $files[] = $this->configFile('login-oauth2', $folder);
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
     * The environment whose file changes are saved to and what chose it, for the messages, e.g. "environment 'staging',
     * chosen by --env".
     *
     * @return string
     */
    public function environmentSource(): string
    {
        $name = (string)$this->grav['setup']->get('environment');
        if ($name !== (string)Setup::$environment) {
            $from = 'setup.php';
        } else {
            $from = $this->input->hasParameterOption('--env') ? '--env' : 'GRAV_ENVIRONMENT';
        }

        return "environment '{$name}', chosen by {$from}";
    }

    /**
     * The environment config files that set one of these keys to another value than the main file: Grav reads them
     * after the main one, so on the requests of their environment they override a change of the main file. None for a
     * change of an environment's file, and never the file of Grav's CLI default environment "cli" (cliFolder()).
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

        $overrides = [];
        foreach ($this->files() as $other) {
            if ($other === $file || $this->cliFolder(dirname($other, 3))) {
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
     * The config files in which a setting is still on: the main one (also if only the plugin's default turns it on)
     * and those of the environments that turn it on, except Grav's CLI default environment "cli" (cliFolder()). Read
     * from the files as they are now, not as this command read or saved them, so it also tells a change that didn't
     * reach its file.
     *
     * @param string $key
     * @return string[]
     */
    public function stillOn(string $key): array
    {
        $this->files = null;
        $main = $this->mainFile();
        $on = ($this->onDisk($main, $key) ?? Utils::getDotNotation($this->defaults(), $key)) ? [Folder::getRelativePath($main)] : [];
        foreach ($this->files() as $file) {
            if ($file !== $main && !$this->cliFolder(dirname($file, 3)) && $this->onDisk($file, $key)) {
                $on[] = Folder::getRelativePath($file);
            }
        }

        return $on;
    }

    /**
     * A setting in one config file as the file has it now (read() keeps what this command read or saved), null if it
     * isn't set there or the file can't be read.
     *
     * @param string $file
     * @param string $key
     * @return mixed
     */
    protected function onDisk(string $file, string $key)
    {
        clearstatcache(true, $file);
        $yaml = is_file($file) ? @file_get_contents($file) : false;
        if ($yaml === false) {
            return null;
        }
        try {
            return Utils::getDotNotation((array)Yaml::parse($yaml), $key);
        } catch (\Throwable $e) {
            // Grav can't read it either.
            return null;
        }
    }

    /**
     * The GRAV_CONFIG__ environment variable that turns a setting of this plugin on, or off, above every config file,
     * in every environment (environmentOverrides()): no change of a config file changes that. Null if none does.
     *
     * @param string $key E.g. "admin.enforce_oauth2"
     * @param bool $on Whether it's asked for one that turns it on, else off
     * @return string|null The variable's name
     */
    public function overriding(string $key, bool $on): ?string
    {
        $path = 'plugins.login-oauth2.' . $key;
        $variable = $this->environmentOverride($path);

        return $variable !== null && (bool)$this->overrideValues[$path] === $on ? $variable : null;
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
     * files every few seconds). The configuration of this command gets the values too, except where a GRAV_CONFIG__
     * variable overrides them (environmentOverrides()), as in Grav.
     *
     * @param string $file
     * @param array<string, mixed> $values Key => value
     * @param string $what For the log, e.g. "turned Enforce OAuth2 off"
     * @param string[]|null $changed The keys it changed
     * @return string|null The change ("<file>: <keys>"), null if nothing changed
     * @throws RuntimeException For a file that can't be read (unreadable())
     */
    public function save(string $file, array $values, string $what, ?array &$changed = null): ?string
    {
        $changed = [];
        $content = $this->content($file);
        // Saving would replace it with these settings alone.
        if (isset($this->unreadable[$file])) {
            throw new RuntimeException(sprintf("Can't read %s, so it wasn't changed: %s", Folder::getRelativePath($file), $this->unreadable[$file]));
        }
        $main = $file === $this->mainFile();
        foreach ($values as $key => $value) {
            $previous = Utils::getDotNotation($content, $key);
            $inherited = $main ? Utils::getDotNotation($this->defaults(), $key) : $this->mainValue($key);
            if ($previous === $value || ($previous === null && $inherited === $value)) {
                continue;
            }
            Utils::setDotNotation($content, $key, $value);
            if ($this->environmentOverride('plugins.login-oauth2.' . $key) === null) {
                $this->grav['config']->set('plugins.login-oauth2.' . $key, $value);
            }
            $changed[] = $key;
        }
        if (!$changed) {
            return null;
        }

        $yaml = CompiledYamlFile::instance($file);
        $yaml->save($content);
        $yaml->free();
        $this->replace($file, $content);
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

        return static::whyNotApplying($side, $config, $providers ?? $this->registeredProviders($side));
    }

    /**
     * The state of a requirement (requirements()) for the commands: "off", or where it's on and why it must be off.
     *
     * @param array<string, bool> $where Where it's on => whether "Enforce OAuth2" is on there (requirements())
     * @return string
     */
    public static function requirementState(array $where): string
    {
        if (!$where) {
            return 'off';
        }

        $enforced = in_array(true, $where, true);

        return sprintf('%s (%s): %s', $enforced ? '<error>on</error>' : 'on', implode(', ', array_keys($where)), $enforced ? 'users can still log in without OAuth2' : 'turn it off first');
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
     * Whether Grav runs in its CLI default environment "cli", which web requests don't use: a command with no
     * environment chosen by --env, GRAV_ENVIRONMENT, GRAV_ENVIRONMENT_PATH (a folder all requests use) or setup.php
     * ("environment").
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
        if (!$folder || !is_file($this->configFile('login-oauth2', $folder))) {
            return;
        }

        // As Grav loads it: the main config file, completed with the plugin's defaults, then the GRAV_CONFIG__
        // variables.
        $config = $this->grav['config'];
        $overrides = $this->pluginOverrides();
        $config->set('plugins.login-oauth2', $this->content($this->mainFile()));
        $config->joinDefaults('plugins.login-oauth2', $this->defaults());
        foreach ($overrides as $key => $value) {
            $config->set('plugins.login-oauth2.' . $key, $value);
        }
    }

    /**
     * A setting's value for the messages: on/off for a toggle.
     *
     * @param mixed $value
     * @return string
     */
    public static function show($value): string
    {
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }
        $json = json_encode($value);

        // E.g. a string that isn't valid UTF-8.
        return $json !== false ? $json : var_export($value, true);
    }
}
