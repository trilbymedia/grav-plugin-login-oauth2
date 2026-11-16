<?php declare(strict_types=1);

// The tests drive a throwaway Grav site on disk (tests/Support/Site.php) through bin/plugin and Grav's own events, so
// all this needs is Grav core's autoloader (symfony/yaml and PHPUnit itself) and the test helpers. Set GRAV_ROOT to
// use another core checkout than ~/Projects/grav/grav.
$core = rtrim((string)(getenv('GRAV_ROOT') ?: dirname(__DIR__, 2) . '/grav'), '/');
if (!is_file($core . '/vendor/autoload.php') || !is_file($core . '/system/defines.php')) {
    fwrite(STDERR, "Grav core not found at {$core}: set GRAV_ROOT.\n");
    exit(1);
}
require $core . '/vendor/autoload.php';
require __DIR__ . '/Support/Site.php';
require __DIR__ . '/Support/SiteTestCase.php';

\OAuth2Tests\Support\Site::$core = $core;
