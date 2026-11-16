<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;

/**
 * onAdminSave: saving the plugin's settings (Admin2 through the API) is refused while it would turn "Enforce OAuth2"
 * on with a setting it requires off still on. The refusal is the API's 422 ValidationException with the reasons per
 * field; nothing is saved, as the exception stops the save before it writes.
 */
final class AdminSaveTest extends SiteTestCase
{
    private const VALIDATION = 'Grav\\Plugin\\Api\\Exceptions\\ValidationException';

    /**
     * The settings form's data: what's saved, with the enforcement turned on as given.
     */
    private function form(array $enforce = ['enforce_oauth2' => true], array $extra = []): array
    {
        return array_replace_recursive([
            'enabled' => true,
            'providers' => ['generic' => static::idp()],
            'admin' => ['enabled' => true, 'providers' => ['generic' => static::idp()]],
        ], $enforce, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function save(array $data, ?string $file = null): array
    {
        $operation = ['type' => 'save', 'data' => $data];
        if ($file !== null) {
            $operation['file'] = $file;
        }

        return static::$site->dispatch([$operation])[0];
    }

    public function testTurningTheSiteOnWhileRememberMeIsOnIsRefused(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => true]]);
        $data = $this->form();

        $result = $this->save($data);

        $this->assertSame(self::VALIDATION, $result['thrown']);
        $this->assertSame('Not saved: Login → Remember me is still on and lets users log in without OAuth2. Turn it off first.', $result['message']);
        $this->assertSame([['field' => 'enforce_oauth2', 'message' => $result['message']]], $result['errors']);
        // What was sent is as it was, and nothing was written.
        $this->assertSame($data, $result['data']);
        $this->assertNull($this->enforced('site'));
    }

    public function testTurningTheAdminOnWhileSessionAuthenticationIsOnIsRefused(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => false]], ['enabled' => true]);

        $result = $this->save($this->form(['admin' => ['enforce_oauth2' => true]]));

        $this->assertSame(self::VALIDATION, $result['thrown']);
        $this->assertStringContainsString('API → Session Authentication is still on', $result['message']);
        $this->assertSame('admin.enforce_oauth2', $result['errors'][0]['field']);
    }

    public function testRefusesBothSidesWithAReasonEach(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => true]], ['enabled' => true]);

        $result = $this->save($this->form(['enforce_oauth2' => true, 'admin' => ['enforce_oauth2' => true]]));

        $this->assertSame(self::VALIDATION, $result['thrown']);
        $this->assertSame(['enforce_oauth2', 'admin.enforce_oauth2'], array_column($result['errors'], 'field'));
        $this->assertStringContainsString('Login → Remember me and API → Session Authentication are still on', $result['errors'][1]['message']);
    }

    public function testSavesWhenTheRequirementsAreMet(): void
    {
        $this->ready();
        $data = $this->form(['enforce_oauth2' => true, 'admin' => ['enforce_oauth2' => true]]);

        $result = $this->save($data);

        $this->assertArrayNotHasKey('thrown', $result);
        $this->assertSame($data, $result['data']);
    }

    public function testTurningItOffIsNeverRefused(): void
    {
        $this->ready(['enforce_oauth2' => true], ['rememberme' => ['enabled' => true]], ['enabled' => true]);

        $result = $this->save($this->form(['enforce_oauth2' => false, 'admin' => ['enforce_oauth2' => false]]));

        $this->assertArrayNotHasKey('thrown', $result);
    }

    public function testASaveThatLeavesItOnPassesWhileRequirementsAreUnmet(): void
    {
        // On already (e.g. turned on by hand): the settings show a red warning instead.
        $this->ready(['enforce_oauth2' => true], ['rememberme' => ['enabled' => true]]);

        $result = $this->save($this->form(['enforce_oauth2' => true], ['debug' => true]));

        $this->assertArrayNotHasKey('thrown', $result);
    }

    public function testASaveWithoutEnforcementPasses(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => true]], ['enabled' => true]);

        $result = $this->save($this->form(['enforce_oauth2' => false]));

        $this->assertArrayNotHasKey('thrown', $result);
    }

    public function testOtherPluginsSettingsAreNotChecked(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => true]]);

        $result = $this->save(['enforce_oauth2' => true], static::LOGIN);

        $this->assertArrayNotHasKey('thrown', $result);
    }

    public function testRefusalDoesNotDependOnAnotherSideBeingOn(): void
    {
        // Remember me is on, only the admin is turned on: refused for the admin field alone.
        $this->ready([], ['rememberme' => ['enabled' => true]]);

        $result = $this->save($this->form(['admin' => ['enforce_oauth2' => true]]));

        $this->assertSame(['admin.enforce_oauth2'], array_column($result['errors'], 'field'));
    }
}
