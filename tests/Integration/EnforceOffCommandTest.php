<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;

/**
 * `bin/plugin login-oauth2 enforce-oauth2 off`: the way back in when locked out. It must turn it off wherever it's on,
 * and never say "already off" while a file still has it on.
 */
final class EnforceOffCommandTest extends SiteTestCase
{
    private const BOTH = ['enforce_oauth2' => true, 'admin' => ['enforce_oauth2' => true]];

    /**
     * What Grav would use for the settings of a web request of an environment (GRAV_ENVIRONMENT).
     *
     * @return array<string, mixed> "site", "admin"
     */
    private function effective(string $environment = '', array $env = []): array
    {
        $keys = ['site' => 'plugins.login-oauth2.enforce_oauth2', 'admin' => 'plugins.login-oauth2.admin.enforce_oauth2'];
        $result = static::$site->dispatch([['type' => 'config', 'keys' => array_values($keys)]], ($environment !== '' ? ['GRAV_ENVIRONMENT' => $environment] : []) + $env)[0];

        return ['site' => (bool)$result[$keys['site']], 'admin' => (bool)$result[$keys['admin']]];
    }

    public function testTurnsBothSidesOffInTheMainFile(): void
    {
        $this->ready(self::BOTH);

        $this->assertSame(['site' => true, 'admin' => true], $this->effective());

        $this->cli(['enforce-oauth2', 'off'], 0, ["'Enforce OAuth2' is off (site, admin)", 'Password logins work again', 'user/config/plugins/login-oauth2.yaml']);

        $this->assertFalse($this->enforced('site'));
        $this->assertFalse($this->enforced('admin'));
        $this->assertSame(['site' => false, 'admin' => false], $this->effective());
    }

    public function testOneSideOnly(): void
    {
        $this->ready(self::BOTH);

        $this->cli(['enforce-oauth2', 'off', '--admin'], 0, ["'Enforce OAuth2' is off (admin)"]);

        $this->assertTrue($this->enforced('site'));
        $this->assertFalse($this->enforced('admin'));
    }

    public function testSaysSoWhenItIsOffAlready(): void
    {
        $this->ready();

        $this->cli(['enforce-oauth2', 'off'], 0, ["'Enforce OAuth2' is already off (site, admin)"]);
    }

    public function testTurnsItOffInEveryEnvironmentThatTurnsItOn(): void
    {
        $this->ready(self::BOTH);
        static::$site->write('user/env/a.example/config/plugins/login-oauth2.yaml', ['enforce_oauth2' => true]);
        static::$site->write('user/env/b.example/config/plugins/login-oauth2.yaml', ['admin' => ['enforce_oauth2' => true]]);
        static::$site->write('user/env/c.example/config/plugins/login-oauth2.yaml', ['debug' => true]);

        $this->assertSame(['site' => true, 'admin' => true], $this->effective('a.example'));
        $this->assertSame(['site' => true, 'admin' => true], $this->effective('b.example'));

        $out = $this->cli(['enforce-oauth2', 'off'], 0, ['user/env/a.example/config/plugins/login-oauth2.yaml', 'user/env/b.example/config/plugins/login-oauth2.yaml']);

        $this->assertStringNotContainsString('already off', $out);
        $this->assertFalse($this->enforced('site', 'user/env/a.example/config/plugins/login-oauth2.yaml'));
        $this->assertFalse($this->enforced('admin', 'user/env/b.example/config/plugins/login-oauth2.yaml'));
        // Not set there: left as it was.
        $this->assertSame(['debug' => true], static::$site->read('user/env/c.example/config/plugins/login-oauth2.yaml'));
        // What each environment's web requests get.
        foreach (['', 'a.example', 'b.example', 'c.example'] as $environment) {
            $this->assertSame(['site' => false, 'admin' => false], $this->effective($environment), "environment '{$environment}'");
        }
    }

    public function testTurnsItOffInTheCliEnvironmentToo(): void
    {
        $this->ready();
        static::$site->write('user/env/cli/config/plugins/login-oauth2.yaml', ['enforce_oauth2' => true]);

        $this->cli(['enforce-oauth2', 'off'], 0, ['user/env/cli/config/plugins/login-oauth2.yaml']);

        $this->assertFalse($this->enforced('site', 'user/env/cli/config/plugins/login-oauth2.yaml'));
    }

    public function testTurnsItOffInALegacyEnvironmentFolder(): void
    {
        $this->ready();
        static::$site->write('user/old.example/config/plugins/login-oauth2.yaml', ['enforce_oauth2' => true]);

        $this->assertTrue($this->effective('old.example')['site']);

        $out = $this->cli(['enforce-oauth2', 'off'], 0, ["'Enforce OAuth2' is off", 'user/old.example/config/plugins/login-oauth2.yaml']);

        $this->assertStringNotContainsString('already off', $out);
        $this->assertFalse($this->enforced('site', 'user/old.example/config/plugins/login-oauth2.yaml'));
        $this->assertSame(['site' => false, 'admin' => false], $this->effective('old.example'));
    }

    public function testTurnsItOffInAFolderOfGravEnvironmentsPath(): void
    {
        $this->ready();
        static::$site->write('custom-envs/x.example/config/plugins/login-oauth2.yaml', ['admin' => ['enforce_oauth2' => true]]);
        $env = ['GRAV_ENVIRONMENTS_PATH' => static::$site->path('custom-envs')];

        $this->assertTrue($this->effective('x.example', $env)['admin']);

        $out = $this->cli(['enforce-oauth2', 'off'], 0, ['custom-envs/x.example/config/plugins/login-oauth2.yaml'], $env);

        $this->assertStringNotContainsString('already off', $out);
        $this->assertFalse($this->enforced('admin', 'custom-envs/x.example/config/plugins/login-oauth2.yaml'));
        $this->assertSame(['site' => false, 'admin' => false], $this->effective('x.example', $env));
    }

    public function testWithEnvOnlyThatEnvironmentIsChanged(): void
    {
        $this->ready(self::BOTH);
        static::$site->write('user/env/a.example/config/plugins/login-oauth2.yaml', ['enforce_oauth2' => true]);

        $this->cli(['enforce-oauth2', 'off', '--env=a.example'], 0, ['environment a.example', 'user/env/a.example/config/plugins/login-oauth2.yaml']);

        $this->assertFalse($this->enforced('site', 'user/env/a.example/config/plugins/login-oauth2.yaml'));
        $this->assertTrue($this->enforced('site'));
        $this->assertTrue($this->enforced('admin'));
    }

    public function testWorksWhileTheLoginPluginIsDisabled(): void
    {
        // Locked out because a plugin fails: off must not need the plugins.
        $this->ready(self::BOTH, ['enabled' => false, 'rememberme' => ['enabled' => false]]);

        $this->cli(['enforce-oauth2', 'off'], 0, ["'Enforce OAuth2' is off (site, admin)"]);

        $this->assertFalse($this->enforced('site'));
        $this->assertFalse($this->enforced('admin'));
    }

    public function testFailsWhenAnEnvironmentFileCannotBeRead(): void
    {
        $this->ready(self::BOTH);
        static::$site->write('user/env/broken.example/config/plugins/login-oauth2.yaml', "enforce_oauth2: [unclosed\n  : :\n");

        $out = $this->cli(['enforce-oauth2', 'off'], 1, ["Can't read these config files", 'user/env/broken.example/config/plugins/login-oauth2.yaml']);

        $this->assertStringNotContainsString('already off', $out);
        // The files it could read are off.
        $this->assertFalse($this->enforced('site'));
    }
}
