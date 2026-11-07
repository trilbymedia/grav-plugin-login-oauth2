<?php

namespace Grav\Plugin\Console;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Login\OAuth2\Console\Settings;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * bin/plugin login-oauth2 enforce-oauth2 on|off|status: the "Enforce OAuth2" setting of the site and the admin.
 */
class OAuth2EnforceCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('enforce-oauth2')
            ->addArgument('action', InputArgument::REQUIRED, 'on, off or status')
            ->addOption('site', null, InputOption::VALUE_NONE, 'Only the site')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Only the admin')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Turn it on even if it wouldn\'t apply yet (no active provider) or a setting it requires off is on')
            ->setDescription("Turns 'Enforce OAuth2' on or off, e.g. when locked out")
            ->setHelp(
                "The <info>enforce-oauth2</info> command turns 'Enforce OAuth2' (only OAuth2 logins) on or off, for the site and the admin.\n\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 off</info>            locked out: password logins work again on both sides\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 on --admin</info>     only OAuth2 logins in the admin\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 status</info>\n\n" .
                "<info>off</info> turns it off in the main configuration and in every environment that turns it on (or only in --env).\n" .
                "<info>on</info> only turns it on where it applies (an active provider) and the settings that let users in without OAuth2\n" .
                "are off: the Login plugin's 'Remember me', and for the admin the API plugin's Session Authentication. A side chosen\n" .
                "with --site or --admin is refused otherwise, of both sides the other one is left as it is. --force turns it on anyway.\n" .
                "<info>status</info> shows both sides, why one doesn't apply, and those settings."
            );
    }

    protected function serve()
    {
        include __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $action = strtolower((string)$this->input->getArgument('action'));
        if (!in_array($action, ['on', 'off', 'status'], true)) {
            $io->error("Unknown action '{$action}': use on, off or status.");

            return 1;
        }

        if ($action === 'off') {
            // The way back in when locked out: without the plugins, so it also works if one of them fails.
            $this->initializeGrav();
        } else {
            // With the plugins: satellite plugins (e.g. login-oauth2-extras) add their providers.
            try {
                $this->initializePlugins();
            } catch (Throwable $e) {
                $this->initializeGrav();
                $io->warning('The plugins could not be initialized, providers of other plugins are missing: ' . $e->getMessage());
            }
        }

        $settings = new Settings(Grav::instance(), $this->input);
        $sides = Settings::sides($this->input);
        if ($error = $settings->missingEnvironment($this->input)) {
            $io->error($error);

            return 1;
        }

        if ($action === 'status') {
            return $this->status($io, $settings, $sides);
        }

        return $action === 'off' ? $this->off($io, $settings, $sides) : $this->on($io, $settings, $sides);
    }

    /**
     * @param string[] $sides
     */
    protected function status(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        $files = $settings->files();
        foreach ($sides as $side) {
            $key = Settings::key($side, 'enforce_oauth2');
            $on = (bool)$settings->get($key);
            $why = $on ? $settings->notApplying($side) : null;
            $io->text(sprintf('%s: <info>%s</info>%s', ucfirst($side), $on ? 'on' : 'off', $why ? ", but doesn't apply: {$why}" : ''));
            foreach ($settings->requirements($side) as $requirement => $met) {
                $io->text(sprintf('  %s: %s', $requirement, $met ? 'off' : ($on ? '<error>on</error>, must be off: users can still log in without OAuth2' : 'on, must be off to turn it on')));
            }

            foreach ($files as $file) {
                $value = $settings->read($file, $key);
                if ($value !== null) {
                    $io->text(sprintf('  %s: %s', Folder::getRelativePath($file), $value ? 'on' : 'off'));
                }
            }
        }

        return 0;
    }

    /**
     * Turns it off in every config file that turns it on, or only in the environment's file with --env. Without --env
     * it then checks the files again: the main one (with the plugin's default) and those of the environments.
     *
     * @param string[] $sides
     */
    protected function off(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        $env = $this->input->hasParameterOption('--env');
        $main = $settings->mainFile();
        $changes = [];
        foreach ($env ? [$settings->file()] : array_unique(array_merge([$main], $settings->files())) as $file) {
            $values = [];
            foreach ($sides as $side) {
                $key = Settings::key($side, 'enforce_oauth2');
                // With --env also where only the main configuration turns it on: the environment's file overrides it.
                // In the main file also where only the plugin's default turns it on (an edited login-oauth2.yaml).
                if ($env ? $settings->get($key) : ($file === $main ? $settings->mainValue($key) : $settings->read($file, $key))) {
                    $values[$key] = false;
                }
            }
            if ($values && ($change = $settings->save($file, $values, 'turned Enforce OAuth2 off'))) {
                $changes[] = $change;
            }
        }

        foreach ($changes as $change) {
            $io->text('  ' . $change . ' => off');
        }
        $stillOn = [];
        foreach ($env ? [] : $sides as $side) {
            $key = Settings::key($side, 'enforce_oauth2');
            foreach ($settings->stillOn($key) as $file) {
                $stillOn[] = "{$file}: {$key}";
            }
        }
        if ($stillOn) {
            $io->error(array_merge(["'Enforce OAuth2' is still on in:"], $stillOn, ["Set it to false there by hand, then check with 'bin/plugin login-oauth2 enforce-oauth2 status'."]));

            return 1;
        }

        if (!$changes) {
            $io->success(sprintf("'Enforce OAuth2' is already off (%s).", implode(', ', $sides)));

            return 0;
        }

        $io->success(sprintf(
            "'Enforce OAuth2' is off (%s%s): logins with a password work again.",
            implode(', ', $sides),
            $env ? ', environment ' . $this->input->getOption('env') : ''
        ));

        return 0;
    }

    /**
     * Why a side isn't turned on: settings it requires off that are on, and why it wouldn't apply.
     */
    protected function reasons(?string $why, ?string $requirements): string
    {
        $reasons = [];
        if ($requirements) {
            $reasons[] = "{$requirements} must be off first, otherwise users can still log in without OAuth2.";
        }
        if ($why) {
            $reasons[] = "{$why}, so it wouldn't apply.";
        }

        return implode(' ', $reasons);
    }

    /**
     * Turns it on in the config file (of the environment with --env), only for sides where it applies, unless --force:
     * a side chosen with --site or --admin is refused then, of both sides only the ones where it applies are turned on.
     * Names the environments whose files override the change.
     *
     * @param string[] $sides
     */
    protected function on(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        $force = (bool)$this->input->getOption('force');
        $values = [];
        $skipped = [];
        $notApplying = [];
        $unmet = [];
        foreach ($sides as $side) {
            $why = $settings->notApplying($side);
            $requirements = $settings->unmetRequirements($side);
            if (($why || $requirements) && !$force) {
                $skipped[$side] = $this->reasons($why, $requirements);
                continue;
            }
            $values[Settings::key($side, 'enforce_oauth2')] = true;
            if ($why) {
                $notApplying[$side] = $why;
            }
            if ($requirements) {
                $unmet[$side] = $requirements;
            }
        }
        // Every side skipped: a side chosen with --site or --admin, or both.
        if (!$values) {
            $refused = [];
            foreach ($skipped as $side => $reasons) {
                $refused[] = ucfirst($side) . ': ' . $reasons;
            }
            $refused[] = 'Nothing changed. Fix that first, or add --force to turn it on anyway'
                . (count($sides) === 1 ? '' : ' (with --site or --admin for one side only)') . '.';
            $io->error($refused);

            return 1;
        }

        $file = $settings->file();
        $on = array_values(array_diff($sides, array_keys($skipped)));
        $change = $settings->save($file, $values, 'turned Enforce OAuth2 on');
        if ($change) {
            $io->text('  ' . $change . ' => on');
        }
        foreach ($skipped as $side => $reasons) {
            $io->note(ucfirst($side) . ': ' . $reasons . ' Left as it is.');
        }
        foreach ($notApplying as $side => $why) {
            $io->warning(ucfirst($side) . ": it's on, but doesn't apply yet: {$why}.");
        }
        foreach ($unmet as $side => $requirements) {
            $io->warning(ucfirst($side) . ": it's on, but users can still log in without OAuth2 while these are on: {$requirements}.");
        }
        if ($overrides = $settings->overrides($file, $values)) {
            $io->warning(array_merge(['These environments override it (change them with --env=<environment>):'], $overrides));
        }

        $applying = array_values(array_diff($on, array_keys($notApplying), array_keys($unmet)));
        if (!$change) {
            $io->success(sprintf("'Enforce OAuth2' is already on (%s).", implode(', ', $on)));

            return 0;
        }
        if ($applying) {
            $io->note("It doesn't sign anyone out: open sessions, 'remember me' cookies and Admin2's tokens keep working until they expire. "
                . (method_exists('Grav\Plugin\Login\Login', 'signOutEverywhere') ? 'To end them: bin/plugin login sign-out --all' : "To end them, see 'Enforce OAuth2' in the README."));
        }
        $io->success(sprintf(
            "'Enforce OAuth2' is on (%s)%s. To undo: bin/plugin login-oauth2 enforce-oauth2 off",
            implode(', ', $on),
            $applying ? ': only OAuth2 logins work' . ($applying !== $on ? ' (' . implode(', ', $applying) . ')' : '') . ($overrides ? ', except in the environments above' : '') : ''
        ));

        return 0;
    }
}
