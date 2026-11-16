<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;

/**
 * `bin/plugin login-oauth2 provider enable|disable|verify-certificate`: which keys change, for which side.
 */
final class ProviderCommandTest extends SiteTestCase
{
    public function testDisableChangesTheSiteKeyOnly(): void
    {
        $this->ready();

        $this->cli(['provider', 'disable', 'github'], 0, ["'github' is disabled (site, admin)"]);

        // The admin's providers are off by default already: nothing to write there.
        $this->assertFalse(static::$site->value(static::OAUTH2, 'providers.github.enabled'));
        $this->assertNull(static::$site->value(static::OAUTH2, 'admin.providers.github.enabled'));
    }

    public function testEnableForTheAdminChangesTheAdminKeyOnly(): void
    {
        $this->ready();

        $this->cli(['provider', 'enable', 'github', '--admin'], 0, ["'github' is enabled (admin)", "Admin: 'github' isn't active yet: missing client_id"]);

        $this->assertTrue(static::$site->value(static::OAUTH2, 'admin.providers.github.enabled'));
        $this->assertNull(static::$site->value(static::OAUTH2, 'providers.github.enabled'));
    }

    public function testEnableForTheSiteOnly(): void
    {
        $this->ready(['providers' => ['github' => ['enabled' => false]]]);

        $this->cli(['provider', 'enable', 'github', '--site'], 0, ["'github' is enabled (site)"]);

        $this->assertTrue(static::$site->value(static::OAUTH2, 'providers.github.enabled'));
        $this->assertNull(static::$site->value(static::OAUTH2, 'admin.providers.github.enabled'));
    }

    public function testEnablingWhatIsEnabledChangesNothing(): void
    {
        $this->ready();
        $before = file_get_contents(static::$site->path(static::OAUTH2));

        $this->cli(['provider', 'enable', 'github', '--site'], 0, ["'github' is already enabled (site)"]);

        $this->assertSame($before, file_get_contents(static::$site->path(static::OAUTH2)));
    }

    public function testUnknownProviderIsAnError(): void
    {
        $this->ready();

        $this->cli(['provider', 'enable', 'nope'], 1, ["Unknown provider 'nope'", 'generic, github, instagram, facebook, google, linkedin']);
    }

    public function testUnknownActionIsAnError(): void
    {
        $this->ready();

        $this->cli(['provider', 'toggle', 'github'], 1, ["Unknown action 'toggle': use enable, disable or verify-certificate."]);
    }

    public function testEnableTakesNoValue(): void
    {
        $this->ready();

        $this->cli(['provider', 'enable', 'github', 'off'], 1, ['Too many arguments', 'Nothing changed']);
    }

    public function testDisablingTheLastActiveProviderNotesThatEnforceNoLongerApplies(): void
    {
        $this->ready(['enforce_oauth2' => true]);

        $this->cli(['provider', 'disable', 'generic', '--site'], 0, ["no active provider is left, so 'Enforce OAuth2' no longer applies"]);

        $this->assertFalse(static::$site->value(static::OAUTH2, 'providers.generic.enabled'));
    }

    public function testEnablingAProviderTurnsADormantEnforcementIntoOne(): void
    {
        $this->ready(['enforce_oauth2' => true, 'providers' => ['generic' => ['enabled' => false]]]);

        $this->cli(['provider', 'enable', 'generic', '--site'], 0, ["'Enforce OAuth2' is on and applies now that the side has an active provider"]);
    }

    // -- verify-certificate ---------------------------------------------------------------------------------------

    public function testVerifyCertificateShowsTheState(): void
    {
        $this->ready();

        $this->cli(['provider', 'verify-certificate', 'generic'], 0, ["Site: 'Verify Certificate' of 'generic' is on", "Admin: 'Verify Certificate' of 'generic' is on"]);
    }

    public function testVerifyCertificateOffForTheSiteWritesFalseAndWarns(): void
    {
        $this->ready();

        $this->cli(['provider', 'verify-certificate', 'generic', 'off', '--site'], 0, ["'Verify Certificate' of 'generic' is off (site)", 'For testing only']);

        $this->assertFalse(static::$site->value(static::OAUTH2, 'providers.generic.verify_peer'));
        $this->assertNull(static::$site->value(static::OAUTH2, 'admin.providers.generic.verify_peer'));
        $this->cli(['provider', 'verify-certificate', 'generic', '--site'], 0, ["Site: 'Verify Certificate' of 'generic' is off"]);
    }

    public function testVerifyCertificateOffForTheAdminOnly(): void
    {
        $this->ready();

        $this->cli(['provider', 'verify-certificate', 'generic', 'off', '--admin'], 0, ["(admin)"]);

        $this->assertFalse(static::$site->value(static::OAUTH2, 'admin.providers.generic.verify_peer'));
        $this->assertNull(static::$site->value(static::OAUTH2, 'providers.generic.verify_peer'));
    }

    public function testVerifyCertificateOnAgain(): void
    {
        $this->ready(['providers' => ['generic' => ['verify_peer' => false]], 'admin' => ['providers' => ['generic' => ['verify_peer' => false]]]]);

        $this->cli(['provider', 'verify-certificate', 'generic', 'on'], 0, ["'Verify Certificate' of 'generic' is on (site, admin)"]);

        $this->assertTrue(static::$site->value(static::OAUTH2, 'providers.generic.verify_peer'));
        $this->assertTrue(static::$site->value(static::OAUTH2, 'admin.providers.generic.verify_peer'));
    }

    public function testVerifyCertificateOnlyForProvidersThatHaveIt(): void
    {
        $this->ready();

        $this->cli(['provider', 'verify-certificate', 'github', 'off'], 1, ["'github' has no 'Verify Certificate' setting"]);

        $this->assertNull(static::$site->value(static::OAUTH2, 'providers.github.verify_peer'));
    }

    public function testVerifyCertificateRejectsOtherValues(): void
    {
        $this->ready();

        $this->cli(['provider', 'verify-certificate', 'generic', 'maybe'], 1, ["Unknown value 'maybe': use on, off or status."]);
    }
}
