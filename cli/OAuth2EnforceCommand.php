<?php

namespace Grav\Plugin\Console;

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
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Turn it on even without an active provider or while a required setting is still on')
            ->addOption('sign-out', null, InputOption::VALUE_NONE, 'With on: also sign out everyone (site) or the accounts with admin access (admin only)')
            ->setDescription("Turns 'Enforce OAuth2' on or off, e.g. when locked out")
            ->setHelp(
                "<info>enforce-oauth2</info> turns 'Enforce OAuth2' (only OAuth2 logins) on or off, for the site and the admin.\n\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 off</info>            locked out? Password logins work again\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 on --admin</info>     only OAuth2 logins in the admin\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 on --sign-out</info>  and sign out everyone who is logged in\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 status</info>\n\n" .
                "<info>off</info> turns it off in the main configuration and in every environment that turns it on (with --env only\n" .
                "there), and fails if it's still on afterwards.\n\n" .
                "<info>on</info> needs an active provider and the settings it requires off: the Login plugin's 'Remember me', and for\n" .
                "the admin the API plugin's Session Authentication, in the main configuration and every environment (with --env\n" .
                "only there). Without --site or --admin, only the side that is ready is turned on. --force turns it on anyway.\n\n" .
                "<info>--sign-out</info> also signs out everyone (site) or the accounts with admin access (admin only), in every\n" .
                "environment. It needs Login 3.13.0 or newer."
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
        if ($action === 'on') {
            return $this->on($io, $settings, $sides);
        }
        if ($this->input->getOption('sign-out')) {
            $io->note("--sign-out only works with 'on'. Nobody was signed out.");
        }

        return $action === 'off' ? $this->off($io, $settings, $sides) : $this->status($io, $settings, $sides);
    }

    /**
     * @param string[] $sides
     */
    protected function status(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        foreach ($sides as $side) {
            [$state, $requirements] = static::enforcement($settings, $side);
            $io->text(ucfirst($side) . ": <info>{$state}</info>");
            foreach ($requirements as $name => $requirement) {
                $io->text("  {$name}: {$requirement}");
            }
        }
        $this->warnUnreadable($io, $settings);

        return 0;
    }

    /**
     * Turns it off in every file that turns it on, then checks them again: it never says "off" while one still has it on.
     *
     * @param string[] $sides
     */
    protected function off(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        $changed = false;
        foreach ($settings->files() as $file) {
            $values = [];
            foreach ($sides as $side) {
                $key = Settings::key($side, 'enforce_oauth2');
                // The first file also turns off what it only inherits: the plugin's default, or with --env the main file.
                if ($file === $settings->target() ? $settings->get($key) : $settings->read($file, $key)) {
                    $values[$key] = false;
                }
            }
            if ($values && ($change = $settings->save($file, $values, 'turned Enforce OAuth2 off'))) {
                $io->text("  {$change} => off");
                $changed = true;
            }
        }

        $stillOn = [];
        foreach ($sides as $side) {
            foreach ($settings->enforcedIn($side) as $where) {
                $stillOn[] = ucfirst($side) . ': ' . $where;
            }
        }
        // E.g. invalid YAML: whether it turns it on there is unknown, and it wasn't changed.
        $unreadable = $settings->unreadable();
        if ($stillOn || $unreadable) {
            $io->error(array_merge(
                $stillOn ? ["'Enforce OAuth2' is still on, so password logins don't work yet:"] : [],
                $stillOn,
                $unreadable ? ["Can't read these config files, so 'Enforce OAuth2' may still be on there:"] : [],
                $unreadable,
                ["Fix that by hand, then run 'bin/plugin login-oauth2 enforce-oauth2 status' to check."]
            ));

            return 1;
        }

        $where = implode(', ', $sides) . ($this->input->hasParameterOption('--env') ? ', environment ' . $this->input->getOption('env') : '');
        $io->success($changed ? "'Enforce OAuth2' is off ({$where}). Password logins work again." : "'Enforce OAuth2' is already off ({$where}).");

        return 0;
    }

    /**
     * Turns on the sides that are ready, or with --force all of them. Refusals come before anything changes.
     *
     * @param string[] $sides
     */
    protected function on(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        $force = (bool)$this->input->getOption('force');
        $signOut = (bool)$this->input->getOption('sign-out');
        $values = $skipped = $warnings = [];
        foreach ($sides as $side) {
            $key = Settings::key($side, 'enforce_oauth2');
            $unmet = [];
            foreach (array_filter($settings->requirements($side)) as $name => $where) {
                $unmet[] = $name . ' (' . implode(', ', $where) . ')';
            }
            $unmet = OAuth2::andList($unmet);
            $why = $settings->notApplying($side);
            $variable = $settings->get($key) ? null : $settings->variable($key);
            if ($variable !== null) {
                $skipped[$side] = "the environment variable {$variable} turns it off and overrides every config file.";
            } elseif (($unmet || $why) && !$force) {
                $skipped[$side] = ($unmet ? "turn off {$unmet} first, or users can still log in without OAuth2." : '')
                    . ($why ? ($unmet ? ' It also' : 'it') . " wouldn't apply ({$why})." : '');
            } else {
                $values[$key] = true;
                if ($why) {
                    $warnings[] = ucfirst($side) . ": it's on, but doesn't apply yet: {$why}.";
                }
                if ($unmet) {
                    $warnings[] = ucfirst($side) . ": it's on, but users can still log in without OAuth2 until you turn off {$unmet}.";
                }
            }
        }
        if (!$values) {
            $refused = [];
            foreach ($skipped as $side => $reason) {
                $refused[] = ucfirst($side) . ': ' . $reason;
            }
            $refused[] = 'Nothing changed' . ($signOut ? ', nobody was signed out' : '') . '. Fix that first' . ($force ? '' : ', or add --force to turn it on anyway') . '.';
            $io->error($refused);

            return 1;
        }
        $login = $signOut ? $this->login() : null;
        if ($signOut && !$login) {
            $io->error("--sign-out isn't possible: it needs the Login plugin, 3.13.0 or newer. Nothing changed. See 'Enforce OAuth2' in the README for other ways to sign users out.");

            return 1;
        }

        $on = array_values(array_diff($sides, array_keys($skipped)));
        $change = $settings->save($settings->target(), $values, 'turned Enforce OAuth2 on');
        if ($change) {
            $io->text("  {$change} => on");
        }
        foreach ($skipped as $side => $reason) {
            $io->note(ucfirst($side) . ": {$reason} Left unchanged.");
        }
        $this->warnUnreadable($io, $settings);
        foreach ($warnings as $warning) {
            $io->warning($warning);
        }
        $io->success(sprintf(
            "'Enforce OAuth2' is %son (%s)%s. To undo: bin/plugin login-oauth2 enforce-oauth2 off%s%s",
            $change ? '' : 'already ',
            implode(', ', $on),
            $warnings ? '' : ': only OAuth2 logins work',
            $this->input->hasParameterOption('--env') ? ' --env=' . $this->input->getOption('env') : '',
            count($on) === 1 ? ' --' . $on[0] : ''
        ));

        if ($login) {
            return $this->signOut($io, $login, $on);
        }
        if ($change) {
            $io->note("Nobody was signed out: open sessions, 'remember me' cookies and Admin2 tokens keep working until they expire. To end them, add --sign-out.");
        }

        return 0;
    }

    /** Login 3.13.0 or newer: older versions lack the sign-out methods. */
    protected function login(): ?Login
    {
        $login = Grav::instance()['login'] ?? null;

        return $login instanceof Login && method_exists($login, 'signOutAccounts') && method_exists($login, 'signOutEveryone') && method_exists($login, 'getAccounts') ? $login : null;
    }

    /**
     * Signs out everyone if the site is enforced, else the accounts with admin access. Accounts are shared by all
     * environments.
     *
     * @param string[] $sides
     */
    protected function signOut(SymfonyStyle $io, Login $login, array $sides): int
    {
        $by = 'from the command line: login-oauth2 enforce-oauth2 on --sign-out';
        $everyone = in_array('site', $sides, true);
        if ($everyone) {
            $result = $login->signOutEveryone($by);
        } else {
            $admins = [];
            foreach ($login->getAccounts() as $account) {
                // Disabled accounts too: their saved logins could work again once enabled.
                if (OAuth2::hasAdminAccess($account, true)) {
                    $admins[] = (string)$account->get('username');
                }
            }
            $result = $login->signOutAccounts($admins, $by);
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
}
