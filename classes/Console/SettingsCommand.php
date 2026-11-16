<?php

namespace Grav\Plugin\Login\OAuth2\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * What the bin/plugin login-oauth2 commands share: the --site and --admin options, how they start (Grav, the plugins,
 * the settings, the check of --env) and the wording of what several of them report.
 */
abstract class SettingsCommand extends ConsoleCommand
{
    /**
     * Adds --site and --admin: without them, or with both, a command applies to both sides (Settings::sides()).
     *
     * @return $this
     */
    protected function addSideOptions(): self
    {
        return $this
            ->addOption('site', null, InputOption::VALUE_NONE, 'Only the site')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Only the admin');
    }

    /**
     * Starts Grav and reads the settings. With the plugins, so satellite plugins (e.g. login-oauth2-extras) add their
     * providers; without them if they can't be initialized (e.g. the Login plugin is disabled), with a warning, or if
     * the command doesn't want them ($plugins). Also with a warning if one of them fails while adding its providers
     * (Settings::providersFailed()). Null, after the error, for an --env that has no folder: Grav would use the main
     * configuration instead (Settings::missingEnvironment()).
     *
     * @param SymfonyStyle $io
     * @param bool $plugins
     * @return Settings|null
     */
    protected function settings(SymfonyStyle $io, bool $plugins = true): ?Settings
    {
        if ($plugins) {
            try {
                $this->initializePlugins();
            } catch (Throwable $e) {
                $this->initializeGrav();
                $io->warning('Could not initialize the plugins, so providers of other plugins are missing: ' . $e->getMessage());
            }
        } else {
            $this->initializeGrav();
        }

        $settings = new Settings(Grav::instance(), $this->input);
        if ($error = $settings->missingEnvironment($this->input)) {
            $io->error($error);

            return null;
        }
        // A plugin that adds providers and fails while doing so: the command goes on without its providers.
        if ($plugins && ($error = $settings->providersFailed()) !== null) {
            $io->warning([
                'Could not load the providers of other plugins, so they are missing here: ' . $error,
                "While that lasts, an admin 'Enforce OAuth2' without an active provider of this plugin stays in effect in Admin2.",
            ]);
        }

        return $settings;
    }

    /**
     * The plugin's config files that can't be read (Settings::unreadable()), for the messages: "<file>: <why>".
     *
     * @return string[]
     */
    protected static function unreadable(Settings $settings): array
    {
        $lines = [];
        foreach ($settings->unreadable() as $file => $why) {
            $lines[] = "{$file}: {$why}";
        }

        return $lines;
    }

    /**
     * For the messages of a side that doesn't apply in the main configuration: the environments where it does
     * (Settings::appliesIn()), e.g. " (it applies in user/env/example.com)"; empty if none.
     */
    protected static function appliesIn(Settings $settings, string $side): string
    {
        $folders = $settings->appliesIn($side);

        return $folders ? ' (it applies in ' . implode(', ', $folders) . ')' : '';
    }

    /**
     * Warns about the environments whose config files override a change of the main file (Settings::overrides()).
     *
     * @param array $values Key => value
     * @return bool Whether any does
     */
    protected function overrides(SymfonyStyle $io, Settings $settings, string $file, array $values): bool
    {
        $overrides = $settings->overrides($file, $values);
        if ($overrides) {
            $io->warning(array_merge(['These environments override it (change them with --env=<environment>):'], $overrides));
        }

        return (bool)$overrides;
    }
}
