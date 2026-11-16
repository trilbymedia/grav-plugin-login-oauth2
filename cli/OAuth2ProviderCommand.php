<?php

namespace Grav\Plugin\Console;

use Grav\Plugin\Login\OAuth2\Console\Settings;
use Grav\Plugin\Login\OAuth2\Console\SettingsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;

// The plugin's classes, also while the plugin isn't loaded.
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * bin/plugin login-oauth2 provider enable|disable <name>, provider verify-certificate <name> [on|off|status].
 */
class OAuth2ProviderCommand extends SettingsCommand
{
    protected function configure()
    {
        $this
            ->setName('provider')
            ->addArgument('action', InputArgument::REQUIRED, 'enable, disable or verify-certificate')
            ->addArgument('name', InputArgument::REQUIRED, 'The provider, e.g. generic')
            ->addArgument('value', InputArgument::OPTIONAL, 'For verify-certificate: on, off or status (the default)')
            ->addSideOptions()
            ->setDescription('Enables or disables a provider, or changes its settings')
            ->setHelp(
                "<info>provider</info> changes one of this plugin's providers, for the site and the admin.\n\n" .
                "  <info>bin/plugin login-oauth2 provider enable generic</info>\n" .
                "  <info>bin/plugin login-oauth2 provider disable github --admin</info>\n" .
                "  <info>bin/plugin login-oauth2 provider verify-certificate generic off --site</info>   only for testing\n" .
                "  <info>bin/plugin login-oauth2 provider verify-certificate generic</info>             shows it\n\n" .
                "verify-certificate is for providers with a 'Verify Certificate' setting (Generic). Providers of other plugins\n" .
                "(e.g. login-oauth2-extras) are changed in their own plugin's settings."
            );
    }

    protected function serve()
    {
        $io = new SymfonyStyle($this->input, $this->output);
        $settings = $this->settings($io);
        if (!$settings) {
            return 1;
        }
        $sides = Settings::sides($this->input);
        $action = strtolower((string)$this->input->getArgument('action'));
        $name = strtolower((string)$this->input->getArgument('name'));

        $providers = $settings->providers();
        if (!in_array($name, $providers, true)) {
            $io->error([
                "Unknown provider '{$name}'. This plugin's providers: " . implode(', ', $providers) . '.',
                "Providers of other plugins (e.g. login-oauth2-extras) are changed in their own plugin's settings.",
            ]);

            return 1;
        }

        $value = $this->input->getArgument('value');
        if ($action === 'enable' || $action === 'disable') {
            if ($value !== null) {
                $io->error("Too many arguments: '{$action}' takes one provider name, not '{$value}'. Nothing changed.");

                return 1;
            }

            return $this->enable($io, $settings, $sides, $name, $action === 'enable');
        }
        if ($action === 'verify-certificate') {
            return $this->verifyCertificate($io, $settings, $sides, $name, strtolower((string)($value ?? 'status')));
        }

        $io->error("Unknown action '{$action}': use enable, disable or verify-certificate.");

        return 1;
    }

    /**
     * @param string[] $sides
     */
    protected function enable(SymfonyStyle $io, Settings $settings, array $sides, string $name, bool $enable): int
    {
        $values = [];
        // Sides where "Enforce OAuth2" is on without applying: a provider enabled there can make it apply.
        $dormant = [];
        foreach ($sides as $side) {
            $values[Settings::key($side, "providers.{$name}.enabled")] = $enable;
            if ($enable && $settings->get(Settings::key($side, 'enforce_oauth2')) && $settings->notApplying($side) !== null) {
                $dormant[] = $side;
            }
        }

        $state = $enable ? 'enabled' : 'disabled';
        $file = $settings->file();
        $change = $settings->save($file, $values, "{$state} provider {$name}");
        if ($change) {
            $io->text('  ' . $change);
            $io->success(sprintf("'%s' is %s (%s).", $name, $state, implode(', ', $sides)));
        } else {
            $io->success(sprintf("'%s' is already %s (%s).", $name, $state, implode(', ', $sides)));
        }
        $this->overrides($io, $settings, $file, $values);

        // What it means for the logins, with the new settings (also if it was already set: e.g. not configured).
        foreach ($sides as $side) {
            $active = $settings->activeProviders($side);
            if ($enable && !isset($active[$name])) {
                $io->note(ucfirst($side) . ": '{$name}' isn't active yet: " . $settings->inactiveReason($side, $name) . '.');
            }
            if ($enable && $side === 'admin' && !$settings->get('admin.enabled')) {
                $io->note("Admin: 'Enabled in Admin' is off, so the admin login doesn't offer it yet.");
            }
            if (!$enable && !$active && $settings->get(Settings::key($side, 'enforce_oauth2'))) {
                $io->note(ucfirst($side) . ": no active provider is left, so 'Enforce OAuth2' no longer applies.");
            }
            // It was turned on before (e.g. with --force) and waited for a provider: from now on it denies the other logins.
            if (in_array($side, $dormant, true) && $settings->notApplying($side) === null) {
                $requirements = $settings->unmetRequirements($side);
                $io->warning(ucfirst($side) . ": 'Enforce OAuth2' is on and applies now that the side has an active provider: only OAuth2 logins work."
                    . ($requirements ? " Users can still log in without OAuth2 until you turn off {$requirements}." : ''));
            }
        }

        return 0;
    }

    /**
     * @param string[] $sides
     */
    protected function verifyCertificate(SymfonyStyle $io, Settings $settings, array $sides, string $name, string $value): int
    {
        foreach ($sides as $side) {
            if (!$settings->defines($side, $name, 'verify_peer')) {
                $io->error("'{$name}' has no 'Verify Certificate' setting" . (count($sides) > 1 ? '' : " ({$side})") . '.');

                return 1;
            }
        }

        if ($value === 'status') {
            $current = [];
            foreach ($sides as $side) {
                $key = Settings::key($side, "providers.{$name}.verify_peer");
                $current[$key] = (bool)$settings->get($key, true);
                $io->text(sprintf("%s: 'Verify Certificate' of '%s' is <info>%s</info>", ucfirst($side), $name, Settings::show($current[$key])));
            }
            $this->overrides($io, $settings, $settings->file(), $current);

            return 0;
        }
        if ($value !== 'on' && $value !== 'off') {
            $io->error("Unknown value '{$value}': use on, off or status.");

            return 1;
        }

        $values = [];
        foreach ($sides as $side) {
            $values[Settings::key($side, "providers.{$name}.verify_peer")] = $value === 'on';
        }
        $file = $settings->file();
        $change = $settings->save($file, $values, "turned Verify Certificate {$value} for provider {$name}");
        if (!$change) {
            $io->success(sprintf("'Verify Certificate' of '%s' is already %s (%s).", $name, $value, implode(', ', $sides)));
            $this->overrides($io, $settings, $file, $values);

            return 0;
        }
        $io->text('  ' . $change);
        $io->success(sprintf("'Verify Certificate' of '%s' is %s (%s).", $name, $value, implode(', ', $sides)));
        $this->overrides($io, $settings, $file, $values);
        if ($value === 'off') {
            $io->warning('For testing only: without this check, anyone who can intercept the connection to the provider can read the client secret and tokens, and log in as any user.');
        }

        return 0;
    }
}
