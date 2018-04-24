<?php
namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Grav;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Facebook;

class FacebookProvider extends BaseProvider
{
    protected $name = 'Facebook';
    protected $classname = 'League\\OAuth2\\Client\\Provider\\Facebook';
    protected $config;

    /** @var AbstractProvider|Facebook */
    protected $provider;

    public function __construct(array $options)
    {
        $this->config = Grav::instance()['config'];

        $options += [
            'clientId'          => $this->config->get('plugins.login-oauth2.providers.facebook.app_id'),
            'clientSecret'      => $this->config->get('plugins.login-oauth2.providers.facebook.app_secret'),
            'redirectUri'       => $this->getCallbackUri(),
            'graphApiVersion'   => $this->config->get('plugins.login-oauth2.providers.facebook.options.graph_api_version')
        ];

        parent::__construct($options);
    }

    public function getAuthorizationUrl()
    {
        $options = ['state' => $this->state];
        $options['scope'] = $this->config->get('plugins.login-oauth2.providers.facebook.options.scope');

        return $this->provider->getAuthorizationUrl($options);
    }

    public function getUserData($user)
    {
        $data_user = [
            'id'         => $user->getId(),
            'login'      => $user->getEmail(),
            'fullname'   => $user->getName(),
            'email'      => $user->getEmail(),
            'facebook'  => [
                'avatar_url' => $user->getPictureUrl(),
                'location' => $user->getHometown() ? $user->getHometown()['name'] : ''
            ]
        ];

        return $data_user;
    }
}