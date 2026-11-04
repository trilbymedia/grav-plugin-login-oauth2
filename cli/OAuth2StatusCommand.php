<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Login\OAuth2\Console\Settings;
use Grav\Plugin\Login\OAuth2\OAuth2;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * bin/plugin login-oauth2 status: shows the login settings of the site and the admin.
 */
class OAuth2StatusCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('status')
            ->addOption('site', null, InputOption::VALUE_NONE, 'Only the site')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Only the admin')
            ->setDescription('Shows the login settings: Enforce OAuth2, the providers and their state')
            ->setHelp(
                "The <info>status</info> command shows, for the site and the admin, whether 'Enforce OAuth2' is on and applies, every provider\n" .
                "(active, or why not) with 'Verify Certificate' where it has it, and the account settings. It changes nothing.\n\n" .
                "  <info>bin/plugin login-oauth2 status</info>\n" .
                "  <info>bin/plugin login-oauth2 status --admin</info>"
            );
    }

    protected function serve()
    {
        include __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        try {
            $this->initializePlugins();
        } catch (Throwable $e) {
            // E.g. the Login plugin is disabled: then the satellite plugins can't add their providers.
            $this->initializeGrav();
            $io->warning('The plugins could not be initialized, providers of other plugins are missing: ' . $e->getMessage());
        }

        $settings = new Settings(Grav::instance(), $this->input);
        $onOff = static function ($value): string {
            return $value ? 'on' : 'off';
        };

        $io->text(sprintf(
            'Login OAuth2: <info>%s</info>, Login plugin: <info>%s</info>',
            $onOff($settings->get('enabled')),
            $onOff(Grav::instance()['config']->get('plugins.login.enabled'))
        ));

        foreach (Settings::sides($this->input) as $side) {
            $registered = $settings->registeredProviders($side);
            $active = $settings->activeProviders($side, $registered);
            $oauth2 = new OAuth2($side === 'admin');
            // The admin follows the site's account settings until set itself.
            $follows = $side === 'admin' ? ' (follows the site)' : '';
            $why = $settings->notApplying($side, $registered);
            $io->section(ucfirst($side));

            $list = [];
            if ($side === 'admin') {
                $list[] = ['Enabled in Admin' => $onOff($settings->get('admin.enabled'))];
            }
            $list[] = ['Enforce OAuth2' => !$settings->get(Settings::key($side, 'enforce_oauth2')) ? 'off' : ($why ? "on, but doesn't apply: {$why}" : 'on')];
            $list[] = ['Require Existing Grav user' => $onOff($oauth2->requireGravUser()) . ($settings->get(Settings::key($side, 'require_grav_user')) === null ? $follows : '')];
            $list[] = ['Save Grav user' => $onOff($oauth2->saveGravUser()) . ($settings->get(Settings::key($side, 'save_grav_user')) === null ? $follows : '')];
            $io->definitionList(...$list);

            $rows = [];
            foreach ($settings->providers() as $provider) {
                $verify = '';
                if ($settings->defines($side, $provider, 'verify_peer')) {
                    $verify = $settings->get(Settings::key($side, "providers.{$provider}.verify_peer"), true) ? 'on' : 'off';
                }
                $reason = isset($active[$provider]) ? null : $settings->inactiveReason($side, $provider);
                $rows[] = [$provider, $reason === null ? 'on' : ($reason === 'disabled' ? 'off' : "on, but {$reason}"), $verify];
                unset($registered[$provider]);
            }
            // Added by satellite plugins as soon as they're enabled: active with their client ID.
            foreach (array_keys($registered) as $provider) {
                $rows[] = [$provider, isset($active[$provider]) ? 'on (other plugin)' : 'on, but missing client_id (other plugin)', ''];
            }
            $io->table(['Provider', 'State', 'Verify Certificate'], $rows);
        }

        return 0;
    }
}
