<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;

final class SettingsPageTest extends SiteTestCase
{
    private function inspect(string $target): array
    {
        copy(dirname(__DIR__) . '/Support/settings-page.php', static::$site->path('settings-page.php'));
        $process = proc_open([PHP_BINARY, 'settings-page.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, static::$site->root, [
            'PATH' => (string)getenv('PATH'),
            'HOME' => (string)getenv('HOME'),
            'OAUTH2_TEST_TARGET' => $target,
        ]);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $this->assertSame(0, proc_close($process), $output);
        $start = strpos($output, '@@SETTINGS@@');
        $this->assertNotFalse($start, $output);

        return json_decode(substr($output, $start + 12), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testNoticesUseTheSelectedEnvironmentAndDashboardUsesTheLiveOne(): void
    {
        $this->ready(['enforce_oauth2' => true]);
        static::$site->write('user/env/staging/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);
        static::$site->write('user/env/staging/config/plugins/api.yaml', ['auth' => ['session_enabled' => true]]);

        $result = $this->inspect('staging');

        $this->assertTrue($result['before']['remember_me']);
        $this->assertTrue($result['before']['session']);
        $this->assertStringContainsString('Remember me is still on', $result['before']['warning']);
        $this->assertStringContainsString('data-met="0"', $result['before']['site_notice']);
        $this->assertNull($result['before']['dashboard']);
        $this->assertStringContainsString('GitHub', $result['before']['callbacks']);
        $this->assertStringContainsString('/auth/sso/github/callback', $result['before']['callbacks']);
    }

    public function testDefaultSelectionIgnoresCliOverridesAndReusesItsReaderAndNotices(): void
    {
        $this->ready(['enforce_oauth2' => true]);
        static::$site->write('user/env/cli/config/plugins/login.yaml', ['rememberme' => ['enabled' => true]]);
        static::$site->write('user/env/cli/config/plugins/login-oauth2.yaml', ['enforce_oauth2' => false]);

        $result = $this->inspect('default');

        $this->assertTrue($result['before']['site']);
        $this->assertFalse($result['before']['remember_me']);
        $this->assertSame('', $result['before']['warning']);
        $this->assertStringContainsString('data-met="1"', $result['before']['site_notice']);
        $this->assertTrue($result['same_reader']);
        $this->assertFalse($result['remember_me_again']);
        $this->assertTrue($result['same_warning']);
        $this->assertTrue($result['same_notice']);
    }
}
