<?php

namespace Grav\Plugin\Login\OAuth2\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** Shared CLI initialization, options and reporting. */
abstract class SettingsCommand extends ConsoleCommand
{
    /** Without a side option, commands apply to both sides. */
    protected function addSideOptions(): self
    {
        return $this
            ->addOption('site', null, InputOption::VALUE_NONE, 'Only the site')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Only the admin');
    }

    /** Initialize plugins when needed; recovery commands work without them. */
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

    /** Format unreadable config files for CLI messages. */
    protected static function unreadable(Settings $settings): array
    {
        $lines = [];
        foreach ($settings->unreadable() as $file => $why) {
            $lines[] = "{$file}: {$why}";
        }

        return $lines;
    }

    protected static function appliesIn(array $report): string
    {
        return $report['elsewhere'] ? ' (it applies in ' . implode(', ', $report['elsewhere']) . ')' : '';
    }

    protected static function enforcementState(Settings $settings, string $side, array $report): string
    {
        if (!$settings->get(Settings::key($side, 'enforce_oauth2'))) {
            return 'off';
        }
        $why = $settings->notApplying($side);

        return $why ? "on, but doesn't apply: {$why}" . static::appliesIn($report) : 'on';
    }

    /** Save and report provider changes consistently. */
    protected function saveProvider(SymfonyStyle $io, Settings $settings, array $sides, array $values, string $what, string $subject, string $state): bool
    {
        $file = $settings->file();
        $change = $settings->save($file, $values, $what);
        if ($change) {
            $io->text('  ' . $change);
        }
        $io->success(sprintf('%s is %s%s (%s).', $subject, $change ? '' : 'already ', $state, implode(', ', $sides)));
        $this->overrides($io, $settings, $file, $values);

        return (bool)$change;
    }

    /** Report environments overriding a main-file change. */
    protected function overrides(SymfonyStyle $io, Settings $settings, string $file, array $values): bool
    {
        $overrides = $settings->overrides($file, $values);
        if ($overrides) {
            $io->warning(array_merge(['These environments override it (change them with --env=<environment>):'], $overrides));
        }

        return (bool)$overrides;
    }
}
