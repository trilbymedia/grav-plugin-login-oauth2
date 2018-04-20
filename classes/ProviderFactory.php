<?php
namespace Grav\Plugin\Login\OAuth2;

class ProviderFactory
{
    /**
     * @param $provider
     * @return mixed
     */
    public static function create($provider){
        $provider_classname = 'Grav\\Plugin\\Login\\OAuth2\\Providers\\' . ucfirst($provider);
        return new $provider_classname;
    }

}