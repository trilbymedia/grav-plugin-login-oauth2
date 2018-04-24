<?php
namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Grav;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Linkedin;

class LinkedinProvider extends BaseProvider
{
    protected $name = 'Linkedin';
    protected $classname = 'League\\OAuth2\\Client\\Provider\\Linkedin';
    protected $config;

    /** @var AbstractProvider|Linkedin */
    protected $provider;

    public function __construct(array $options)
    {
        $this->config = Grav::instance()['config'];

        $options += [
            'clientId'      => $this->config->get('plugins.login-oauth2.providers.linkedin.client_id'),
            'clientSecret'  => $this->config->get('plugins.login-oauth2.providers.linkedin.client_secret'),
            'redirectUri'   => $this->getCallbackUri(),
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
        $data_user = [
            'id'         => $user->getId(),
            'login'      => $user->getEmail(),
            'fullname'   => $user->getFirstName() . ' ' . $user->getLastName(),
            'email'      => $user->getEmail(),
            'linkedin'  => [
                'avatar_url' => $user->getImageurl(),
                'headline' => $user->getDescription(),
                'location' => $user->getLocation(),
            ]
        ];

        return $data_user;
    }
}