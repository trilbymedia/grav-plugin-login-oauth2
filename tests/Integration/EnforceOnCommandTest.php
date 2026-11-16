<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;

/**
 * `bin/plugin login-oauth2 enforce-oauth2 on`: when it refuses, what it writes and where.
 */
final class EnforceOnCommandTest extends SiteTestCase
{
    public function testTurnsOnTheSiteInTheMainFile(): void
    {
        $this->ready();

        $this->cli(['enforce-oauth2', 'on', '--site'], 0, ["'Enforce OAuth2' is on (site)", 'only OAuth2 logins work', 'user/config/plugins/login-oauth2.yaml']);

        $this->assertTrue($this->enforced('site'));
        $this->assertNull($this->enforced('admin'));
    }

    public function testTurnsOnTheAdminInTheMainFile(): void
    {
        $this->ready();

        $this->cli(['enforce-oauth2', 'on', '--admin'], 0, ["'Enforce OAuth2' is on (admin)"]);

        $this->assertTrue($this->enforced('admin'));
        $this->assertNull($this->enforced('site'));
    }

    public function testSayWhenItIsOnAlready(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        $this->cli(['enforce-oauth2', 'on', '--site'], 0, ["'Enforce OAuth2' is already on (site)"]);
    }

    public function testWithoutSideOptionsOnlyTheSideThatIsReadyIsTurnedOn(): void
    {
        // "Enabled in Admin" is off: the admin wouldn't apply.
        $this->ready(['admin' => ['enabled' => false]]);

        $this->cli(['enforce-oauth2', 'on'], 0, ['Admin:', "'Enabled in Admin' is off", 'Left unchanged', "'Enforce OAuth2' is on (site)"]);

        $this->assertTrue($this->enforced('site'));
        $this->assertNull($this->enforced('admin'));
    }

    public function testUnknownActionIsAnError(): void
    {
        $this->ready();

        $this->cli(['enforce-oauth2', 'maybe'], 1, ["Unknown action 'maybe': use on, off or status."]);
    }

    // -- No active provider ---------------------------------------------------------------------------------------

    public function testSiteIsRefusedWithoutAnActiveProvider(): void
    {
        $this->ready(['providers' => ['generic' => ['client_id' => '']]]);

        $this->cli(['enforce-oauth2', 'on', '--site'], 1, ['Site:', 'no active provider', 'Nothing changed', 'add --force']);

        $this->assertNull($this->enforced('site'));
    }

    public function testAdminIsRefusedWithoutAnActiveProvider(): void
    {
        $this->ready(['admin' => ['providers' => ['generic' => ['client_id' => '']]]]);

        $this->cli(['enforce-oauth2', 'on', '--admin'], 1, ['Admin:', 'no active provider', 'Nothing changed']);

        $this->assertNull($this->enforced('admin'));
    }

    public function testAdminIsRefusedWhileEnabledInAdminIsOff(): void
    {
        $this->ready(['admin' => ['enabled' => false]]);

        $this->cli(['enforce-oauth2', 'on', '--admin'], 1, ["'Enabled in Admin' is off", 'Nothing changed']);

        $this->assertNull($this->enforced('admin'));
    }

    public function testForceWritesItAnywayAndWarnsThatItDoesNotApplyYet(): void
    {
        $this->ready(['providers' => ['generic' => ['client_id' => '']]]);

        $this->cli(['enforce-oauth2', 'on', '--site', '--force'], 0, ["it's on, but doesn't apply yet", 'no active provider', "'Enforce OAuth2' is on (site)"]);

        $this->assertTrue($this->enforced('site'));
    }

    // -- Settings that must be off --------------------------------------------------------------------------------

    public function testSiteIsRefusedWhileRememberMeIsOnInTheMainConfig(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--site'], 1, ['Login → Remember me', 'user/config/plugins/login.yaml', 'Nothing changed']);

        $this->assertNull($this->enforced('site'));
    }

    public function testRememberMeIsOnByDefaultWhenNothingSetsIt(): void
    {
        $this->ready([], null);

        $this->cli(['enforce-oauth2', 'on', '--site'], 1, ['Login → Remember me', 'by default']);

        $this->assertNull($this->enforced('site'));
    }

