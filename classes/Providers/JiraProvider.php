<?php
/**
 * @author      Wizaplace DevTeam <dev@wizaplace.com>
 * @copyright   Copyright (c) Wizaplace
 * @license     MIT
 */

namespace Grav\Plugin\Login\OAuth2\Providers;

use Grav\Common\Grav;
use Grav\Common\Config\Config;
use Grav\Plugin\Login\OAuth2\ResourceOwner\JiraResourceOwner;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;

class JiraProvider extends BaseProvider
{
    const AUTHORIZE_URL = 'https://auth.atlassian.com/authorize';
    const TOKEN_URL = 'https://auth.atlassian.com/oauth/token';
    const TOKEN_URL_RESOURCES = 'https://api.atlassian.com/oauth/token/accessible-resources';

    /** @var string */
    protected $name = 'Jira';
    /** @var Grav */
    protected $grav;
    /** @var Config */
    protected $config;

    /** @var Client */
    protected $client;

    public function __construct(array $options)
    {
        $this->grav = Grav::instance();
        $this->config = $this->grav['config'];
        $this->client = new Client();
    }

    /**
     * @return string
     */
    public function getAuthorizationUrl()
    {
        $state = bin2hex(random_bytes(16));
        $this->grav['session']->oauth2_state = $state;

        $params = [
            'audience'      => 'api.atlassian.com',
            'client_id'     => $this->config->get('plugins.login-oauth2.providers.jira.client_id'),
            'scope'         => implode(' ', $this->config->get('plugins.login-oauth2.providers.jira.options.scope')),
            'redirect_uri'  => $this->getCallbackUri(),
            'state'         => $state,
            'response_type' => 'code',
            'prompt'        => 'consent',
        ];

        return static::AUTHORIZE_URL."?".http_build_query($params);
    }

    /**
     * @param JiraResourceOwner $user
     *
     * @return array
     */
    public function getUserData($user)
    {
        return [
            'id'       => $user->getId(),
            'login'    => $user->getName(),
            'fullname' => $user->getName(),
            'email'    => $user->getEmail(),
            'jira'     => [
                'company'    => $user->getName(),
                'avatar_url' => $user->getAvatarUrl(),
            ],
        ];
    }

    /**
     * @param mixed $grant
     *
     * @return AccessToken
     */
    public function getAccessToken($grant, array $options = [])
    {
        $params = [
            'grant_type'    => $grant,
            'client_id'     => $this->config->get('plugins.login-oauth2.providers.jira.client_id'),
            'client_secret' => $this->config->get('plugins.login-oauth2.providers.jira.client_secret'),
            'code'          => $options['code'],
            'redirect_uri'  => $this->getCallbackUri(),
        ];

        $response = $this->client->post(
            static::TOKEN_URL,
            [
                RequestOptions::HEADERS => ['Content-Type' => 'application/json'],
                RequestOptions::JSON    => $params,
            ]
        );

        $data = json_decode($response->getBody()->getContents(), true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            throw new \Exception("[Jira Provider] Unable to get access token.");
        }

        return new AccessToken($data);
    }

    /**
     * @param AccessToken $token
     *
     * @return JiraResourceOwner
     */
    public function getResourceOwner(AccessToken $token)
    {
        $response = $this->client->get(
            static::TOKEN_URL_RESOURCES,
            [
                RequestOptions::HEADERS => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token->getToken(),
                ],
            ]
        );

        $data = json_decode($response->getBody()->getContents(), true);

        if (json_last_error() !== JSON_ERROR_NONE && count($data) === 0) {
            throw new \Exception("[Jira Provider] Unable to get resource owner");
        }

        return new JiraResourceOwner($data[0]);
    }
}
