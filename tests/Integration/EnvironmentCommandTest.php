<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;

final class EnvironmentCommandTest extends SiteTestCase
{
    public function testARequirementOnInAnEnvironmentRefusesWhateverThatEnvironmentSetsItself(): void
    {
        $this->ready();
        static::$site->write('user/env/staging/config/plugins/login-oauth2.yaml', ['enforce_oauth2' => false]);
        static::$site->write('user/env/staging/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--site'], 1, ['Login → Remember me', 'user/env/staging/config/plugins/login.yaml', 'Nothing changed']);
        $this->assertNull($this->enforced());
    }

    public function testStatusNamesTheEnvironmentThatTurnsARequirementOn(): void
    {
        $this->ready(['enforce_oauth2' => true]);
        static::$site->write('user/env/staging/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);

        $this->cli(['enforce-oauth2', 'status', '--site'], 0, [
            'Site: on (user/config/plugins/login-oauth2.yaml)',
            'Login → Remember me: on (user/env/staging/config/plugins/login.yaml): users can still log in without OAuth2',
        ]);
    }

    public function testLegacyDiscoveryExcludesOtherUserStreams(): void
    {
        $this->ready();
        static::$site->write('user/pages/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--site'], 0, ["'Enforce OAuth2' is on"]);
    }

    public function testAnAliasedEnvironmentVariableKeepingItOffCannotBeForced(): void
    {
        $this->ready();
        $variable = 'GRAV_CONFIG__plugins__OAUTH2__enforce_oauth2';

        $this->cli(['enforce-oauth2', 'on', '--site', '--force'], 1, ["environment variable {$variable} turns it off", 'Nothing changed'], [
            'GRAV_CONFIG' => '1',
            'GRAV_CONFIG_ALIAS__OAUTH2' => 'login-oauth2',
            $variable => 'false',
        ]);
        $this->assertNull($this->enforced());
    }

    public function testRecoveryReportsAnEnvironmentVariableStillKeepingItOn(): void
    {
        $this->ready(['enforce_oauth2' => true]);
        $variable = 'GRAV_CONFIG__plugins__login-oauth2__enforce_oauth2';

        $this->cli(['enforce-oauth2', 'off', '--site'], 1, ["'Enforce OAuth2' is still on", "environment variable {$variable}"], [
            'GRAV_CONFIG' => '1',
            $variable => 'true',
        ]);
        $this->assertFalse($this->enforced());
    }
}
