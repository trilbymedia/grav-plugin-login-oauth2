<?php declare(strict_types=1);

namespace OAuth2Tests\Integration;

use OAuth2Tests\Support\SiteTestCase;

final class WithoutFormTest extends SiteTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        unlink(static::$site->path('user/plugins/form'));
    }

    private function runWithoutForm(array $arguments, ?array $operations = null): array
    {
        $command = array_merge([PHP_BINARY, '-d', 'memory_limit=64M'], $arguments);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, static::$site->root, [
            'PATH' => (string)getenv('PATH'),
            'HOME' => (string)getenv('HOME'),
            'OAUTH2_TEST_API_DIR' => (string)getenv('API_PLUGIN_DIR'),
        ]);
        $this->assertIsResource($process);
        if ($operations !== null) {
            fwrite($pipes[0], json_encode($operations, JSON_THROW_ON_ERROR));
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    public function testHelpAndCommandsDoNotLoadTheirBlueprintRecursively(): void
    {
        $this->ready();
        foreach ([['--help'], ['status'], ['enforce-oauth2', 'status'], ['provider', 'verify-certificate', 'generic']] as $arguments) {
            [$code, $output] = $this->runWithoutForm(array_merge(['bin/plugin', 'login-oauth2'], $arguments, ['--no-ansi', '--no-interaction']));
            $this->assertSame(0, $code, $output);
            static::$site->clearCache();
        }
    }

    public function testAdminSaveStillChecksRequirementsWithoutForm(): void
    {
        $this->ready([], ['rememberme' => ['enabled' => true]]);
        [$code, $output] = $this->runWithoutForm(['events.php'], [['type' => 'save', 'data' => ['enabled' => true, 'enforce_oauth2' => true]]]);
        $this->assertSame(0, $code, $output);
        $start = strpos($output, '@@RESULT@@');
        $this->assertNotFalse($start, $output);
        $result = json_decode(substr($output, $start + 10), true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertSame('Grav\\Plugin\\Api\\Exceptions\\ValidationException', $result['thrown']);
        $this->assertStringContainsString('Remember me is still on', $result['message']);
    }
}
