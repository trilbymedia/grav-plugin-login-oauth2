<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Plugin\Login\OAuth2\Console\Settings;
use Grav\Plugin\Login\OAuth2\Console\SettingsCommand;
use Grav\Plugin\Login\OAuth2\OAuth2;
use Symfony\Component\Console\Style\SymfonyStyle;

// The plugin's classes, also while the plugin isn't loaded.
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * bin/plugin login-oauth2 status: shows the login settings of the site and the admin.
 */
class OAuth2StatusCommand extends SettingsCommand
{
    protected function configure()
    {
        $this
            ->setName('status')
            ->addSideOptions()
            ->setDescription('Shows the login settings: Enforce OAuth2, the providers and their state')
            ->setHelp(
                "<info>status</info> shows, for the site and the admin, whether 'Enforce OAuth2' is on and applies, each provider\n" .
                "(active, or why not) and the account settings. It changes nothing.\n\n" .
                "  <info>bin/plugin login-oauth2 status</info>\n" .
                "  <info>bin/plugin login-oauth2 status --admin</info>"
            );
    }

    protected function serve()
    {
        $io = new SymfonyStyle($this->input, $this->output);
        $settings = $this->settings($io);
        if (!$settings) {
            return 1;
        }
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
            $key = Settings::key($side, 'enforce_oauth2');
            $enforced = (bool)$settings->get($key);
            // Off here, but environments may turn it on.
            $elsewhere = $enforced ? [] : $settings->stillOn($key);
            // On, but not applying here: with the environments where it does (e.g. with their own provider).
            $list[] = ['Enforce OAuth2' => !$enforced ? 'off' . ($elsewhere ? ' (on in ' . implode(', ', $elsewhere) . ')' : '') : ($why ? "on, but doesn't apply: {$why}" . static::appliesIn($settings, $side) : 'on')];
            // The settings it requires off (they let users in without OAuth2).
            foreach ($settings->requirements($side) as $requirement => $where) {
                $list[] = ['  ' . $requirement => Settings::requirementState($where)];
            }
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
        if ($unreadable = static::unreadable($settings)) {
            $io->warning(array_merge(["Can't read these config files, so their settings are unknown:"], $unreadable));
        }

        return 0;
    }
}
