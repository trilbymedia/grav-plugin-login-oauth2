<?php

namespace Grav\Plugin\Login\OAuth2\Console;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\Yaml;
use Grav\Plugin\Login\OAuth2\OAuth2;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;

/** The settings as the commands read and write them: YAML files of the main configuration and the environments. */
class Settings
{
    /** Grav's own folders in user/, which aren't environments in the legacy layout. */
    private const USER_FOLDERS = ['accounts', 'blueprints', 'config', 'data', 'images', 'languages', 'media', 'pages', 'plugins', 'themes'];

    protected $grav;
    /** @var string|null Environment folder the command changes; null for the main configuration */
    protected $folder;
    /** @var array GRAV_CONFIG__ variables: config path => [name, value] */
    protected $variables = [];
    protected $contents = [];
    protected $unreadable = [];
    /** @var string|null Why the providers of other plugins are missing */
    public $providersFailed;

    public function __construct(Grav $grav, InputInterface $input)
    {
        $this->grav = $grav;
        // Without --env or GRAV_ENVIRONMENT, Grav runs commands in "cli", which no web request uses.
        if ($input->hasParameterOption('--env') || $grav['setup']->get('environment') !== 'cli') {
            $this->folder = $grav['locator']->findResource('environment://', true) ?: null;
        }
        // These variables override every config file (InitializeProcessor::initializeConfig()).
        $vars = $_ENV + $_SERVER;
        foreach (!empty($vars['GRAV_CONFIG']) || getenv('GRAV_CONFIG') ? $vars : [] as $name => $value) {
            if (strpos((string)$name, 'GRAV_CONFIG__') !== 0) {
                continue;
            }
            $path = str_replace('__', '.', substr($name, 13));
            foreach ($vars as $alias => $real) {
                if (strpos((string)$alias, 'GRAV_CONFIG_ALIAS__') === 0) {
                    $path = str_replace(substr($alias, 19), (string)$real, $path);
                }
            }
            $this->variables[$path] = [$name, $grav['config']->get($path)];
        }
    }

    public static function sides(InputInterface $input): array
    {
        $site = (bool)$input->getOption('site');

        return $site === (bool)$input->getOption('admin') ? ['site', 'admin'] : [$site ? 'site' : 'admin'];
    }

    public static function key(string $side, string $key): string
    {
        return ($side === 'admin' ? 'admin.' : '') . $key;
    }

    /** A plugin's config file in an environment folder, or in the main configuration (null). */
    public function file(?string $folder, string $plugin = 'login-oauth2'): string
    {
        return ($folder ?? $this->grav['locator']->findResource('user://', true)) . "/config/plugins/{$plugin}.yaml";
    }

    /** The file the command changes. */
    public function target(): string
    {
        return $this->file($this->folder);
    }

    /** The plugin's files that can turn a setting on for web requests, the target first. */
    public function files(): array
    {
        $files = [$this->target()];
        foreach ($this->folder === null ? $this->environments(true) : [] as $folder) {
            $files[] = $this->file($folder);
        }

        return $files;
    }

    /** Environment folders of the website. Grav has no API to list them. "cli" is only used by commands. */
    protected function environments(bool $cli = false): array
    {
        $locator = $this->grav['locator'];
        if (static::setting('GRAV_ENVIRONMENT_PATH') !== null) {
            return array_filter([$locator->findResource('environment://', true)]);
        }
        $user = $locator->findResource('user://', true);
        $root = static::setting('GRAV_ENVIRONMENTS_PATH');
        if ($root === null) {
            $root = is_dir($user . '/env') ? $user . '/env' : $user;
        } elseif ($locator->isStream($root)) {
            $root = (string)$locator->findResource($root, true);
        } elseif (!preg_match('`^(/|\w+:)`', $root)) {
            $root = GRAV_ROOT . '/' . $root;
        }
        $folders = [];
        foreach (glob(rtrim($root, '/\\') . '/*/config', GLOB_ONLYDIR) ?: [] as $config) {
            $name = basename(dirname($config));
            if (($cli || $name !== 'cli') && ($root !== $user || !in_array($name, self::USER_FOLDERS, true))) {
                $folders[] = dirname($config);
            }
        }

        return $folders;
    }

