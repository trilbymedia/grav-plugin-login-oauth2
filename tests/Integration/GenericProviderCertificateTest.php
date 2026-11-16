<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * providers.generic.verify_peer: only an explicit off turns the certificate checks of the token and userinfo
 * requests off. Read from the HTTP client the provider made (ProviderFactory::create(), getProvider()->getHttpClient()).
 */
final class GenericProviderCertificateTest extends SiteTestCase
{
    /**
     * @param mixed $value
     * @return mixed What the HTTP client's "verify" option is
     */
    private function verify($value, bool $set = true)
    {
        $generic = static::idp($set ? ['verify_peer' => $value] : []);
        static::$site->write(static::OAUTH2, ['providers' => ['generic' => $generic]]);

        return static::$site->dispatch([['type' => 'provider', 'name' => 'generic']])[0]['verify'];
    }

    #[DataProvider('off')]
    public function testExplicitOffTurnsTheChecksOff($value): void
    {
        $this->assertFalse($this->verify($value));
    }

    public static function off(): array
    {
        return ['false' => [false], '0' => [0], "'0'" => ['0']];
    }

    #[DataProvider('on')]
    public function testAnythingElseKeepsTheChecksOn($value): void
    {
        $this->assertTrue($this->verify($value));
    }

    public static function on(): array
    {
        return ['true' => [true], '1' => [1], 'null' => [null], "''" => [''], '[]' => [[]], "'false'" => ['false'], "'off'" => ['off']];
    }

    public function testMissingKeepsTheChecksOn(): void
    {
        $this->assertTrue($this->verify(null, false));
    }
}
