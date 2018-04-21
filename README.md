# grav-plugin-login-oauth2
OAuth2 Client Plugin to integrate with Grav's Login

# GitHub
'clientId'          => '{github-client-id}',
'clientSecret'      => '{github-client-secret}',
'redirectUri'       => 'https://example.com/callback-url',

### Scopes
$options = [
    'state' => 'OPTIONAL_CUSTOM_CONFIGURED_STATE',
    'scope' => ['user','user:email','repo'] // array or string
];

$authorizationUrl = $provider->getAuthorizationUrl($options);

https://developer.github.com/v3/oauth/#scopes


# Instagram
'clientId'          => '{instagram-client-id}',
'clientSecret'      => '{instagram-client-secret}',
'redirectUri'       => 'https://example.com/callback-url',
'host'              => 'https://api.instagram.com' // Optional, defaults to https://api.instagram.com

### Scopes
$options = [
    'state' => 'OPTIONAL_CUSTOM_CONFIGURED_STATE',
    'scope' => ['basic','likes','comments'] // array or string
];

$authorizationUrl = $provider->getAuthorizationUrl($options);

https://instagram.com/developer/authentication/#scope


# Facebook
'clientId'          => '{facebook-app-id}',
'clientSecret'      => '{facebook-app-secret}',
'redirectUri'       => 'https://example.com/callback-url',
'graphApiVersion'   => 'v2.10',

### Scopes

$options = [
    'scope' => ['email', ...] // array or string
];

https://developers.facebook.com/docs/facebook-login/permissions

## Rebuild token from data:

use League\OAuth2\Client\Token\AccessToken

$rebuilt_token = new AccessToken(json_decode($grav_user->get('token'), true));