    public function testForceTurnsItOnWhileRememberMeIsOnAndWarns(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--site', '--force'], 0, ['users can still log in without OAuth2 until you turn off Login → Remember me']);

        $this->assertTrue($this->enforced('site'));
    }

    public function testRememberMeOnlyInAnEnvironmentFileRefuses(): void
    {
        $this->ready();
        static::$site->write('user/env/example.com/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--site'], 1, ['Login → Remember me', 'user/env/example.com/config/plugins/login.yaml', 'Nothing changed']);

        $this->assertNull($this->enforced('site'));
    }

    public function testRememberMeOnlyInALegacyEnvironmentFolderRefuses(): void
    {
        $this->ready();
        static::$site->write('user/example.com/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--site'], 1, ['Login → Remember me', 'user/example.com/config/plugins/login.yaml']);

        $this->assertNull($this->enforced('site'));
    }

    public function testALegacyEnvironmentFolderIsIgnoredWhileUserEnvExists(): void
    {
        // As Grav's Setup: the folders in user/ are only environments without user/env.
        $this->ready();
        static::$site->write('user/example.com/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);
        mkdir(static::$site->path('user/env'));

        $this->cli(['enforce-oauth2', 'on', '--site'], 0, ["'Enforce OAuth2' is on (site)"]);

        $this->assertTrue($this->enforced('site'));
    }

    public function testEnvironmentFolderOfGravEnvironmentsPathIsFound(): void
    {
        $this->ready();
        static::$site->write('custom-envs/example.com/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);
        $env = ['GRAV_ENVIRONMENTS_PATH' => static::$site->path('custom-envs')];

        $this->cli(['enforce-oauth2', 'on', '--site'], 1, ['Login → Remember me', 'custom-envs/example.com/config/plugins/login.yaml'], $env);
        $this->assertNull($this->enforced('site'));

        // Nothing points at that folder without the variable.
        $this->cli(['enforce-oauth2', 'on', '--site'], 0, ["'Enforce OAuth2' is on (site)"]);
    }

    public function testWithEnvOnlyThatEnvironmentIsChecked(): void
    {
        $this->ready();
        mkdir(static::$site->path('user/env/staging/config'), 0777, true);
        static::$site->write('user/env/other.example/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);

        // Another environment's Remember me doesn't reach staging.
        $this->cli(['enforce-oauth2', 'on', '--site', '--env=staging'], 0, ["'Enforce OAuth2' is on (site)"]);
        $this->assertTrue($this->enforced('site', 'user/env/staging/config/plugins/login-oauth2.yaml'));

        // The main configuration's does: staging inherits it.
        static::$site->write(static::LOGIN, ['rememberme' => ['enabled' => true]]);
        $this->cli(['enforce-oauth2', 'on', '--site', '--env=staging', '--force'], 0);
        static::$site->write('user/env/staging/config/plugins/login-oauth2.yaml', ['debug' => true]);
        $this->cli(['enforce-oauth2', 'on', '--site', '--env=staging'], 1, ['Login → Remember me', 'user/config/plugins/login.yaml']);
        $this->assertNull($this->enforced('site', 'user/env/staging/config/plugins/login-oauth2.yaml'));
    }

    public function testTheCliEnvironmentTurningRememberMeOffDoesNotMakeItPass(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => true]]);
        static::$site->write('user/env/cli/config/plugins/login.yaml', ['rememberme' => ['enabled' => false]]);

        $this->cli(['enforce-oauth2', 'on', '--site'], 1, ['Login → Remember me', 'user/config/plugins/login.yaml']);

        $this->assertNull($this->enforced('site'));
    }

    public function testTheCliEnvironmentTurningRememberMeOnDoesNotRefuse(): void
    {
        // Web requests never use the "cli" environment.
        $this->ready();
        static::$site->write('user/env/cli/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--site'], 0, ["'Enforce OAuth2' is on (site)"]);

        $this->assertTrue($this->enforced('site'));
    }

    public function testAdminIsRefusedWhileSessionAuthenticationIsOnByDefault(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => false]], ['enabled' => true]);

        $this->cli(['enforce-oauth2', 'on', '--admin'], 1, ['API → Session Authentication', 'by default', 'user/config/plugins/api.yaml']);

        $this->assertNull($this->enforced('admin'));
    }

    public function testAdminIsRefusedWhileSessionAuthenticationIsOnInAnEnvironment(): void
    {
        $this->ready();
        static::$site->write('user/env/example.com/config/plugins/api.yaml', ['auth' => ['session_enabled' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--admin'], 1, ['API → Session Authentication', 'user/env/example.com/config/plugins/api.yaml']);

        $this->assertNull($this->enforced('admin'));
    }

    public function testAdminIsRefusedWhileSessionAuthenticationIsSetOnInTheMainConfig(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => false]], ['enabled' => true, 'auth' => ['session_enabled' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--admin'], 1, ['API → Session Authentication', 'user/config/plugins/api.yaml', 'Nothing changed']);
    }

    public function testSessionAuthenticationDoesNotHoldBackTheSite(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => false]], ['enabled' => true, 'auth' => ['session_enabled' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--site'], 0, ["'Enforce OAuth2' is on (site)"]);
    }

    public function testSessionAuthenticationIsMetWhileTheApiPluginIsDisabled(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => false]], ['enabled' => false, 'auth' => ['session_enabled' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--admin'], 0, ["'Enforce OAuth2' is on (admin)"]);

        $this->assertTrue($this->enforced('admin'));
    }

    // -- Where it writes ------------------------------------------------------------------------------------------

    public function testWithoutEnvItWritesTheMainFileEvenWhenTheCliEnvironmentExists(): void
    {
        $this->ready();
        mkdir(static::$site->path('user/env/cli/config'), 0777, true);

        $this->cli(['enforce-oauth2', 'on', '--site'], 0, ['user/config/plugins/login-oauth2.yaml']);

        $this->assertTrue($this->enforced('site'));
        $this->assertFalse(static::$site->exists('user/env/cli/config/plugins/login-oauth2.yaml'));
    }

    public function testWithoutEnvItLeavesAnExistingCliEnvironmentFileAlone(): void
    {
        $this->ready();
        static::$site->write('user/env/cli/config/plugins/login-oauth2.yaml', ['debug' => true]);

        $this->cli(['enforce-oauth2', 'on', '--site'], 0);

        $this->assertTrue($this->enforced('site'));
        $this->assertSame(['debug' => true], static::$site->read('user/env/cli/config/plugins/login-oauth2.yaml'));
    }

    public function testEnvWritesThatEnvironmentsFile(): void
    {
        $this->ready();
        mkdir(static::$site->path('user/env/staging/config'), 0777, true);

        $this->cli(['enforce-oauth2', 'on', '--site', '--env=staging'], 0, ['user/env/staging/config/plugins/login-oauth2.yaml', 'enforce-oauth2 off --env=staging']);

        $this->assertTrue($this->enforced('site', 'user/env/staging/config/plugins/login-oauth2.yaml'));
        $this->assertNull($this->enforced('site'));
    }

    public function testEnvWithoutAFolderIsRefused(): void
    {
        $this->ready();

        $this->cli(['enforce-oauth2', 'on', '--site', '--env=nowhere'], 1, ["Environment 'nowhere' has no folder: nothing changed."]);

        $this->assertNull($this->enforced('site'));
        $this->assertFalse(static::$site->exists('user/env'));
    }

    public function testATraversalEnvNameIsRefused(): void
    {
        $this->ready();
        // Even with folders it would reach: user/env/../etc.
        mkdir(static::$site->path('user/env/staging/config'), 0777, true);
        mkdir(static::$site->path('user/etc/config'), 0777, true);

        $this->cli(['enforce-oauth2', 'on', '--site', '--env=../etc'], 1, ["Environment '../etc' has no folder", 'nothing changed']);

        $this->assertFalse(static::$site->exists('user/etc/config/plugins/login-oauth2.yaml'));
        $this->assertNull($this->enforced('site'));
    }
}
