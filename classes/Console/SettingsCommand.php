<?php

namespace Grav\Plugin\Login\OAuth2\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** What the commands share: the side options, their settings and the state of "Enforce OAuth2". */
abstract class SettingsCommand extends ConsoleCommand
{
    /** Without a side option, commands apply to both sides. */
    protected function addSideOptions(): self
    {
        return $this
            ->addOption('site', null, InputOption::VALUE_NONE, 'Only the site')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Only the admin');
    }

    /** Initialize the plugins for their providers; recovery ("off") works without them. */
    protected function settings(SymfonyStyle $io, bool $plugins = true): ?Settings
    {
        try {
            $plugins ? $this->initializePlugins() : $this->initializeGrav();
        } catch (Throwable $e) {
            $this->initializeGrav();
            $io->warning('Could not initialize the plugins, so providers of other plugins are missing: ' . $e->getMessage());
        }

        $grav = Grav::instance();
        // Grav's default environment is "cli": only an --env given on the command line needs its folder.
        if ($this->input->hasParameterOption('--env') && !$grav['locator']->findResource('environment://', true)) {
            $io->error(sprintf("Environment '%s' has no folder: nothing changed.", $this->input->getOption('env')));

            return null;
        }
        $settings = new Settings($grav, $this->input);
        if ($plugins) {
            $settings->providers('site');
            $settings->providers('admin');
        }
        if ($settings->providersFailed !== null) {
            $io->warning([
                'Could not load the providers of other plugins, so they are missing here: ' . $settings->providersFailed,
                "While that lasts, an admin 'Enforce OAuth2' without an active provider of this plugin stays in effect in Admin2.",
            ]);
        }

        return $settings;
    }

    protected function warnUnreadable(SymfonyStyle $io, Settings $settings): void
    {
        if ($unreadable = $settings->unreadable()) {
            $io->warning(array_merge(["Can't read these config files, so their settings are unknown:"], $unreadable));
        }
    }

    /**
     * A side's "Enforce OAuth2": its state with the files that turn it on, and the settings it requires off by name.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    protected static function enforcement(Settings $settings, string $side): array
    {
        $in = $settings->enforcedIn($side);
        if (!$settings->get(Settings::key($side, 'enforce_oauth2'))) {
            $state = 'off' . ($in ? ', but on in ' . implode(', ', $in) : '');
        } else {
            $why = $settings->notApplying($side);
            $state = 'on' . ($why ? ", but doesn't apply: {$why}" : '') . ' (' . implode(', ', $in) . ')';
        }
        $requirements = [];
        foreach ($settings->requirements($side) as $name => $where) {
            $requirements[$name] = $where
                ? sprintf('on (%s): %s', implode(', ', $where), $in ? 'users can still log in without OAuth2' : 'turn it off first')
                : 'off';
        }

        return [$state, $requirements];
    }
}
