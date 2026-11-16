<?php declare(strict_types=1);

namespace OAuth2Tests\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * A throwaway Grav site in the system temp folder: Grav core, its vendor folder and the plugins are symlinked in, the
 * rest (user/config, user/env, accounts, cache, logs) is the site's own and is wiped by reset().
 *
 * Everything the tests do goes through what a user or another plugin sees: `php bin/plugin login-oauth2 ...` as a
 * subprocess (cli()), Grav's own events fired by name in a booted Grav (dispatch()), and the YAML files on disk.
 */
final class Site
{
    /** @var string Grav core checkout, set by tests/bootstrap.php */
    public static $core;

    /** @var string */
    public $root;

    /** @var string The API plugin's checkout, for its exception classes (events.php) */
    private $apiDir;

    public static function create(): self
    {
        $plugin = dirname(__DIR__, 2);
        // The Login and API plugins next to this one (~/Projects/grav/grav-plugin-*), or where LOGIN_PLUGIN_DIR and
        // API_PLUGIN_DIR say.
        $sibling = static function (string $name) use ($plugin): string {
            return (string)(getenv(strtoupper($name) . '_PLUGIN_DIR') ?: dirname($plugin) . '/grav-plugin-' . $name);
        };
        // As on a real site: Login needs Form, and the API plugin is loaded (its config store and exceptions are used).
        $plugins = ['login' => $sibling('login'), 'form' => $sibling('form'), 'api' => $sibling('api'), 'login-oauth2' => $plugin];
        foreach ($plugins as $name => $dir) {
            if (!is_dir($dir)) {
                throw new RuntimeException("The {$name} plugin isn't at {$dir}: set " . strtoupper(str_replace('-', '_', $name)) . '_PLUGIN_DIR.');
            }
        }

        $site = new self();
        $site->root = rtrim(sys_get_temp_dir(), '/') . '/oauth2-test-site-' . bin2hex(random_bytes(5));
        mkdir($site->root, 0777, true);
        // realpath: macOS' temp folder is a link, and Grav resolves it.
        $site->root = (string)realpath($site->root);
        foreach (['system', 'vendor', 'bin', 'index.php'] as $name) {
            symlink(static::$core . '/' . $name, $site->root . '/' . $name);
        }
        mkdir($site->root . '/user/plugins', 0777, true);
        foreach ($plugins as $name => $dir) {
            symlink($dir, $site->root . '/user/plugins/' . $name);
        }
        copy(__DIR__ . '/events.php', $site->root . '/events.php');
        $site->apiDir = $sibling('api');
        $site->reset();

        return $site;
    }

    /**
     * Back to a bare site: no config, environments, accounts or cache.
     */
    public function reset(): void
    {
        foreach (scandir($this->root . '/user') as $name) {
            if ($name[0] !== '.' && $name !== 'plugins') {
                static::remove($this->root . '/user/' . $name);
            }
        }
        foreach (['cache', 'logs', 'tmp', 'backup', 'images', 'assets'] as $name) {
            static::remove($this->root . '/' . $name);
            mkdir($this->root . '/' . $name, 0777, true);
        }
        foreach (['config/plugins', 'accounts', 'data', 'pages', 'themes'] as $name) {
            mkdir($this->root . '/user/' . $name, 0777, true);
        }
    }

    public function destroy(): void
    {
        if ($this->root && strpos($this->root, 'oauth2-test-site-') !== false) {
            static::remove($this->root);
        }
    }

    public function path(string $relative = ''): string
    {
        return $this->root . ($relative !== '' ? '/' . ltrim($relative, '/') : '');
    }

    /**
     * Writes a YAML file (an array), or a raw string, creating its folders. Grav's compiled configuration goes, so the
     * next run reads the file.
     *
     * @param string $relative Path in the site, e.g. user/config/plugins/login.yaml
     * @param array|string $content
     */
    public function write(string $relative, $content): void
    {
        $file = $this->path($relative);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, is_array($content) ? Yaml::dump($content, 10, 2) : $content);
        $this->clearCache();
    }

    public function exists(string $relative): bool
    {
        return file_exists($this->path($relative));
    }

    /**
     * @return array|null The parsed YAML file, null if there is none
     */
    public function read(string $relative): ?array
    {
        $file = $this->path($relative);

        return is_file($file) ? (array)Yaml::parseFile($file) : null;
    }

    /**
     * A setting of a YAML file by its dot path, e.g. "admin.enforce_oauth2"; null if the file or the key is missing.
     *
     * @return mixed
     */
    public function value(string $relative, string $key)
    {
        $value = $this->read($relative);
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * An account file: user/accounts/<username>.yaml, with a password.
     *
     * @param array $access E.g. ['site' => ['login' => true]]
     */
    public function account(string $username, array $access, string $password = 'secret-pass-1', array $extra = []): void
    {
        $this->write("user/accounts/{$username}.yaml", $extra + [
            'email' => "{$username}@example.com",
            'fullname' => ucfirst($username),
            'state' => 'enabled',
            // The lowest cost: the tests make many accounts.
            'hashed_password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'access' => $access,
        ]);
    }

    public function clearCache(): void
    {
        static::remove($this->root . '/cache');
        mkdir($this->root . '/cache', 0777, true);
    }

    /**
     * `php bin/plugin login-oauth2 <args>` in the site, as a user runs it.
     *
     * @param string[] $args E.g. ['enforce-oauth2', 'on', '--site']
     * @param array<string, string> $env Environment variables of the process, e.g. GRAV_ENVIRONMENTS_PATH
     * @return array{0: int, 1: string} Exit code, output (stdout and stderr, whitespace collapsed)
     */
    public function cli(array $args, array $env = []): array
    {
        [$code, $out] = $this->run(array_merge([PHP_BINARY, 'bin/plugin', 'login-oauth2'], $args, ['--no-ansi', '--no-interaction']), $env);

        // Long messages wrap, and lists are indented: compare words.
        return [$code, trim((string)preg_replace('/\s+/', ' ', $out))];
    }

    /**
     * Fires Grav's login events by name in a Grav booted as bin/plugin does (events.php), and reports what came out.
     *
     * @param array<int, array<string, mixed>> $operations See events.php
     * @param array<string, string> $env
     * @return array<int, array<string, mixed>> One result per operation
     */
    public function dispatch(array $operations, array $env = []): array
    {
        [$code, $out] = $this->run([PHP_BINARY, 'events.php'], $env + ['OAUTH2_TEST_API_DIR' => $this->apiDir], json_encode($operations, JSON_THROW_ON_ERROR));
        $start = strpos($out, '@@RESULT@@');
        if ($code !== 0 || $start === false) {
            throw new RuntimeException("events.php failed ({$code}): {$out}");
        }

        return json_decode(substr($out, $start + 10), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string[] $command
     * @return array{0: int, 1: string}
     */
    private function run(array $command, array $env, ?string $stdin = null): array
    {
        $base = ['PATH' => (string)getenv('PATH'), 'HOME' => (string)getenv('HOME'), 'COLUMNS' => '4000', 'TERM' => 'dumb', 'TMPDIR' => (string)getenv('TMPDIR')];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->root, $env + $base);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start ' . implode(' ', $command));
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $out];
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                static::remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
