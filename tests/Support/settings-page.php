<?php

use Grav\Common\Grav;
use Grav\Common\Processors\InitializeProcessor;
use Grav\Plugin\Login\OAuth2\OAuth2;

define('GRAV_CLI', true);
define('GRAV_REQUEST_TIME', microtime(true));
$autoload = require __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('UTC');
$grav = Grav::instance(['loader' => $autoload]);
InitializeProcessor::initializeCli($grav);
$grav['plugins']->init();
$grav->fireEvent('onPluginsInitialized');

$request = $grav['request']->withHeader('X-Config-Environment', getenv('OAUTH2_TEST_TARGET') ?: 'default');
unset($grav['request']);
$grav['request'] = $request;
$get = OAuth2::formConfig();
$before = [
    'site' => $get('plugins.login-oauth2.enforce_oauth2'),
    'remember_me' => $get('plugins.login.rememberme.enabled'),
    'session' => $get('plugins.api.auth.session_enabled'),
    'warning' => OAuth2::enforceWarning(),
    'site_notice' => OAuth2::enforceConditionsNotice(),
    'admin_notice' => OAuth2::enforceConditionsNotice(true),
    'dashboard' => OAuth2::enforceDashboardNotice(),
    'callbacks' => OAuth2::adminCallbackUris(),
];
// A second callback on the same settings page must reuse the saved configuration.
file_put_contents(__DIR__ . '/user/config/plugins/login.yaml', "rememberme:\n  enabled: true\n");
$getAgain = OAuth2::formConfig();
echo '@@SETTINGS@@' . json_encode([
    'before' => $before,
    'same_reader' => $get === $getAgain,
    'remember_me_again' => $getAgain('plugins.login.rememberme.enabled'),
    'same_warning' => $before['warning'] === OAuth2::enforceWarning(),
    'same_notice' => $before['site_notice'] === OAuth2::enforceConditionsNotice(),
], JSON_THROW_ON_ERROR);
