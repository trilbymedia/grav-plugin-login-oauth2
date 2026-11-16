<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;

/**
 * `bin/plugin login-oauth2 status` and `enforce-oauth2 status`: read-only, exit 0.
 */
final class StatusCommandTest extends SiteTestCase
{
    public function testEnforceStatusNamesEachSide(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        $out = $this->cli(['enforce-oauth2', 'status'], 0, ['Site: on (user/config/plugins/login-oauth2.yaml)', 'Admin: off']);

        $this->assertStringNotContainsString("doesn't apply", $out);
    }

    public function testEnforceStatusTellsWhyItDoesNotApply(): void
    {
        $this->ready(['enforce_oauth2' => true, 'admin' => ['enforce_oauth2' => true, 'enabled' => false], 'providers' => ['generic' => ['client_id' => '']]]);

        $this->cli(['enforce-oauth2', 'status'], 0, ["Site: on, but doesn't apply: no active provider", "Admin: on, but doesn't apply: 'Enabled in Admin' is off"]);
    }

    public function testEnforceStatusListsTheRequirementsThatAreUnmet(): void
    {
        $this->ready(['enforce_oauth2' => true, 'admin' => ['enforce_oauth2' => true]], ['rememberme' => ['enabled' => true]], ['enabled' => true]);

        $this->cli(['enforce-oauth2', 'status'], 0, [
            'Login → Remember me: on (user/config/plugins/login.yaml)',
            'users can still log in without OAuth2',
            'API → Session Authentication: on (by default; set it in user/config/plugins/api.yaml)',
        ]);
    }

    public function testEnforceStatusShowsRequirementsThatAreMetAsOff(): void
    {
        $this->ready();

        $this->cli(['enforce-oauth2', 'status'], 0, ['Site: off', 'Login → Remember me: off', 'Admin: off', 'API → Session Authentication: off']);
    }

    public function testEnforceStatusShowsWhereEnvironmentsTurnItOn(): void
    {
        $this->ready();
        static::$site->write('user/env/a.example/config/plugins/login-oauth2.yaml', ['enforce_oauth2' => true]);

        $this->cli(['enforce-oauth2', 'status', '--site'], 0, ['Site: off, but on in user/env/a.example/config/plugins/login-oauth2.yaml']);
    }

    public function testStatusLimitedToOneSide(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        $out = $this->cli(['enforce-oauth2', 'status', '--admin'], 0, ['Admin: off']);

        $this->assertStringNotContainsString('Site:', $out);
    }

    public function testStatusChangesNothing(): void
    {
        $this->ready(['enforce_oauth2' => true]);
        $before = file_get_contents(static::$site->path(static::OAUTH2));

        $this->cli(['enforce-oauth2', 'status'], 0);
        $this->cli(['status'], 0);

        $this->assertSame($before, file_get_contents(static::$site->path(static::OAUTH2)));
    }

    public function testStatusCommandListsTheProvidersOfBothSides(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        $this->cli(['status'], 0, [
            'Login OAuth2: on, Login plugin: on',
            'Site', 'Enforce OAuth2 on',
            'Admin', 'Enabled in Admin on',
            'generic on on',
            'github on, but missing client_id',
        ]);
    }

    public function testStatusCommandShowsWhetherCertificatesAreChecked(): void
    {
        $this->ready(['providers' => ['generic' => ['verify_peer' => false]]]);

        $this->cli(['status', '--site'], 0, ['generic on off']);
    }

    public function testStatusCommandWarnsAboutAnUnreadableFile(): void
    {
        $this->ready();
        static::$site->write('user/env/broken.example/config/plugins/login-oauth2.yaml', "enforce_oauth2: [unclosed\n  : :\n");

        $this->cli(['status'], 0, ["Can't read these config files", 'user/env/broken.example/config/plugins/login-oauth2.yaml']);
    }

    public function testStatusWorksWhileTheLoginPluginIsDisabled(): void
    {
        $this->ready([], ['enabled' => false]);

        $this->cli(['status'], 0, ['Login plugin: off', 'Could not initialize the plugins']);
    }

    public function testSignOutWithStatusIsOnlyANote(): void
    {
        $this->ready();
        static::$site->account('al', ['admin' => ['login' => true]]);
        $before = file_get_contents(static::$site->path('user/accounts/al.yaml'));

        $this->cli(['enforce-oauth2', 'status', '--sign-out'], 0, ["--sign-out only works with 'on'. Nobody was signed out."]);

        $this->assertSame($before, file_get_contents(static::$site->path('user/accounts/al.yaml')));
    }
}