    /** A constant or environment variable of Grav's setup. */
    protected static function setting(string $name): ?string
    {
        $value = defined($name) ? constant($name) : ($_SERVER[$name] ?? $_ENV[$name] ?? getenv($name));

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function content(string $file): array
    {
        if (!isset($this->contents[$file])) {
            $this->contents[$file] = [];
            try {
                if (is_file($file)) {
                    $yaml = @file_get_contents($file);
                    if ($yaml === false) {
                        throw new RuntimeException('Could not read file');
                    }
                    $this->contents[$file] = (array)Yaml::parse($yaml);
                }
            } catch (\Throwable $e) {
                $this->unreadable[$file] = $e->getMessage();
            }
        }

        return $this->contents[$file];
    }

    /** Config files that can't be read, e.g. invalid YAML: "file: why". */
    public function unreadable(): array
    {
        $lines = [];
        foreach ($this->unreadable as $file => $why) {
            $lines[] = Folder::getRelativePath($file) . ': ' . $why;
        }

        return $lines;
    }

    /** A setting as the file itself has it. */
    public function read(string $file, string $key)
    {
        return Utils::getDotNotation($this->content($file), $key);
    }

    protected function defaults(string $plugin = 'login-oauth2'): array
    {
        $file = $this->grav['locator']->findResource("plugins://{$plugin}/{$plugin}.yaml");

        return is_string($file) ? $this->content($file) : [];
    }

    /** A plugin's settings as web requests get them: of an environment folder, or of the main configuration (null). */
    protected function config(string $plugin, ?string $folder): array
    {
        $config = array_replace_recursive(
            $this->defaults($plugin),
            $this->content($this->file(null, $plugin)),
            $folder !== null ? $this->content($this->file($folder, $plugin)) : []
        );
        foreach ($this->variables as $path => $variable) {
            if (strpos($path, "plugins.{$plugin}.") === 0) {
                Utils::setDotNotation($config, substr($path, strlen($plugin) + 9), $variable[1]);
            }
        }

        return $config;
    }

    /** Where web requests get a setting from. */
    protected function source(string $path, ?string $folder): string
    {
        if (isset($this->variables[$path])) {
            return 'environment variable ' . $this->variables[$path][0];
        }
        [, $plugin, $key] = explode('.', $path, 3);
        $main = $this->file(null, $plugin);
        foreach ($folder !== null ? [$this->file($folder, $plugin), $main] : [$main] as $file) {
            if ($this->read($file, $key) !== null) {
                return Folder::getRelativePath($file);
            }
        }

        return 'by default; set it in ' . Folder::getRelativePath($main);
    }

    /** A setting of this plugin in the configuration the command changes. */
    public function get(string $key, $default = null)
    {
        return Utils::getDotNotation($this->config('login-oauth2', $this->folder), $key, $default);
    }

    /** The GRAV_CONFIG__ variable that sets a setting of this plugin. */
    public function variable(string $key): ?string
    {
        return $this->variables['plugins.login-oauth2.' . $key][0] ?? null;
    }

    /** Where a side's "Enforce OAuth2" is on: for the main configuration also the environments that turn it on. */
    public function enforcedIn(string $side): array
    {
        $key = static::key($side, 'enforce_oauth2');
        $in = $this->get($key) ? [$this->source('plugins.login-oauth2.' . $key, $this->folder)] : [];
        foreach (array_slice($this->files(), 1) as $file) {
            if ($this->read($file, $key)) {
                $in[] = Folder::getRelativePath($file);
            }
        }

        return $in;
    }

    /**
     * The settings a side requires off: name => where it's on (empty: off). The main configuration counts with every
     * environment of the website, as they inherit "Enforce OAuth2" from it.
     */
    public function requirements(string $side): array
    {
        $on = [];
        foreach ($this->folder !== null ? [$this->folder] : array_merge([null], $this->environments()) as $folder) {
            $get = function (string $path) use ($folder) {
                [, $plugin, $key] = explode('.', $path, 3);

                return Utils::getDotNotation($this->config($plugin, $folder), $key);
            };
            foreach (OAuth2::enforceRequirements($side === 'admin', $get) as $name => $met) {
                $label = OAuth2::requirementNames([$name]);
                $on[$label] = $on[$label] ?? [];
                if (!$met) {
                    $on[$label][$this->source(OAuth2::REQUIREMENT_KEYS[$name], $folder)] = true;
                }
            }
        }

        return array_map('array_keys', $on);
    }

    /** A side's enabled providers, with the ones of other plugins unless they fail to register. */
    public function providers(string $side): array
    {
        try {
            return OAuth2::registeredFor($side === 'admin');
        } catch (\Throwable $e) {
            $this->providersFailed = $e->getMessage();

            return OAuth2::enabledProviders($side === 'admin', (array)$this->grav['config']->get('plugins.login-oauth2'));
        }
    }

    /** Why a side's "Enforce OAuth2" wouldn't apply, in the commands' words. */
    public function notApplying(string $side): ?string
    {
        $admin = $side === 'admin';
        $why = OAuth2::enforceNotApplying($admin, $this->config('login-oauth2', $this->folder), $this->providers($side));

        return $why === null ? null : OAuth2::notApplyingReason($admin, $why, true);
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

    /** Names of this plugin's providers. */
    public function ownProviders(): array
    {
        return array_keys((array)($this->defaults()['providers'] ?? []));
    }

    /** Whether a provider of this plugin has a setting, e.g. verify_peer. */
    public function defines(string $side, string $provider, string $setting): bool
    {
        return Utils::getDotNotation($this->defaults(), static::key($side, "providers.{$provider}.{$setting}")) !== null;
    }

    /** Writes the values a file doesn't have yet, itself or inherited. Returns "file: keys", null if nothing changed. */
    public function save(string $file, array $values, string $what): ?string
    {
        $content = $this->content($file);
        $relative = Folder::getRelativePath($file);
        // Saving would replace it with these settings alone.
        if (isset($this->unreadable[$file])) {
            throw new RuntimeException("Can't read {$relative}, so it wasn't changed: {$this->unreadable[$file]}");
        }
        $inherited = $file === $this->file(null) ? $this->defaults() : $this->config('login-oauth2', null);
        $changed = [];
        foreach ($values as $key => $value) {
            if ((Utils::getDotNotation($content, $key) ?? Utils::getDotNotation($inherited, $key)) === $value) {
                continue;
            }
            Utils::setDotNotation($content, $key, $value);
            if ($this->variable($key) === null) {
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
        unset($this->contents[$file]);
        // The compiled configuration of web requests.
        $compiled = $this->grav['locator']->findResource('cache://compiled/config', true, true);
        array_map('unlink', glob($compiled . '/*.php') ?: []);

        $change = $relative . ': ' . implode(', ', $changed);
        $this->grav['log']->notice("Login OAuth2 (bin/plugin): {$what} in {$change}");

        return $change;
    }
}
