<?php

/**
 * Runs in the root of a test site (copied there by Site::create()): boots Grav as `bin/plugin` does, with the plugins,
 * then does what the JSON list of operations on stdin says and prints "@@RESULT@@" and a JSON list of results.
 *
 * Operations ("type"):
 *  login       Fires onUserLoginAuthenticate and, if that succeeds, onUserLoginAuthorize by name, as Login::login() does.
 *              Keys: credentials, options, oauth2_stub (a listener that accepts OAuth2 logins, standing in for the
 *              provider's token request). Result: status, stage, message, message_type, username.
 *  invitation  Fires onApiInvitationAccepted. Result: thrown (exception class), message.
 *  save        Fires onAdminSave with a Data object of the plugin's settings. Keys: data, file (config file the Data
 *              is "read from", default the main one). Result: thrown, message, errors, data (what is left in it).
 *  config      Reads Grav's configuration. Keys: keys. Result: key => value.
 *  provider    Creates a provider through ProviderFactory and returns the HTTP client's "verify" option. Keys: name.
 */

use Grav\Common\Data\Data;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Grav;
use Grav\Common\Processors\InitializeProcessor;
use Grav\Plugin\Login\Events\UserLoginEvent;
use RocketTheme\Toolbox\Event\Event;

define('GRAV_CLI', true);
define('GRAV_REQUEST_TIME', microtime(true));

$autoload = require __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

$grav = Grav::instance(['loader' => $autoload]);
InitializeProcessor::initializeCli($grav);
$grav['plugins']->init();
$grav->fireEvent('onPluginsInitialized');

$operations = json_decode((string)stream_get_contents(STDIN), true);
$results = [];
foreach ($operations as $operation) {
    $result = [];
    try {
        switch ($operation['type']) {
            case 'login':
                if (!empty($operation['oauth2_stub'])) {
                    // Between the plugin's enforcement (20000) and its own provider handler (1000).
                    $grav['events']->addListener('onUserLoginAuthenticate', static function (UserLoginEvent $event) {
                        if ($event->getOption('oauth2')) {
                            $event->setStatus($event::AUTHENTICATION_SUCCESS);
                            $event->stopPropagation();
                        }
                    }, 10000);
                }
                $event = new UserLoginEvent([
                    'credentials' => $operation['credentials'] ?? [],
                    'options' => $operation['options'] ?? [],
                    'authorize' => ($operation['options']['admin'] ?? false) ? 'admin.login' : 'site.login',
                ]);
                $stage = 'authenticate';
                $grav->fireEvent('onUserLoginAuthenticate', $event);
                if ($event->isSuccess()) {
                    $user = $event->getUser();
                    $user->authenticated = true;
                    $user->authorized = false;
                    $event = new UserLoginEvent($event->toArray());
                    $stage = 'authorize';
                    $grav->fireEvent('onUserLoginAuthorize', $event);
                }
                $result = [
                    'success' => $event->isSuccess(),
                    'status' => $event->getStatus(),
                    'stage' => $stage,
                    'message' => $event->getMessage(),
                    'message_type' => $event->getMessageType(),
                ];
                break;

            case 'invitation':
                $grav->fireEvent('onApiInvitationAccepted', new Event(['user' => null]));
                break;

            case 'save':
                $file = $operation['file'] ?? 'user/config/plugins/login-oauth2.yaml';
                $data = new Data($operation['data']);
                $data->file(CompiledYamlFile::instance(__DIR__ . '/' . $file));
                try {
                    $grav->fireEvent('onAdminSave', new Event(['object' => $data]));
                } finally {
                    // Also when the save is refused: what is left in it.
                    $result['data'] = $data->toArray();
                }
                break;

            case 'config':
                foreach ($operation['keys'] as $key) {
                    $result[$key] = $grav['config']->get($key);
                }
                break;

            case 'provider':
                $provider = \Grav\Plugin\Login\OAuth2\ProviderFactory::create($operation['name'], []);
                $client = $provider->getProvider();
                $config = $client->getHttpClient()->getConfig();
                $result['verify'] = $config['verify'] ?? true;
                break;
        }
    } catch (Throwable $e) {
        $result['thrown'] = get_class($e);
        $result['message'] = $e->getMessage();
        if (method_exists($e, 'getValidationErrors')) {
            $result['errors'] = $e->getValidationErrors();
        }
    }
    $results[] = $result;
}

echo '@@RESULT@@' . json_encode($results);
