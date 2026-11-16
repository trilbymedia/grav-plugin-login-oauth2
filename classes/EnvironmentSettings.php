<?php

namespace Grav\Plugin\Login\OAuth2;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\Yaml;

/** YAML configuration for commands, settings notices and admin saves. Never used by login requests. */
class EnvironmentSettings
{
    protected $grav;
    protected $folder;
    protected $mainConfig;
    protected $defaults = [];
    protected $overrides = [];
    protected $unreadable = [];
    protected $pending = [];

    public function __construct(Grav $grav, ?string $folder = null)
    {
        $this->grav = $grav;
        $this->folder = $folder;
        $vars = $_ENV + $_SERVER;
        if (empty($vars['GRAV_CONFIG']) && !getenv('GRAV_CONFIG')) {
            return;
        }
        $aliases = [];
        foreach ($vars as $name => $value) {
            if (strpos((string)$name, 'GRAV_CONFIG_ALIAS__') === 0) {
                $aliases[substr($name, 19)] = (string)$value;
            }
        }
        foreach (array_keys($vars) as $name) {
            if (strpos((string)$name, 'GRAV_CONFIG__') === 0) {
                $key = str_replace('__', '.', substr($name, 13));
                foreach ($aliases as $alias => $real) {
                    $key = str_replace($alias, $real, $key);
                }
                $this->overrides[$key] = [$name, $grav['config']->get($key)];
            }
        }
    }

    public static function ofSave(Grav $grav, string $file, array $config): ?self
    {
        $settings = new self($grav);
        $main = $settings->mainFile();
        $path = static function (string $file): string {
            return str_replace('\\', '/', (realpath(dirname($file)) ?: dirname($file)) . '/' . basename($file));
        };
        if ($path($file) !== $path($main)) {
            return null;
        }
        $settings->pending[$main] = $config;

        return $settings;
    }

    public static function key(string $side, string $key): string
    {
        return ($side === 'admin' ? 'admin.' : '') . $key;
    }

    public function file(): string
    {
        return $this->configFile('login-oauth2', $this->folder);
    }

    public function mainFile(): string
    {
        return $this->configFile('login-oauth2');
    }

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

    /** Grav has no API to list environments. Exclude folders assigned to other user streams. */
    protected function environmentFolders(): array
    {
        $locator = $this->grav['locator'];
        $user = (string)$locator->findResource('user://', true);
        $current = $locator->findResource('environment://', true);
        $folders = $current ? [$current] : [];
        if (($path = static::setting('GRAV_ENVIRONMENT_PATH')) !== null) {
            $folders[] = $this->path($path);
        } else {
            $root = static::setting('GRAV_ENVIRONMENTS_PATH');
            $root = $root !== null ? $this->path($root) : (is_dir($user . '/env') ? $user . '/env' : $user);
            foreach ($root && is_dir($root) ? scandir($root) : [] as $name) {
                if ($name[0] !== '.' && is_dir($root . '/' . $name)) {
                    $folders[] = $root . '/' . $name;
                }
            }
        }
        $streams = [];
        foreach ((array)$this->grav['setup']->get('streams.schemes', []) as $scheme => $config) {
            if ($scheme === 'environment') {
                continue;
            }
            $prefixes = (array)($config['prefixes'] ?? []);
            $prefixes[] = $config['paths'] ?? [];
            foreach ($prefixes as $paths) {
                foreach ((array)$paths as $path) {
                    if (is_string($path) && strpos($path, 'user://') === 0 && trim(substr($path, 7), '/') !== '') {
                        $real = realpath($user . '/' . substr($path, 7));
                        if ($real !== false) {
                            $streams[] = $real . DIRECTORY_SEPARATOR;
                        }
                    }
                }
            }
        }
        $found = [];
        foreach (array_filter($folders) as $folder) {
            $real = realpath($folder) ?: $folder;
            if ($folder !== $current) {
                foreach ($streams as $stream) {
                    if (strncasecmp($real . DIRECTORY_SEPARATOR, $stream, strlen($stream)) === 0) {
                        continue 2;
                    }
                }
            }
            $found[$real] = $folder;
        }

        return array_values($found);
    }

    public function reachesEveryEnvironment(): bool
    {
        return $this->file() === $this->mainFile() || static::setting('GRAV_ENVIRONMENT_PATH') !== null;
    }

