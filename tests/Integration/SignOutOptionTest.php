<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;

/**
 * `enforce-oauth2 on --sign-out`, as far as it can be checked without a browser: which accounts it reports and touches.
 * Whether the sessions, "remember me" cookies and tokens really end needs HTTP requests with them, and isn't tested.
 */
final class SignOutOptionTest extends SiteTestCase
{
    private function accounts(): void
    {
        static::$site->account('al', ['admin' => ['login' => true]]);
        static::$site->account('sue', ['admin' => ['super' => true]]);
        static::$site->account('api1', ['api' => ['access' => true]]);
        static::$site->account('api2', ['api' => ['super' => true]]);
        static::$site->account('disabled-al', ['admin' => ['login' => true]], 'secret-pass-1', ['state' => 'disabled']);
        static::$site->account('plain', ['site' => ['login' => true]]);
    }

    private function accountFile(string $name): string
    {
        return (string)file_get_contents(static::$site->path("user/accounts/{$name}.yaml"));
    }

    public function testAdminOnlySignsOutTheAccountsWithAdminAccess(): void
    {
        $this->ready();
        $this->accounts();
        $plain = $this->accountFile('plain');
        $al = $this->accountFile('al');

        $out = $this->cli(['enforce-oauth2', 'on', '--admin', '--sign-out'], 0, ["'Enforce OAuth2' is on (admin)", 'Signed out 5 accounts with admin access everywhere']);

        foreach (['al', 'sue', 'api1', 'api2', 'disabled-al'] as $name) {
            $this->assertStringContainsString("Signed out \"{$name}\"", $out);
        }
        $this->assertStringNotContainsString('"plain"', $out);
        $this->assertTrue($this->enforced('admin'));
        // Login marks the accounts it signed out: the others are as they were.
        $this->assertNotSame($al, $this->accountFile('al'));
        $this->assertSame($plain, $this->accountFile('plain'));
    }

    public function testSiteSignsOutEveryone(): void
    {
        $this->ready();
        $this->accounts();

        $this->cli(['enforce-oauth2', 'on', '--site', '--sign-out'], 0, ['Every open session ends on its next request', 'Signed out 6 accounts everywhere']);

        $this->assertTrue(static::$site->exists('user/data/login/sign-out-all.yaml'));
    }

    public function testAdminWithNoAdminAccountSignsOutNobody(): void
    {
        $this->ready();
        static::$site->account('plain', ['site' => ['login' => true]]);

        $this->cli(['enforce-oauth2', 'on', '--admin', '--sign-out'], 0, ['Nobody was signed out: no account has admin access.']);

        $this->assertTrue($this->enforced('admin'));
    }

    public function testSignsOutAgainWhenItWasOnAlready(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true]]);
        $this->accounts();

        $this->cli(['enforce-oauth2', 'on', '--admin', '--sign-out'], 0, ["'Enforce OAuth2' is already on (admin)", 'Signed out 5 accounts with admin access everywhere']);
    }

    public function testWithoutTheOptionItSaysNobodyWasSignedOut(): void
    {
        $this->ready();
        $this->accounts();
        $al = $this->accountFile('al');

        $this->cli(['enforce-oauth2', 'on', '--admin'], 0, ['Nobody was signed out', 'add --sign-out']);

        $this->assertSame($al, $this->accountFile('al'));
    }

    public function testRefusedWhenLoginCannotSignOut(): void
    {
        // Login can't be loaded: no sign-out methods. Nothing is changed, not even the setting.
        $this->ready([], ['enabled' => false, 'rememberme' => ['enabled' => false]]);
        $this->accounts();
        $al = $this->accountFile('al');

        $this->cli(['enforce-oauth2', 'on', '--site', '--sign-out'], 1, ["--sign-out isn't possible", 'Nothing changed']);

        $this->assertNull($this->enforced('site'));
        $this->assertSame($al, $this->accountFile('al'));
    }

    public function testWithEnvItSignsOutTheSharedAccounts(): void
    {
        $this->ready();
        $this->accounts();
        mkdir(static::$site->path('user/env/staging/config'), 0777, true);

        $this->cli(['enforce-oauth2', 'on', '--site', '--sign-out', '--env=staging'], 0, ['Signed out 6 accounts everywhere']);

        $this->assertTrue($this->enforced('site', 'user/env/staging/config/plugins/login-oauth2.yaml'));
    }

    public function testRefusedSettingSignsOutNobody(): void
    {
        // The requirement isn't met: nothing changes, and nobody is signed out either.
        $this->ready([], ['rememberme' => ['enabled' => true]]);
        $this->accounts();
        $al = $this->accountFile('al');

        $this->cli(['enforce-oauth2', 'on', '--site', '--sign-out'], 1, ['Nothing changed, nobody was signed out']);

        $this->assertSame($al, $this->accountFile('al'));
        $this->assertFalse(static::$site->exists('user/data/login/sign-out-all.yaml'));
    }
}
