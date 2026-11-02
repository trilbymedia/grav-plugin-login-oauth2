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
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Turn it on even if it wouldn\'t apply yet (no active provider)')
            ->setDescription("Turns 'Enforce OAuth2' on or off, e.g. when locked out")
            ->setHelp(
                "The <info>enforce-oauth2</info> command turns 'Enforce OAuth2' (only OAuth2 logins) on or off, for the site and the admin.\n\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 off</info>            locked out: password logins work again on both sides\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 on --admin</info>     only OAuth2 logins in the admin\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 status</info>\n\n" .
                "<info>off</info> turns it off in the main configuration and in every environment that turns it on (or only in --env).\n" .
                "<info>on</info> refuses a side without an active provider, as it wouldn't apply there (unless --force)."
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

        $settings = new Settings(Grav::instance());
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
     * Turns it off in every config file that turns it on, or only in the environment's file with --env.
     *
     * @param string[] $sides
     */
    protected function off(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        $env = $this->input->hasParameterOption('--env');
        $changes = [];
        foreach ($env ? [$settings->file()] : $settings->files() as $file) {
            $values = [];
            foreach ($sides as $side) {
                $key = Settings::key($side, 'enforce_oauth2');
                // With --env also where only the main configuration turns it on: the environment's file overrides it.
                if ($env ? $settings->get($key) : $settings->read($file, $key)) {
                    $values[$key] = false;
                }
            }
            if ($values && ($change = $settings->save($file, $values, 'turned Enforce OAuth2 off'))) {
                $changes[] = $change;
            }
        }

        if (!$changes) {
            $io->success(sprintf("'Enforce OAuth2' is already off (%s).", implode(', ', $sides)));

            return 0;
        }

        foreach ($changes as $change) {
            $io->text('  ' . $change . ' => off');
        }
        $io->success(sprintf(
            "'Enforce OAuth2' is off (%s%s): logins with a password work again.",
            implode(', ', $sides),
            $env ? ', environment ' . $this->input->getOption('env') : ''
        ));

        return 0;
    }

    /**
     * Turns it on in the config file (of the environment with --env), only for sides where it applies, unless --force.
     *
     * @param string[] $sides
     */
    protected function on(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        $force = (bool)$this->input->getOption('force');
        $values = [];
        $refused = [];
        foreach ($sides as $side) {
            if (!$force && ($why = $settings->notApplying($side))) {
                $refused[] = ucfirst($side) . ": {$why}, so it wouldn't apply.";
            }
            $values[Settings::key($side, 'enforce_oauth2')] = true;
        }
        if ($refused) {
            $io->error(array_merge($refused, ['Nothing changed. Enable and configure a provider first, or use --force.']));

            return 1;
        }

        $change = $settings->save($settings->file(), $values, 'turned Enforce OAuth2 on');
        if (!$change) {
            $io->success(sprintf("'Enforce OAuth2' is already on (%s).", implode(', ', $sides)));

            return 0;
        }

        $io->text('  ' . $change . ' => on');
        $io->success(sprintf("'Enforce OAuth2' is on (%s): only OAuth2 logins work. To undo: bin/plugin login-oauth2 enforce-oauth2 off", implode(', ', $sides)));

        return 0;
    }
}
