<?php

// autoload_psr4.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'Psr\\Http\\Message\\' => array($vendorDir . '/psr/http-message/src', $vendorDir . '/psr/http-factory/src'),
    'Psr\\Http\\Client\\' => array($vendorDir . '/psr/http-client/src'),
    'League\\OAuth2\\Client\\' => array($vendorDir . '/league/oauth2-github/src', $vendorDir . '/league/oauth2-google/src', $vendorDir . '/league/oauth2-linkedin/src', $vendorDir . '/league/oauth2-facebook/src', $vendorDir . '/league/oauth2-client/src', $vendorDir . '/league/oauth2-instagram/src'),
    'GuzzleHttp\\Psr7\\' => array($vendorDir . '/guzzlehttp/psr7/src'),
    'GuzzleHttp\\Promise\\' => array($vendorDir . '/guzzlehttp/promises/src'),
    'GuzzleHttp\\' => array($vendorDir . '/guzzlehttp/guzzle/src'),
    'Grav\\Plugin\\Login\\OAuth2\\' => array($baseDir . '/classes'),
);
