<?php
namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Grav;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Github;

class GithubProvider extends BaseProvider
{
    protected $name = 'Github';
    protected $classname = 'League\\OAuth2\\Client\\Provider\\Github';
    protected $config;

    /** @var AbstractProvider|Github */
    protected $provider;

    public function __construct(array $options)
    {
        $this->config = Grav::instance()['config'];

        $options += [
            'clientId'      => $this->config->get('plugins.login-oauth2.providers.github.client_id'),
            'clientSecret'  => $this->config->get('plugins.login-oauth2.providers.github.client_secret'),
            'redirectUri'   => $this->getCallbackUri(),
        ];

        parent::__construct($options);
    }

    public function getAuthorizationUrl()
    {
        $options = ['state' => $this->state];
        $options['scope'] = $this->config->get('plugins.login-oauth2.providers.github.options.scope');

        return $this->provider->getAuthorizationUrl($options);
    }

    public function getUserData($user)
    {
        $data = $user->toArray();

        $data_user = [
            'id'         => $user->getId(),
            'login'      => $data['login'],
            'fullname'   => $user->getName(),
            'email'      => $user->getEmail(),
            'github'     => [
                'location'   => $data['location'],
                'company'    => $data['company'],
                'avatar_url' => $data['avatar_url'],
            ]
        ];

        return $data_user;
    }
}