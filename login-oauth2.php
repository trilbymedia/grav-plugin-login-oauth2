<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Session;
use Grav\Plugin\Login\Events\UserLoginEvent;
use Grav\Plugin\Login\Login;
use Grav\Plugin\Login\OAuth2\ProviderFactory;

/**
 * Class GravPluginLoginOauth2Plugin
 * @package Grav\Plugin
 */
class LoginOauth2Plugin extends Plugin
{
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

        $post = !empty($_POST) ? $_POST : [];
        if (!isset($post['oauth2'])) {
            throw new \RuntimeException('Bad Request', 400);
        }

        $provider = ProviderFactory::create($post['oauth2']);

        /** @var Session $session */
        $session = $this->grav['session'];
        $session->oauth2 = $provider->getState();

        $authorizationUrl = $provider->getAuthorizationUrl();

        $this->grav->redirect($authorizationUrl);
    }

    /**
     * Task: callback.oauth2
     */
    public function loginCallback()
    {
        /** @var Login $login */
        $login = $this->grav['login'];
        $login->login($_GET, ['oauth2' => true]);

        // ...
    }

    public function userLoginAuthenticate(UserLoginEvent $event)
    {
        // Usually consists of GET or POST variables.
        $credentials = $event->getCredentials();

        // Second parameter of Login::login() call.
        $options = $event->getOptions();

        if (isset($options['oauth2'])) {
            /** @var Session $session */
            $session = $this->grav['session'];
            $state = $session->oauth2;

            // Do something...

            $event->setStatus($event::AUTHENTICATION_SUCCESS);
            $event->stopPropagation();

            return;
        }

        // If authentication status is undefined, lower level event handlers may still be able to authenticate user.
    }

    public function userLoginFailure(UserLoginEvent $event)
    {
        // This gets fired if user fails to log in.
    }

    public function userLogin(UserLoginEvent $event)
    {
        // This gets fired when the user has successfully logged in.
    }

    public function userLogout(UserLoginEvent $event)
    {
        // This gets fired on user logout.
    }
}
