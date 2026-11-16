<?php

namespace Grav\Plugin\Login\OAuth2\Console;

use Grav\Common\Config\Setup;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Plugin\Login\OAuth2\EnvironmentSettings;
use Grav\Plugin\Login\OAuth2\OAuth2;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;

/** CLI configuration and writes. */
class Settings extends EnvironmentSettings
{
    protected $input;
    protected $unregistered = [];

    public function __construct(Grav $grav, InputInterface $input)
    {
        $this->grav = $grav;
        $this->input = $input;
        $folder = $this->cliDefault() ? false : $grav['locator']->findResource('environment://', true);
        parent::__construct($grav, $folder ?: null);
        $this->ignoreCliEnvironment();
    }

    public static function sides(InputInterface $input): array
    {
        $site = (bool)$input->getOption('site');
        $admin = (bool)$input->getOption('admin');
        if ($site === $admin) {
            return ['site', 'admin'];
        }

        return $site ? ['site'] : ['admin'];
    }

    public function get(string $key, $default = null)
    {
        return $this->grav['config']->get('plugins.login-oauth2.' . $key, $default);
    }

    public function missingEnvironment(InputInterface $input): ?string
    {
        // Only an --env given on the command line: Grav's default environment is "cli".
        if (!$input->hasParameterOption('--env') || $this->grav['locator']->findResource('environment://', true)) {
            return null;
        }

        return sprintf("Environment '%s' has no folder: nothing changed.", $input->getOption('env'));
    }

    public function environment(): ?string
    {
        return $this->reachesEveryEnvironment() ? null : (string)$this->grav['setup']->get('environment');
    }

    public function registeredProviders(string $side): array
    {
        try {
            $providers = parent::registeredProviders($side);
        } catch (\Throwable $e) {
            $this->unregistered[$side] = $e->getMessage();
            $providers = [];
        }
        $config = (array)$this->grav['config']->get('plugins.login-oauth2');

        return OAuth2::enabledProviders($side === 'admin', $config) + array_diff_key($providers, array_flip($this->providers()));
    }

    public function providersFailed(): ?string
    {
        foreach (['site', 'admin'] as $side) {
            $this->registeredProviders($side);
        }

        return $this->unregistered ? implode('; ', array_unique($this->unregistered)) : null;
    }

    public function files(): array
    {
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

        return array_values($found);
    }

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

    public function stillOn(string $key): array
    {
        $main = $this->mainFile();
        $on = ($this->read($main, $key) ?? Utils::getDotNotation($this->pluginDefaults('login-oauth2'), $key)) ? [Folder::getRelativePath($main)] : [];
        foreach ($this->files() as $file) {
            if ($file !== $main && !$this->cliFolder(dirname($file, 3)) && $this->read($file, $key)) {
                $on[] = Folder::getRelativePath($file);
            }
        }

        return $on;
    }

    public function overriding(string $key, bool $on): ?string
    {
        $override = $this->overrides['plugins.login-oauth2.' . $key] ?? null;

        return $override !== null && (bool)$override[1] === $on ? $override[0] : null;
    }

    public function mainValue(string $key)
    {
        return $this->read($this->mainFile(), $key) ?? Utils::getDotNotation($this->pluginDefaults('login-oauth2'), $key);
    }

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
            $inherited = $main ? Utils::getDotNotation($this->pluginDefaults('login-oauth2'), $key) : $this->mainValue($key);
            if ($previous === $value || ($previous === null && $inherited === $value)) {
                continue;
            }
            Utils::setDotNotation($content, $key, $value);
            if (!isset($this->overrides['plugins.login-oauth2.' . $key])) {
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

    public function activeProviders(string $side, ?array $registered = null): array
    {
        return OAuth2::configured($registered ?? $this->registeredProviders($side));
    }

    public function notApplying(string $side, ?array $providers = null): ?string
    {
        $config = (array)($this->grav['config']->get('plugins.login-oauth2') ?? []);

        return static::whyNotApplying($side, $config, $providers ?? $this->registeredProviders($side));
    }

    public static function requirementState(array $where): string
    {
        if (!$where) {
            return 'off';
        }

        $enforced = in_array(true, $where, true);

        return sprintf('%s (%s): %s', $enforced ? '<error>on</error>' : 'on', implode(', ', array_keys($where)), $enforced ? 'users can still log in without OAuth2' : 'turn it off first');
    }

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

    public function defines(string $side, string $provider, string $setting): bool
    {
        return Utils::getDotNotation($this->pluginDefaults('login-oauth2'), static::key($side, "providers.{$provider}.{$setting}")) !== null;
    }

    protected function cliDefault(): bool
    {
        return !$this->input->hasParameterOption('--env')
            && $this->grav['setup']->get('environment') === 'cli'
            && static::setting('GRAV_ENVIRONMENT') === null
            && static::setting('GRAV_ENVIRONMENT_PATH') === null;
    }

    protected function ignoreCliEnvironment(): void
    {
        $folder = $this->cliDefault() ? $this->grav['locator']->findResource('environment://', true) : false;
        if (!$folder || !is_file($this->configFile('login-oauth2', $folder))) {
            return;
        }

        $config = $this->grav['config'];
        $config->set('plugins.login-oauth2', $this->pluginConfig('login-oauth2'));
    }

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
