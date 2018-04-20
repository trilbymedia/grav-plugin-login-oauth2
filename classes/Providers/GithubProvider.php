<?php
namespace Grav\Plugin\Login\OAuth2\Providers;

use League\OAuth2\Client\Provider\Github;

class GithubProvider extends BaseProvider
{
    protected $name = 'Github';

    /** @var Github */
    protected $provider;

    public function __construct(array $options)
    {
        $options += [
            'clientId'      => $this->config->get('plugins.login-oauth2.providers.github.client_id'),
            'clientSecret'  => $this->config->get('plugins.login-oauth2.providers.github.client_secret'),
            'redirectUri'   => $this->config->get('plugins.login-oauth2.callback_uri'),
        ];

        parent::__construct($options);
    }

    public function getAuthorizationUrl()
    {
        $options = ['state' => $this->state];
        $options['scope'] = $this->config->get('plugins.login-oauth2.providers.github.options.scope');

        return $this->provider->getAuthorizationUrl($options);
    }
}