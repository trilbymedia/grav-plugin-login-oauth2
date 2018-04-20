<?php
namespace Grav\Plugin\Login\OAuth2\Providers;

use League\OAuth2\Client\Provider\AbstractProvider;

interface ProviderInterface
{
    public function __construct(array $options);

    /**
     * @return string
     */
    public function getName();

    /**
     * @return string
     */
    public function getState();

    /**
     * @param string $state
     * @return $this
     */
    public function setState($state);

    /**
     * @return AbstractProvider
     */
    public function getProvider();

    /**
     * @return string
     */
    public function getAuthorizationUrl();
}