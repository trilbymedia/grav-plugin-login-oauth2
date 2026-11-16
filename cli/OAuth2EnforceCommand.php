<?php

namespace Grav\Plugin\Console;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Plugin\Login\Login;
use Grav\Plugin\Login\OAuth2\Console\Settings;
use Grav\Plugin\Login\OAuth2\Console\SettingsCommand;
use Grav\Plugin\Login\OAuth2\OAuth2;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

// The plugin's classes, also while the plugin isn't loaded.
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * bin/plugin login-oauth2 enforce-oauth2 on|off|status: the "Enforce OAuth2" setting of the site and the admin.
 */
class OAuth2EnforceCommand extends SettingsCommand
{
    protected function configure()
    {
        $this
            ->setName('enforce-oauth2')
            ->addArgument('action', InputArgument::REQUIRED, 'on, off or status')
            ->addSideOptions()
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Turn it on even if it wouldn\'t apply yet or a required setting is still on')
            ->addOption('sign-out', null, InputOption::VALUE_NONE, 'With on: also sign out everyone (site) or the accounts with admin access (admin only)')
            ->addOption('sign-out-all-environments', null, InputOption::VALUE_NONE, 'Like --sign-out, even if that signs out users of environments where it doesn\'t apply')
            ->setDescription("Turns 'Enforce OAuth2' on or off, e.g. when locked out")
            ->setHelp(
                "<info>enforce-oauth2</info> turns 'Enforce OAuth2' (only OAuth2 logins) on or off, for the site and the admin.\n\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 off</info>            locked out? Password logins work again\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 on --admin</info>     only OAuth2 logins in the admin\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 on --sign-out</info>  and sign out everyone who is logged in\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 status</info>\n\n" .
                "<info>off</info> turns it off in the main configuration and in every environment that turns it on (with --env only\n" .
                "there). It fails if it's still on afterwards: a config file can't be read, or a GRAV_CONFIG__ environment\n" .
                "variable turns it on.\n\n" .
                "<info>on</info> turns it on if the side has an active provider and the settings it requires are off: the Login\n" .
                "plugin's 'Remember me', and for the admin the API plugin's Session Authentication. They are checked in the main\n" .
                "configuration and every environment (with --env only there). If not, a side chosen with --site or --admin is\n" .
                "refused; without them, only the side that is ready is turned on. --force turns it on anyway, except against a\n" .
                "GRAV_CONFIG__ environment variable that turns it off.\n\n" .
                "<info>--sign-out</info> also signs out everyone (site) or the accounts with admin access (admin only), even if it was\n" .
                "on already. It needs Login 3.13.0 or newer. Accounts are shared by all environments, so it's refused if it would\n" .
                "also sign out users of an environment where 'Enforce OAuth2' doesn't apply.\n" .
                "<info>--sign-out-all-environments</info> signs them out as well and names the environments.\n\n" .
                "<info>status</info> shows both sides, the settings they require off, and why a side doesn't apply."
            );
    }

    protected function serve()
    {
        $io = new SymfonyStyle($this->input, $this->output);
        $action = strtolower((string)$this->input->getArgument('action'));
        if (!in_array($action, ['on', 'off', 'status'], true)) {
            $io->error("Unknown action '{$action}': use on, off or status.");

            return 1;
        }

        // "off" is the way back in when locked out: without the plugins, so it also works if one of them fails.
        $settings = $this->settings($io, $action !== 'off');
        if (!$settings) {
            return 1;
        }
        $sides = Settings::sides($this->input);

        if ($action !== 'on' && ($option = $this->signOutOption())) {
            $io->note("{$option} only works with 'on'. Nobody was signed out.");
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
            $report = $settings->inspect($side);
            $key = Settings::key($side, 'enforce_oauth2');
            $io->text(ucfirst($side) . ': <info>' . static::enforcementState($settings, $side, $report) . '</info>');
            foreach ($report['requirements'] as $requirement => $where) {
                $io->text(sprintf('  %s: %s', $requirement, Settings::requirementState($where)));
            }

            foreach ($files as $file) {
                $value = $settings->read($file, $key);
                if ($value !== null) {
                    $io->text(sprintf('  %s: %s', Folder::getRelativePath($file), $value ? 'on' : 'off'));
                }
            }
        }
        if ($unreadable = static::unreadable($settings)) {
            $io->warning(array_merge(["Can't read these config files, so their settings are unknown:"], $unreadable));
        }

        return 0;
    }

    /** Disable every file that enables enforcement, then verify the files and environment variables. */
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
        $stillOn = $variables = [];
        foreach ($sides as $side) {
            $key = Settings::key($side, 'enforce_oauth2');
            foreach ($env ? [] : $settings->stillOn($key) as $file) {
                $stillOn[] = "{$file}: {$key}";
            }
            if ($variable = $settings->overriding($key, true)) {
                $variables[] = "environment variable {$variable}";
            }
        }
        // E.g. invalid YAML: whether it turns it on there is unknown, and it wasn't changed.
        $unreadable = static::unreadable($settings);
        if ($unreadable) {
            $io->error(array_merge(
                [($changes ? 'Turned off in the files above. ' : '') . "Can't read these config files, so 'Enforce OAuth2' may still be on there:"],
                $unreadable,
                ["Fix them by hand, then run 'bin/plugin login-oauth2 enforce-oauth2 status' to check."]
            ));
        }
        if ($stillOn || $variables) {
            $fix = [];
            if ($stillOn) {
                $fix[] = 'Set it to false in these files by hand.';
            }
            if ($variables) {
                $fix[] = "The variable overrides every config file: remove it or set it to false where it's defined (.env file, web server or container).";
            }
            $fix[] = "Then run 'bin/plugin login-oauth2 enforce-oauth2 status' to check.";
            $io->error(array_merge(["'Enforce OAuth2' is still on, so password logins don't work yet:"], $stillOn, $variables, [implode(' ', $fix)]));

            return 1;
        }

        if ($unreadable) {
            return 1;
        }

        if (!$changes) {
            $io->success(sprintf("'Enforce OAuth2' is already off (%s).", implode(', ', $sides)));

            return 0;
        }

        $io->success(sprintf(
            "'Enforce OAuth2' is off (%s%s). Password logins work again.",
            implode(', ', $sides),
            $env ? ', environment ' . $this->input->getOption('env') : ''
        ));

        return 0;
    }

    /** Explain why enabling enforcement was refused. */
    protected function reasons(?string $why, ?string $requirements): string
    {
        $reasons = [];
        if ($requirements) {
            $reasons[] = "turn off {$requirements} first, or users can still log in without OAuth2.";
        }
        if ($why) {
            $reasons[] = ($requirements ? 'It also' : 'it') . " wouldn't apply ({$why}).";
        }

        return implode(' ', $reasons);
    }

    /** Enable ready sides, reporting requirements and sign-out scope before making changes. */
    protected function on(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        $force = (bool)$this->input->getOption('force');
        $values = [];
        $skipped = [];
        $notApplying = [];
        $unmet = [];
        // Sides a GRAV_CONFIG__ variable keeps off above every config file: no change of a file turns them on.
        $keptOff = [];
        $reports = [];
        foreach ($sides as $side) {
            if ($variable = $settings->overriding(Settings::key($side, 'enforce_oauth2'), false)) {
                $skipped[$side] = $keptOff[$side] = "the environment variable {$variable} turns it off and overrides every config file: remove it or set it to true where it's defined (.env file, web server or container).";
                continue;
            }
            $reports[$side] = $report = $settings->inspect($side);
            $why = $report['why'];
            $requirements = $report['unmet'];
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
            $refused[] = 'Nothing changed' . ($this->signOutOption() ? ', nobody was signed out' : '')
                . '. Fix that first' . (count($keptOff) === count($skipped) ? '' : ', or add --force to turn it on anyway'
                . (count($sides) === 1 ? '' : ' (with --site or --admin for one side only)')) . '.';
            $io->error($refused);

            return 1;
        }

        $on = array_values(array_diff($sides, array_keys($skipped)));
        // Where it applies: elsewhere password logins still work, so signing out wouldn't make anyone use OAuth2.
        $signOutSides = array_values(array_diff($on, array_keys($notApplying)));
        $applying = array_values(array_diff($signOutSides, array_keys($unmet)));
        $option = $this->signOutOption();
        $login = $this->login();
        // Before anything changes: a sign-out needs the Login plugin's, and --sign-out only signs out the users it's for.
        if ($option && $signOutSides) {
            if (!$login) {
                $io->error("{$option} isn't possible: {$this->signOutUnavailable()}. Nothing changed. See 'Enforce OAuth2' in the README for other ways to sign users out.");

                return 1;
            }
            if ($option === '--sign-out' && ($beyond = $this->signedOutBeyond($settings, $signOutSides))) {
                $whom = array_shift($beyond);
                $io->error(array_merge(
                    ["--sign-out would also sign out {$whom}. Nothing changed."],
                    $beyond,
                    ['To sign them out too, use --sign-out-all-environments.']
                ));

                return 1;
            }
        }

        $file = $settings->file();
        $change = $settings->save($file, $values, 'turned Enforce OAuth2 on', $changed);
        if ($change) {
            $io->text('  ' . $change . ' => on');
        }
        foreach ($skipped as $side => $reasons) {
            $io->note(ucfirst($side) . ': ' . $reasons . ' Left unchanged.');
        }
        if ($unreadable = static::unreadable($settings)) {
            $io->warning(array_merge(["Can't read these config files, so their environments weren't checked:"], $unreadable));
        }
        foreach ($notApplying as $side => $why) {
            $io->warning(ucfirst($side) . ": it's on, but doesn't apply yet: {$why}.");
        }
        $mainOnly = false;
        foreach ($on as $side) {
            $report = $reports[$side];
            if ($why = $report['main']) {
                $mainOnly = true;
                $io->note(ucfirst($side) . ": it doesn't apply in the main configuration ({$why}), so password logins still work there" . static::appliesIn($report) . '.');
            }
        }
        foreach ($unmet as $side => $requirements) {
            $io->warning(ucfirst($side) . ": it's on, but users can still log in without OAuth2 until you turn off {$requirements}.");
        }
        $overrides = $this->overrides($io, $settings, $file, $values);

        if (!$change) {
            $io->success(sprintf("'Enforce OAuth2' is already on (%s).", implode(', ', $on)));
        } else {
            if ($applying && !$option) {
                if (!$login) {
                    $end = "To end them, see 'Enforce OAuth2' in the README.";
                } elseif ($this->signedOutBeyond($settings, $signOutSides)) {
                    $end = 'To end them, add --sign-out-all-environments (--sign-out would be refused here; run it to see why).';
                } else {
                    $end = 'To end them, add --sign-out.';
                }
                $io->note("Nobody was signed out: open sessions, 'remember me' cookies and Admin2 tokens keep working until they expire. {$end}");
            }
            $io->success(sprintf(
                "'Enforce OAuth2' is on (%s)%s. To undo: %s",
                implode(', ', $on),
                $applying ? ': only OAuth2 logins work' . ($applying !== $on ? ' (' . implode(', ', $applying) . ')' : '') . ($overrides || $mainOnly ? ', except as noted above' : '') : '',
                $this->undo($settings, (array)$changed)
            ));
        }

        if (!$option) {
            return 0;
        }
        if ($signOutSides && $login) {
            // Only with --sign-out-all-environments: --sign-out was refused above.
            if ($beyond = $this->signedOutBeyond($settings, $signOutSides)) {
                $whom = array_shift($beyond);
                $io->warning(array_merge(["{$option} also signs out {$whom}."], $beyond));
            }

            return $this->signOut($io, $login, $signOutSides);
        }
        $io->note("Nobody was signed out: 'Enforce OAuth2' doesn't apply yet, so password logins still work.");

        return 0;
    }

    /** Undo only the sides and environment changed by this command. */
    protected function undo(Settings $settings, array $changed): string
    {
        $command = 'bin/plugin login-oauth2 enforce-oauth2 off';
        if (($environment = $settings->environment()) !== null) {
            $command .= ' --env=' . $environment;
        }
        $sides = array_filter(['site', 'admin'], static function (string $side) use ($changed) {
            return in_array(Settings::key($side, 'enforce_oauth2'), $changed, true);
        });

        return $command . (count($sides) === 1 ? ' --' . reset($sides) : '');
    }

    /** The broader sign-out option includes --sign-out. */
    protected function signOutOption(): ?string
    {
        if ($this->input->getOption('sign-out-all-environments')) {
            return '--sign-out-all-environments';
        }

        return $this->input->getOption('sign-out') ? '--sign-out' : null;
    }

    /** Accounts are shared: report users signed out beyond the environments using enforcement. */
    protected function signedOutBeyond(Settings $settings, array $sides): ?array
    {
        $shared = 'because accounts are shared by all environments';
        if (!$settings->reachesEveryEnvironment()) {
            return ["users of every other environment, {$shared} (this only changes {$settings->environmentSource()})"];
        }

        $side = in_array('site', $sides, true) ? 'site' : 'admin';
        $where = [];
        $report = $settings->inspect($side);
        if ($why = $report['main']) {
            $where[] = 'main configuration: ' . $why;
        }
        foreach ($report['excluded'] as $folder => $why) {
            $where[] = Folder::getRelativePath($folder) . ': ' . $why;
        }

        return $where ? array_merge(["users where 'Enforce OAuth2' ({$side}) doesn't apply, {$shared}"], $where) : null;
    }

    /** Explain why Login cannot sign accounts out. */
    protected function signOutUnavailable(): string
    {
        return isset(Grav::instance()['login'])
            ? 'it needs Login 3.13.0 or newer'
            : "the Login plugin isn't loaded";
    }

    /** Older Login versions lack the sign-out methods. */
    protected function login(): ?Login
    {
        $grav = Grav::instance();
        $login = isset($grav['login']) ? $grav['login'] : null;

        return $login instanceof Login && method_exists($login, 'signOutAccounts') && method_exists($login, 'signOutEveryone') && method_exists($login, 'getAccounts') ? $login : null;
    }

    /** Sign out everyone for site enforcement, or accounts with admin access for admin enforcement. */
    protected function signOut(SymfonyStyle $io, Login $login, array $sides): int
    {
        $by = 'from the command line: login-oauth2 enforce-oauth2 on ' . $this->signOutOption();
        $everyone = in_array('site', $sides, true);
        if ($everyone) {
            $result = $login->signOutEveryone($by);
        } else {
            $result = $login->signOutAccounts($this->adminAccounts($login), $by);
            foreach ($result['signed_out'] as $username) {
                $io->text(sprintf('  Signed out "%s"', $username));
            }
        }

        $failed = 0;
        foreach ($result['failed'] as $failure) {
            $io->error(sprintf('Could not sign out "%s": %s', $failure['username'], $failure['error']));
            $failed++;
        }
        // Listed with admin access, but gone when Login read it again (e.g. removed meanwhile).
        foreach ($result['missing'] ?? [] as $username) {
            $io->error(sprintf('Could not sign out "%s": the account can no longer be read.', $username));
            $failed++;
        }
        $count = count($result['signed_out']);
        if ($count) {
            // Signed-in web processes may hold the accounts in Grav's cache (Flex accounts).
            $this->invalidateCache();
        }
        if ($everyone && empty($result['sessions_ended'])) {
            $io->error("Could not end the sessions of users without an account file: user/data/login/sign-out-all.yaml can't be written (see the Grav log).");
            $failed++;
        }

        if (!$everyone && !$count && !$failed) {
            $io->note('Nobody was signed out: no account has admin access.');

            return 0;
        }
        $message = sprintf(
            '%sSigned out %d account%s%s everywhere%s',
            $everyone && !empty($result['sessions_ended']) ? 'Every open session ends on its next request. ' : '',
            $count,
            $count === 1 ? '' : 's',
            $everyone ? '' : ' with admin access',
            $failed ? ', but not all of them (see above).' : ': they log in again with OAuth2.'
        );
        $failed ? $io->warning($message) : $io->success($message);
        $io->note('API keys keep working: revoke them in Admin2 if needed.');

        return $failed ? 1 : 0;
    }

    /** Include disabled accounts, whose saved credentials could work again after enabling them. */
    protected function adminAccounts(Login $login): array
    {
        $admins = [];
        foreach ($login->getAccounts() as $account) {
            if (OAuth2::hasAdminAccess($account, true)) {
                $admins[] = (string)$account->get('username');
            }
        }

        return $admins;
    }
}
