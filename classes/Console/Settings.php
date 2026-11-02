<?php

namespace Grav\Plugin\Login\OAuth2\Console;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Plugin\Login\OAuth2\OAuth2;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The plugin settings for the bin/plugin commands: reads the configuration Grav uses, changes the config files (the
 * main one and those of the environments), and tells the active providers of a side and the settings a provider has.
 */
class Settings
{
    /** @var Grav */
    protected $grav;
    /** @var array|null The plugin's default configuration, read once */
    protected $defaults;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
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
     * The config file changes are saved to: the environment's if it has a folder (--env), else the main one.
     *
     * @return string
     */
    public function file(): string
    {
        return (string)$this->grav['locator']->findResource('config://plugins/login-oauth2.yaml', true, true);
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
     * The plugin's existing config files: the main one and those of all environments (in user/env).
     *
     * @return string[]
     */
    public function files(): array
    {
        // Grav has no API to list its environments.
        $user = (string)$this->grav['locator']->findResource('user://', true);
        $files = array_merge([$user . '/config/plugins/login-oauth2.yaml'], glob($user . '/env/*/config/plugins/login-oauth2.yaml') ?: []);

        return array_values(array_filter($files, 'is_file'));
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
        $yaml = CompiledYamlFile::instance($file);
        $content = (array)$yaml->content();
        $yaml->free();

        return Utils::getDotNotation($content, $key);
    }

    /**
     * Saves settings to a config file, which is created if needed, only those that change: a value not set in the
     * file that Grav already uses (e.g. the plugin's default) is left out. Grav saves the file as YAML again, without
     * comments (as the admin does). Grav's compiled configuration is removed, so the next request reads the file again
     * (Grav only checks for changed config files every few seconds). The configuration of this command gets the
     * values too.
     *
     * @param string $file
     * @param array $values Key => value
     * @param string $what For the log, e.g. "turned Enforce OAuth2 off"
     * @return string|null The change ("<file>: <keys>"), null if nothing changed
     */
    public function save(string $file, array $values, string $what): ?string
    {
        $yaml = CompiledYamlFile::instance($file);
        $content = (array)$yaml->content();
        $changed = [];
        foreach ($values as $key => $value) {
            $previous = Utils::getDotNotation($content, $key);
            if ($previous === $value || ($previous === null && $this->get($key) === $value)) {
                continue;
            }
            Utils::setDotNotation($content, $key, $value);
            $this->grav['config']->set('plugins.login-oauth2.' . $key, $value);
            $changed[] = $key;
        }
        if (!$changed) {
            $yaml->free();

            return null;
        }

        $yaml->save($content);
        $yaml->free();
        foreach (glob($this->grav['locator']->findResource('cache://compiled/config', true, true) . '/*.php') ?: [] as $compiled) {
            @unlink($compiled);
        }

        $change = Folder::getRelativePath($file) . ': ' . implode(', ', $changed);
        $this->grav['log']->notice("Login OAuth2 (bin/plugin): {$what} in {$change}");

        return $change;
    }

    /**
     * The active providers of a side, as its login page shows them: this plugin's (enabled and configured) and the
     * ones satellite plugins (e.g. login-oauth2-extras) add.
     *
     * @param string $side
     * @return array Name => settings
     */
    public function activeProviders(string $side): array
    {
        $oauth2 = new OAuth2($side === 'admin');
        $oauth2->addEnabledProviders();
        // Satellite plugins add theirs on this event, as for the Admin2 login (LoginOauth2Plugin::ensureAdminOAuth2()).
        $this->grav->fireEvent('onOAuth2ProvidersRegister', new Event(['oauth2' => $oauth2]));

        return $oauth2->getProviders();
    }

    /**
     * Why "Enforce OAuth2" doesn't apply on a side although it's on, null if it applies: the same conditions as the
     * plugin's login check (LoginOauth2Plugin::isOAuth2Enforced()).
     *
     * @param string $side
     * @param array|null $active The side's active providers, if known
     * @return string|null
     */
    public function notApplying(string $side, ?array $active = null): ?string
    {
        if (!$this->get('enabled')) {
            return 'the plugin is disabled';
        }
        if ($side === 'admin' && !$this->get('admin.enabled')) {
            return "'Enabled in Admin' is off";
        }

        return ($active ?? $this->activeProviders($side)) ? null : 'no active provider';
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
            $yaml = CompiledYamlFile::instance((string)$this->grav['locator']->findResource('plugins://login-oauth2/login-oauth2.yaml'));
            $this->defaults = (array)$yaml->content();
            $yaml->free();
        }

        return $this->defaults;
    }
}
