<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;

final class SettingsPageTest extends SiteTestCase
{
    private function inspect(array $env = []): array
    {
        copy(dirname(__DIR__) . '/Support/settings-page.php', static::$site->path('settings-page.php'));
        $process = proc_open([PHP_BINARY, 'settings-page.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, static::$site->root, [
            'PATH' => (string)getenv('PATH'),
            'HOME' => (string)getenv('HOME'),
        ] + $env);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $this->assertSame(0, proc_close($process), $output);
        $start = strpos($output, '@@SETTINGS@@');
        $this->assertNotFalse($start, $output);

        return json_decode(substr($output, $start + 12), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testWarningsShowWhileEnforcementIsOnWithARequirementUnmet(): void
    {
        $this->ready(['enforce_oauth2' => true, 'admin' => ['enforce_oauth2' => true]], ['rememberme' => ['enabled' => true]]);

        $result = $this->inspect();

        $this->assertStringContainsString('On the site, Login → Remember me is still on.', $result['warning']);
        $this->assertStringContainsString('In the admin, Login → Remember me is still on.', $result['warning']);
        $this->assertStringContainsString('data-requirement="remember_me" data-met="0"', $result['site_notice']);
        $this->assertStringContainsString('data-requirement="session_passthrough" data-met="1"', $result['admin_notice']);
        $this->assertStringContainsString('Remember me is still on', $result['dashboard']);
        $this->assertStringContainsString('GitHub', $result['callbacks']);
        $this->assertStringContainsString('/auth/sso/github/callback', $result['callbacks']);
    }

    public function testNoWarningWhileTheRequirementsAreMetOrEnforcementIsOff(): void
    {
        $this->ready(['enforce_oauth2' => true]);
        $result = $this->inspect();
        $this->assertSame('', $result['warning']);
        $this->assertNull($result['dashboard']);
        $this->assertStringContainsString('data-met="1"', $result['site_notice']);

        $this->ready([], ['rememberme' => ['enabled' => true]]);
        $result = $this->inspect();
        $this->assertSame('', $result['warning']);
        $this->assertNull($result['dashboard']);
        $this->assertStringContainsString('data-met="0"', $result['site_notice']);
    }

    public function testWarningsFollowTheEnvironmentPickedInAdmin2(): void
    {
        $this->ready(['enforce_oauth2' => true]);
        static::$site->write('user/env/staging.test/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);

        // The request's own configuration, and Admin2's "Default" (the main files): remember me is off.
        $this->assertSame('', $this->inspect()['warning']);
        $this->assertSame('', $this->inspect(['HTTP_X_CONFIG_ENVIRONMENT' => ''])['warning']);

        // Editing staging.test in Admin2 (X-Config-Environment): its login.yaml turns remember me on.
        $result = $this->inspect(['HTTP_X_CONFIG_ENVIRONMENT' => 'staging.test']);
        $this->assertStringContainsString('On the site, Login → Remember me is still on.', $result['warning']);
        $this->assertStringContainsString('data-requirement="remember_me" data-met="0"', $result['site_notice']);
        // The dashboard has no selector: it describes the site as it runs.
        $this->assertNull($result['dashboard']);
    }
}
