<?php
namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Grav;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Google;

class GoogleProvider extends BaseProvider
{
    protected $name = 'Google';
    protected $classname = 'League\\OAuth2\\Client\\Provider\\Google';
    protected $config;

    /** @var AbstractProvider|Google */
    protected $provider;

    public function __construct(array $options)
    {
        $this->config = Grav::instance()['config'];

        $options += [
            'clientId'      => $this->config->get('plugins.login-oauth2.providers.google.client_id'),
            'clientSecret'  => $this->config->get('plugins.login-oauth2.providers.google.client_secret'),
            'redirectUri'   => $this->config->get('plugins.login-oauth2.callback_uri'),
            'host'          => $this->config->get('plugins.login-oauth2.providers.google.options.host')
        ];

        parent::__construct($options);
    }

    public function getAuthorizationUrl()
    {
        $options = ['state' => $this->state];
        $options['scope'] = $this->config->get('plugins.login-oauth2.providers.google.options.scope');

        return $this->provider->getAuthorizationUrl($options);
    }

    public function getUserData($user)
    {
        $data = $user->toArray();

        $data_user = [
            'id'         => $user->getId(),
            'login'      => $user->getNickname(),
            'fullname'   => $user->getName(),
            'google'  => [
                'avatar_url' => $data['profile_picture'],
            ]
        ];

        return $data_user;
    }
}