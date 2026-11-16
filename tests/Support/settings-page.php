<?php

use Grav\Common\Grav;
use Grav\Common\Processors\InitializeProcessor;
use Grav\Plugin\Login\OAuth2\OAuth2;

// Boots Grav with the plugins and prints what the settings page and Admin2's dashboard get from the plugin.
define('GRAV_CLI', true);
define('GRAV_REQUEST_TIME', microtime(true));
$autoload = require __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('UTC');
$grav = Grav::instance(['loader' => $autoload]);
InitializeProcessor::initializeCli($grav);
$grav['plugins']->init();
$grav->fireEvent('onPluginsInitialized');

echo '@@SETTINGS@@' . json_encode([
    'warning' => OAuth2::enforceWarning(),
    'site_notice' => OAuth2::enforceConditionsNotice(),
    'admin_notice' => OAuth2::enforceConditionsNotice(true),
    'dashboard' => OAuth2::enforceDashboardNotice(),
    'callbacks' => OAuth2::adminCallbackUris(),
], JSON_THROW_ON_ERROR);
