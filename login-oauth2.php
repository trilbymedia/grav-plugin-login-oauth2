<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Session;
use Grav\Common\User\User;
use Grav\Plugin\Login\Events\UserLoginEvent;
use Grav\Plugin\Login\Login;
use Grav\Plugin\Login\OAuth2\ProviderFactory;

/**
 * Class GravPluginLoginOauth2Plugin
 * @package Grav\Plugin
 */
class LoginOauth2Plugin extends Plugin
{

    protected $valid_providers = ['github', 'instagram', 'facebook' ];

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ],
            'onTask.login.oauth2'       => ['loginRedirect', 0],
            'onTask.callback.oauth2'    => ['loginCallback', 0],
            'onTwigTemplatePaths'       => ['onTwigTemplatePaths', 0],
            'onLoginPage'               => ['onLoginPage', 10],
            'onUserLoginAuthenticate'   => ['userLoginAuthenticate', 1000],
            'onUserLoginFailure'        => ['userLoginFailure', 0],
            'onUserLogin'               => ['userLogin', 0],
            'onUserLogout'              => ['userLogout', 0],
        ];
    }

    /**
     * [onPluginsInitialized:100000] Composer autoload.
     *
     * @return ClassLoader
     */
    public function autoload()
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * [onTwigTemplatePaths] Add twig paths to plugin templates.
     */
    public function onTwigTemplatePaths()
    {
        $twig = $this->grav['twig'];
        $twig->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        // Check to ensure login plugin is enabled.
        if (!$this->grav['config']->get('plugins.login.enabled')) {
            throw new \RuntimeException('The Login plugin needs to be installed and enabled');
        }
    }

    /**
     * Add navigation item to the admin plugin
     */
    public function onLoginPage()
    {
        $this->grav['twig']->plugins_hooked_loginPage['LoginOauth2'] = 'partials/login-oauth2.html.twig';
    }

    /**
     * Task: login.oauth2
     */
    public function loginRedirect()
    {

        $user = isset($this->grav['user']) ? $this->grav['user'] : null;
        if ($user && $user->authorized) {
            throw new \RuntimeException('You have already been logged in', 403);
        }

        $provider_name = filter_input(INPUT_POST,'oauth2',FILTER_SANITIZE_STRING,!FILTER_FLAG_STRIP_LOW);

        if (!isset($provider_name)) {
            throw new \RuntimeException('Bad Request', 400);
        }

        if (in_array($provider_name, $this->valid_providers, true)) {

            $provider = ProviderFactory::create($provider_name);

            /** @var Session $session */
            $session = $this->grav['session'];
            $session->oauth2_state = $provider->getState();
            $session->oauth2_provider = $provider_name;

            $authorizationUrl = $provider->getAuthorizationUrl();

            $this->grav->redirect($authorizationUrl);
        }


    }

    /**
     * Task: callback.oauth2
     */
    public function loginCallback()
    {
        /** @var Login $login */
        $login = $this->grav['login'];

        /** @var Session $session */
        $session = $this->grav['session'];
        $provider_name = $session->oauth2_provider;

        if (in_array($provider_name, $this->valid_providers, true)) {



            $state = filter_input(INPUT_GET, 'state', FILTER_SANITIZE_STRING, !FILTER_FLAG_STRIP_LOW);


            if (empty($state) || ($state !== $session->oauth2_state)) {
                unset($session->oauth2_state);
                // how do we indicate the error?

            } else {

                $login->login([], ['oauth2' => true, 'provider' => $provider_name]);

            }
        }
        return false;
    }

    public function userLoginAuthenticate(UserLoginEvent $event)
    {

        // Second parameter of Login::login() call.
        $options = $event->getOptions();

        if (isset($options['oauth2'])) {

            $code = filter_input(INPUT_GET, 'code', FILTER_SANITIZE_STRING, !FILTER_FLAG_STRIP_LOW);
            $provider_name = $options['provider'];
            $provider = ProviderFactory::create($provider_name);

            try {

                // Try to get an access token (using the authorization code grant)
                $token = $provider->getAccessToken('authorization_code', ['code' => $code]);

                // We got an access token, let's now get the user's details
                $user = $provider->getResourceOwner($token);

                $username = $provider_name . '.' . $user->getId();
                $grav_user = User::load($username);

                $event->setUser($grav_user);
                $event->oauth2_provider = $provider;
                $event->oauth2_user = $user;

                // Do something...
                $event->setStatus($event::AUTHENTICATION_SUCCESS);
                $event->stopPropagation();
            } catch (\Exception $e) {
                $this->grav['messages']->add('OAuth2 ' . ucfirst($provider_name) . ' Login Failed: ' . $e->getMessage(), 'error');
                $event->setStatus($event::AUTHENTICATION_FAILURE);
            }
        }
    }

    public function userLoginFailure(UserLoginEvent $event)
    {
        // This gets fired if user fails to log in.
    }

    public function userLogin(UserLoginEvent $event)
    {
        // This gets fired when the user has successfully logged in.
        $provider = $event->oauth2_provider;
        $user = $event->oauth2_user;
        $grav_user = $event->getUser();

        $user_data = $provider->getUserData($user);

        $current_access = $grav_user->get('access');
        if (!$current_access) {
            $access = $this->config->get('plugins.login.user_registration.access.site', []);
            if (count($access) > 0) {
                $data['access']['site'] = $access;
                $grav_user->merge($data);
            }
        }

        $grav_user->merge($user_data);
        $grav_user->save();
    }

    public function userLogout(UserLoginEvent $event)
    {
        // This gets fired on user logout.
    }
}
