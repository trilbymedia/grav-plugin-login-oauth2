<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * "Enforce OAuth2" as the Login plugin's events see it: onUserLoginAuthenticate and onUserLoginAuthorize fired by
 * name in a Grav booted with the plugins (tests/Support/events.php), as Login::login() fires them. Login's own
 * handlers run too, so a login that isn't denied by "Enforce OAuth2" really succeeds with the account's password.
 *
 * The OAuth2 logins use a stub for the provider's token request (no network): it stands in for the plugin's own
 * handler, and only runs if "Enforce OAuth2" lets the login through.
 */
final class LoginEnforcementTest extends SiteTestCase
{
    private const PASSWORD = ['password' => 'secret-pass-1'];
    private const DENIED = 'Password logins are disabled. Please use one of the login buttons.';
    private const ADMIN_ACCOUNT = 'Accounts with admin access can only log in with OAuth2. Please use one of the login buttons.';
    private const AUTHORIZATION_DENIED = 32;
    private const AUTHENTICATION_FAILURE = 2;

    protected function setUp(): void
    {
        parent::setUp();
        static::$site->account('al', ['site' => ['login' => true], 'admin' => ['login' => true]]);
        static::$site->account('sue', ['site' => ['login' => true], 'admin' => ['super' => true]]);
        static::$site->account('api1', ['site' => ['login' => true], 'api' => ['access' => true]]);
        static::$site->account('api2', ['site' => ['login' => true], 'api' => ['super' => true]]);
        static::$site->account('plain', ['site' => ['login' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function login(string $user, array $options = [], bool $password = true, bool $oauth2 = false): array
    {
        return static::$site->dispatch([[
            'type' => 'login',
            'credentials' => ['username' => $user] + ($password ? self::PASSWORD : []),
            'options' => $options,
            'oauth2_stub' => $oauth2,
        ]])[0];
    }

    private function assertDeniedAtAuthenticate(array $result, ?string $message = self::DENIED): void
    {
        $this->assertFalse($result['success']);
        $this->assertSame('authenticate', $result['stage']);
        $this->assertSame(self::AUTHENTICATION_FAILURE, $result['status']);
        $this->assertSame($message, $result['message']);
    }

    private function assertDeniedAtAuthorize(array $result): void
    {
        $this->assertFalse($result['success']);
        $this->assertSame('authorize', $result['stage']);
        $this->assertSame(self::AUTHORIZATION_DENIED, $result['status'] & self::AUTHORIZATION_DENIED);
    }

    private function assertLoggedIn(array $result): void
    {
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('authorize', $result['stage']);
        $this->assertNull($result['message']);
    }

    // -- Site enforced --------------------------------------------------------------------------------------------

    public function testSitePasswordLoginIsDeniedWithTheEnforceMessage(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        $this->assertDeniedAtAuthenticate($this->login('plain'));
    }

    public function testSiteDeniesEvenAWrongPasswordTheSameWay(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        // Before any credential check: it can't be used to test passwords.
        $result = static::$site->dispatch([['type' => 'login', 'credentials' => ['username' => 'plain', 'password' => 'wrong']]])[0];

        $this->assertDeniedAtAuthenticate($result);
    }

    public function testSiteOAuth2LoginPasses(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        $this->assertLoggedIn($this->login('plain', ['oauth2' => true, 'provider' => 'generic'], false, true));
    }

    public function testSiteRememberMeLoginPasses(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        $this->assertLoggedIn($this->login('plain', ['remember_me_login' => true]));
    }

    public function testSiteMagicLinkAndPasskeyLoginsAreDeniedToo(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        // Neither comes with a password.
        $this->assertDeniedAtAuthenticate($this->login('plain', ['passkey' => true], false));
        $this->assertDeniedAtAuthenticate($this->login('plain', ['magic' => true], false));
    }

    public function testSiteLoginAfterRegistrationIsDenied(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        $this->assertDeniedAtAuthenticate($this->login('plain', ['after_registration' => true], false));
    }

    public function testSiteEnforcementLeavesAdminLoginsAlone(): void
    {
        // Only the admin's setting covers logins with the "admin" option.
        $this->ready(['enforce_oauth2' => true]);

        $result = $this->login('al', ['admin' => true]);

        $this->assertTrue($result['success']);
    }

    public function testSiteEnforcementDoesNotRefuseInvitations(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        $this->assertArrayNotHasKey('thrown', static::$site->dispatch([['type' => 'invitation']])[0]);
    }

    // -- Not applying ---------------------------------------------------------------------------------------------

    public function testSiteWithoutActiveProviderLetsPasswordLoginsThrough(): void
    {
        $this->ready(['enforce_oauth2' => true, 'providers' => ['generic' => ['client_id' => '']]]);

        $this->assertLoggedIn($this->login('plain'));
    }

    public function testSiteWithTheProviderDisabledLetsPasswordLoginsThrough(): void
    {
        $this->ready(['enforce_oauth2' => true, 'providers' => ['generic' => ['enabled' => false]]]);

        $this->assertLoggedIn($this->login('plain'));
    }

    public function testSiteWithThePluginDisabledLetsPasswordLoginsThrough(): void
    {
        $this->ready(['enabled' => false, 'enforce_oauth2' => true]);

        $this->assertLoggedIn($this->login('plain'));
    }

    public function testSiteSettingOffLetsPasswordLoginsThrough(): void
    {
        $this->ready();

        $this->assertLoggedIn($this->login('plain'));
    }

    // -- Admin enforced only --------------------------------------------------------------------------------------

    public function testAdminPasswordLoginIsDenied(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true]]);

        $this->assertDeniedAtAuthenticate($this->login('al', ['admin' => true]));
    }

    public function testAdminOAuth2LoginPasses(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true]]);

        $this->assertLoggedIn($this->login('al', ['admin' => true, 'oauth2' => true, 'provider' => 'generic'], false, true));
    }

    public function testAdminRememberMeLoginPasses(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true]]);

        $this->assertLoggedIn($this->login('al', ['admin' => true, 'remember_me_login' => true]));
    }

    #[DataProvider('adminAccounts')]
    public function testSitePasswordLoginOfAnAccountWithAdminAccessIsDeniedAtAuthorize(string $account): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true]]);

        $result = $this->login($account);

        $this->assertDeniedAtAuthorize($result);
        // Refused like a wrong password: no message that tells the password was right or the account has admin access.
        $this->assertNull($result['message']);
    }

    public static function adminAccounts(): array
    {
        return ['admin.login' => ['al'], 'admin.super' => ['sue'], 'api.access' => ['api1'], 'api.super' => ['api2']];
    }

    public function testSitePasswordLoginOfASiteOnlyAccountPasses(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true]]);

        $this->assertLoggedIn($this->login('plain'));
    }

    public function testSitePasskeyLoginOfAnAccountWithAdminAccessIsDeniedWithAMessage(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true]]);

        $result = $this->login('al', ['passkey' => true], false);

        $this->assertDeniedAtAuthorize($result);
        $this->assertSame(self::ADMIN_ACCOUNT, $result['message']);
    }

    public function testSiteOAuth2LoginOfAnAccountWithAdminAccessPasses(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true]]);

        $this->assertLoggedIn($this->login('al', ['oauth2' => true, 'provider' => 'generic'], false, true));
    }

    public function testSiteRememberMeLoginOfAnAccountWithAdminAccessPasses(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true]]);

        $this->assertLoggedIn($this->login('al', ['remember_me_login' => true]));
    }

    public function testBothSidesEnforcedDenyTheSitePasswordLoginAtAuthenticate(): void
    {
        $this->ready(['enforce_oauth2' => true, 'admin' => ['enforce_oauth2' => true]]);

        $this->assertDeniedAtAuthenticate($this->login('al'));
        $this->assertDeniedAtAuthenticate($this->login('al', ['admin' => true]));
    }

    public function testAdminWhileEnabledInAdminIsOffLetsPasswordLoginsThrough(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true, 'enabled' => false]]);

        $this->assertTrue($this->login('al', ['admin' => true])['success']);
        $this->assertLoggedIn($this->login('al'));
    }

    public function testAdminWithoutActiveProviderLetsPasswordLoginsThrough(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true, 'providers' => ['generic' => ['client_id' => '']]]]);

        $this->assertTrue($this->login('al', ['admin' => true])['success']);
        $this->assertLoggedIn($this->login('al'));
    }

    // -- No fail-open ---------------------------------------------------------------------------------------------

    public function testEnforcementKeepsRefusingPasswordLoginsWhenRememberMeIsTurnedBackOn(): void
    {
        $this->ready(['enforce_oauth2' => true, 'admin' => ['enforce_oauth2' => true]], ['rememberme' => ['enabled' => true]], ['enabled' => true]);

        $this->assertDeniedAtAuthenticate($this->login('plain'));
        $this->assertDeniedAtAuthenticate($this->login('al', ['admin' => true]));
    }

    public function testAdminEnforcementKeepsRefusingAdminAccountsWhenRememberMeAndSessionsAreOn(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true]], ['rememberme' => ['enabled' => true]], ['enabled' => true]);

        $this->assertDeniedAtAuthorize($this->login('al'));
    }

    // -- Invitations ----------------------------------------------------------------------------------------------

    public function testInvitationIsRefusedWhileAdminEnforcementApplies(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true]]);

        $result = static::$site->dispatch([['type' => 'invitation']])[0];

        $this->assertSame('Grav\\Plugin\\Api\\Exceptions\\ForbiddenException', $result['thrown']);
        $this->assertSame('Your account was created. Admin logins only work with OAuth2, so please log in through your identity provider.', $result['message']);
    }

    public function testInvitationPassesWhenAdminEnforcementIsOff(): void
    {
        $this->ready();

        $this->assertArrayNotHasKey('thrown', static::$site->dispatch([['type' => 'invitation']])[0]);
    }

    public function testInvitationPassesWhenAdminEnforcementDoesNotApply(): void
    {
        $this->ready(['admin' => ['enforce_oauth2' => true, 'providers' => ['generic' => ['client_id' => '']]]]);

        $this->assertArrayNotHasKey('thrown', static::$site->dispatch([['type' => 'invitation']])[0]);
    }
}
