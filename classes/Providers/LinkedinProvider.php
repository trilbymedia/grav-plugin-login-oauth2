<?php
namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Grav;

class LinkedinProvider extends BaseProvider
{
    protected $name = 'Linkedin';
    protected $classname = 'League\\OAuth2\\Client\\Provider\\Linkedin';
    protected $config;

    /** @var Linkedin */
    protected $provider;

    public function __construct(array $options)
    {
        $this->config = Grav::instance()['config'];

        $options += [
            'clientId'      => $this->config->get('plugins.login-oauth2.providers.linkedin.client_id'),
            'clientSecret'  => $this->config->get('plugins.login-oauth2.providers.linkedin.client_secret'),
            'redirectUri'   => $this->config->get('plugins.login-oauth2.callback_uri'),
            'host'          => $this->config->get('plugins.login-oauth2.providers.linkedin.options.host')
        ];

        parent::__construct($options);
    }

    public function getAuthorizationUrl()
    {
        $options = ['state' => $this->state];
        $options['scope'] = $this->config->get('plugins.login-oauth2.providers.linkedin.options.scope');

        return $this->provider->getAuthorizationUrl($options);
    }

    public function getUserData($user)
    {
        $data = $user->toArray();

        $data_user = [
            'id'         => $user->getId(),
            'login'      => $user->getNickname(),
            'fullname'   => $user->getName(),
            'linkedin'  => [
                'avatar_url' => $data['profile_picture'],
            ]
        ];

        return $data_user;
    }
}