    /** One pass reports applicability and requirements, including environments with their own providers. */
    public function inspect(string $side): array
    {
        $main = $this->file() === $this->mainFile();
        $key = static::key($side, 'enforce_oauth2');
        $path = 'plugins.login-oauth2.' . $key;
        $others = array_diff_key($this->registeredProviders($side), array_flip($this->providers()));
        $folders = $main ? [null] : [$this->folder];
        foreach ($main ? $this->environmentFolders() : [] as $folder) {
            if (is_dir($folder . '/config') && !$this->cliFolder($folder)) {
                $folders[] = $folder;
            }
        }
        $applying = $excluded = [];
        $targetWhy = null;
        foreach ($folders as $folder) {
            $config = $this->pluginConfig('login-oauth2', $folder);
            $why = static::whyNotApplying($side, $config, OAuth2::enabledProviders($side === 'admin', $config) + $others);
            if ($folder === $this->folder) {
                $targetWhy = $why;
            }
            $own = $folder !== null ? $this->read($this->configFile('login-oauth2', $folder), $key) : null;
            if ($main && $folder !== null && !isset($this->overrides[$path]) && $own !== null && !$own) {
                $why = 'turns it off';
            }
            if ($why === null) {
                $applying[] = $folder;
            } elseif ($main && $folder !== null) {
                $excluded[$folder] = $why;
            }
        }
        $requirements = $changed = [];
        foreach ($applying ?: [$this->folder] as $folder) {
            $where = [];
            $get = function (string $path) use ($folder, &$where) {
                [$value, $where[$path]] = $this->value($folder, $path);
                return $value;
            };
            $enforced = $applying && $get($path);
            $own = $main && $folder !== null && $this->read($this->configFile('login-oauth2', $folder), $key) !== null;
            foreach (OAuth2::enforceRequirements($side === 'admin', $get) as $name => $met) {
                $label = OAuth2::requirementNames([$name]);
                $requirements[$label] = $requirements[$label] ?? [];
                if (!$met) {
                    $file = $where[OAuth2::REQUIREMENT_KEYS[$name]];
                    $requirements[$label][$file] = !empty($requirements[$label][$file]) || $enforced;
                    if (!$own) {
                        $changed[$label][$file] = true;
                    }
                }
            }
        }
        $unmet = [];
        foreach ($changed as $label => $files) {
            $unmet[] = $label . ' (' . implode(', ', array_keys($files)) . ')';
        }

        return [
            'why' => $applying ? null : $targetWhy,
            'main' => $main && $applying ? $targetWhy : null,
            'elsewhere' => array_map([Folder::class, 'getRelativePath'], array_values(array_filter($applying, 'is_string'))),
            'excluded' => $excluded,
            'requirements' => $requirements,
            'unmet' => $unmet ? OAuth2::andList($unmet) : null,
        ];
    }

    protected static function whyNotApplying(string $side, array $config, array $providers): ?string
    {
        $why = OAuth2::enforceNotApplying($side === 'admin', $config, $providers);
        return $why === null ? null : OAuth2::notApplyingReason($side === 'admin', $why, true);
    }

    public function registeredProviders(string $side): array
    {
        return OAuth2::registeredFor($side === 'admin');
    }

    /** Value and its source, with environment variables above files and plugin defaults. */
    protected function value(?string $folder, string $path): array
    {
        if (isset($this->overrides[$path])) {
            return [$this->overrides[$path][1], 'environment variable ' . $this->overrides[$path][0]];
        }
        [, $plugin, $key] = explode('.', $path, 3);
        $main = $this->configFile($plugin);
        foreach ($folder !== null ? [$this->configFile($plugin, $folder), $main] : [$main] as $file) {
            $value = $this->read($file, $key);
            if ($value !== null) {
                return [$value, Folder::getRelativePath($file)];
            }
        }
        return [Utils::getDotNotation($this->pluginDefaults($plugin), $key), 'by default; set it in ' . Folder::getRelativePath($main)];
    }

    public function pluginConfig(string $plugin, ?string $folder = null): array
    {
        $config = array_replace_recursive($this->pluginDefaults($plugin), $this->content($this->configFile($plugin)));
        if ($folder !== null) {
            $config = array_replace_recursive($config, $this->content($this->configFile($plugin, $folder)));
        }
        $prefix = 'plugins.' . $plugin . '.';
        foreach ($this->overrides as $path => $override) {
            if (strpos($path, $prefix) === 0) {
                Utils::setDotNotation($config, substr($path, strlen($prefix)), $override[1]);
            }
        }
        return $config;
    }

    public function unreadable(): array
    {
        $errors = [];
        foreach ($this->unreadable as $file => $why) {
            $errors[Folder::getRelativePath($file)] = $why;
        }
        return $errors;
    }

    public function read(string $file, string $key)
    {
        return Utils::getDotNotation($this->content($file), $key);
    }

    public function providers(): array
    {
        return array_keys((array)($this->pluginDefaults('login-oauth2')['providers'] ?? []));
    }

    protected function pluginDefaults(string $plugin): array
    {
        if (!isset($this->defaults[$plugin])) {
            $file = $this->grav['locator']->findResource("plugins://{$plugin}/{$plugin}.yaml");
            $this->defaults[$plugin] = is_string($file) ? $this->content($file) : [];
        }
        return $this->defaults[$plugin];
    }

    /** Read the current file; pending admin data is the only in-memory replacement. */
    protected function content(string $file): array
    {
        if (isset($this->pending[$file])) {
            return $this->pending[$file];
        }
        try {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                return [];
            }
            $yaml = @file_get_contents($file);
            if ($yaml === false) {
                throw new \RuntimeException('Could not read file');
            }
            return (array)Yaml::parse($yaml);
        } catch (\Throwable $e) {
            $this->unreadable[$file] = $e->getMessage();
            return [];
        }
    }

    protected function cliFolder(string $folder): bool
    {
        return basename($folder) === 'cli' && static::setting('GRAV_ENVIRONMENT_PATH') === null;
    }

    protected function path(string $path): ?string
    {
        $locator = $this->grav['locator'];
        if ($locator->isStream($path)) {
            $folder = $locator->findResource($path, true, true);
            return $folder ? rtrim($folder, '/\\') : null;
        }
        $path = rtrim($path, '/\\');
        return preg_match('`^(/|\w+:)`', $path) ? $path : $locator->getBase() . '/' . $path;
    }

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
