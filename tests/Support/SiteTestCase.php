<?php declare(strict_types=1);

namespace OAuth2Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A test class with one throwaway Grav site, reset before each test, and the settings most tests start from.
 */
abstract class SiteTestCase extends TestCase
{
    public const OAUTH2 = 'user/config/plugins/login-oauth2.yaml';
    public const LOGIN = 'user/config/plugins/login.yaml';
    public const API = 'user/config/plugins/api.yaml';

    /** @var Site */
    protected static $site;

    public static function setUpBeforeClass(): void
    {
        static::$site = Site::create();
    }

    public static function tearDownAfterClass(): void
    {
        static::$site->destroy();
    }

    protected function setUp(): void
    {
        static::$site->reset();
    }

    /**
     * A provider that counts as active: enabled, with its client ID and endpoints.
     */
    protected static function idp(array $extra = []): array
    {
        return $extra + [
            'enabled' => true,
            'client_id' => 'client-123',
            'authorization_endpoint' => 'https://idp.example.test/authorize',
            'token_endpoint' => 'https://idp.example.test/token',
            'userinfo_endpoint' => 'https://idp.example.test/userinfo',
        ];
    }

    /**
     * A site where "Enforce OAuth2" could be turned on for both sides: an active generic provider on each (the admin
     * enabled), Login's "Remember me" off and the API's Session Authentication off. $oauth2 is merged into the
     * plugin's settings, e.g. to turn it on, or to take a provider away with null.
     *
     * @param array $oauth2 Replaces keys of the plugin's settings (recursively)
     * @param array|null $login Settings of the Login plugin, null for none
     * @param array|null $api Settings of the API plugin, null for none
     */
    protected function ready(array $oauth2 = [], ?array $login = ['rememberme' => ['enabled' => false]], ?array $api = ['enabled' => true, 'auth' => ['session_enabled' => false]]): void
    {
        $settings = [
            'providers' => ['generic' => static::idp()],
            'admin' => ['enabled' => true, 'providers' => ['generic' => static::idp()]],
        ];
        static::$site->write(static::OAUTH2, array_replace_recursive($settings, $oauth2));
        if ($login !== null) {
            static::$site->write(static::LOGIN, $login);
        }
        if ($api !== null) {
            static::$site->write(static::API, $api);
        }
    }

    /**
     * Runs a command and checks its exit code and that its output has each phrase (whitespace collapsed).
     *
     * @param string[] $args
     * @param string[] $phrases
     * @param array<string, string> $env
     * @return string The output
     */
    protected function cli(array $args, int $exit, array $phrases = [], array $env = []): string
    {
        [$code, $out] = static::$site->cli($args, $env);
        $this->assertSame($exit, $code, 'Exit code of "' . implode(' ', $args) . "\". Output: {$out}");
        foreach ($phrases as $phrase) {
            $this->assertStringContainsString($phrase, $out, 'Output of "' . implode(' ', $args) . '"');
        }

        return $out;
    }

    protected function enforced(string $side = 'site', string $file = self::OAUTH2): ?bool
    {
        $value = static::$site->value($file, $side === 'admin' ? 'admin.enforce_oauth2' : 'enforce_oauth2');

        return $value === null ? null : (bool)$value;
    }
}
