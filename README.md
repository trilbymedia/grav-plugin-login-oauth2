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