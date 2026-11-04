<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Exception;
use Grav\Common\Data\Data;
use Grav\Common\Debugger;
use Grav\Common\Language\Language;
use Grav\Common\Plugin;
use Grav\Common\Session;
use Grav\Common\Uri;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Exceptions\ForbiddenException as ApiForbiddenException;
use Grav\Plugin\Login\Events\UserLoginEvent;
use Grav\Plugin\Login\Login;
use Grav\Plugin\Login\OAuth2\OAuth2;
use Grav\Plugin\Login\OAuth2\ProviderFactory;
use ReflectionClass;
use ReflectionException;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\Session\Message;
use RuntimeException;

/**
 * Class GravPluginLoginOauth2Plugin
 * @package Grav\Plugin
 */
class LoginOauth2Plugin extends Plugin
{
    /** @var bool */
    protected $admin = false;

    protected $debug = false;

    /** @var OAuth2|null Admin-mode service built for the headless SSO path. */
    private $adminOAuth2;

    /** @var OAuth2|null Admin providers for the "Enforce OAuth2" check outside the SSO path, beside the site's service. */
    private $adminProviders;

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
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['onPluginsInitialized', 0],
                ['disableMagicLinkRoutes', -1000],
            ],
        ];
    }

    /**
     * [onPluginsInitialized:100000] Composer autoload.
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onTwigLoader(): void
    {
        $media_paths = $this->grav['locator']->findResources('plugins://login-oauth2/media');
        foreach(array_reverse($media_paths) as $images_path) {
            $this->grav['twig']->addPath($images_path, 'oauth2-media');
        }
    }

    /**
     * [onTwigTemplatePaths] Add twig paths to plugin templates.
     */
    public function onTwigTemplatePaths(): void
    {
        $twig = $this->grav['twig'];
        $twig->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigSiteVariables(): void
    {
        // add CSS for frontend if required
        if ((!$this->isAdmin() && $this->config->get('plugins.login-oauth2.built_in_css')) ||
            ($this->admin && $this->config->get('plugins.login-oauth2.admin.built_in_css'))) {
            $this->grav['assets']->add('plugin://login-oauth2/css/login-oauth2.css');
        }
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            if (!$this->grav['config']->get('plugins.login-oauth2.admin.enabled')) {
                // Don't proceed if we are in the admin plugin
                return;
            }
            $this->admin = true;
        }

        $this->enable([
                'onTask.login.oauth2'       => ['loginRedirect', 0],
                'onTask.callback.oauth2'    => ['loginCallback', 0],
                'onTask.delete.oauth2'      => ['loginDataDelete', 0],
                'onTask.login.magicRequest' => ['denyMagicLinkRequest', 10],
                'onTwigLoader'              => ['onTwigLoader', 0],
                'onTwigTemplatePaths'       => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables'       => ['onTwigSiteVariables', 0],
                'onLoginPage'               => ['onLoginPage', 10],
                'onUserLoginAuthenticate'   => [['userLoginEnforceOAuth2', 20000], ['userLoginAuthenticate', 1000]],
                'onUserLoginAuthorize'      => ['userLoginEnforceAdminOAuth2', 10],
                'onUserLoginFailure'        => ['userLoginFailure', 0],
                'onUserLogin'               => ['userLogin', 0],
                'onUserLogout'              => ['userLogout', 0],
                'onOAuth2Username'          => ['onOAuth2Username', 0],
                // Headless SSO bridge for admin-next (API plugin). These let the
                // SvelteKit admin login screen drive OAuth without the classic
                // Twig login page / form tasks.
                'onApiLoginProviders'       => ['onApiLoginProviders', 0],
                'onApiLoginStart'           => ['onApiLoginStart', 0],
                'onApiLoginCallback'        => ['onApiLoginCallback', 0],
                'onApiInvitationAccepted'   => ['onApiInvitationAccepted', 0],
            ]
        );

        // Check to ensure login plugin is enabled.
        if (!$this->config->get('plugins.login.enabled')) {
            throw new RuntimeException('The Login plugin needs to be installed and enabled');
        }

        $this->debug = $this->config->get('plugins.login-oauth2.debug', false);

        $isAdmin = $this->admin;
        $this->grav['oauth2'] = static function () use ($isAdmin) {
            // Add OAuth2 object to Grav
            $oauth2 = new OAuth2($isAdmin);
            $oauth2->addEnabledProviders();

            return $oauth2;
        };
    }

    /**
     * [onLoginPage] Adds the login buttons. With "Enforce OAuth2" in effect on the site, also turns the Login plugin's
     * password form off (show_password_form, set only where the Login config has that key: without it the form shows
     * anyway, and so the "or" above it), and its "Login by link" button, as magic-link logins are denied too.
     * Not in the classic admin: it ignores show_password_form, and its Login settings form would show and save the changes
     * (the Login plugin fires this event on every page).
     */
    public function onLoginPage(): void
    {
        if ($this->grav['oauth2']->getProviders()) {
            $this->grav['login']->addProviderLoginTemplate('login-oauth2/login-oauth2.html.twig');
        }
        if (!$this->admin && $this->isOAuth2Enforced(false)) {
            if ($this->config->get('plugins.login.show_password_form') !== null) {
                $this->config->set('plugins.login.show_password_form', false);
            }
            $this->config->set('plugins.login.magic_link.enabled', false);
        }
    }

    /**
     * [onPluginsInitialized:-1000] "Enforce OAuth2" on the site: turns magic links off on the Login plugin's magic-link
     * routes before it reads the setting (onPagesInitialized), once the satellite plugins have added their providers.
     * The request page then redirects to the login page, and an emailed link is refused before it's used. Only on these
     * routes, so the settings forms of the admins (classic admin, Admin2) never show or save the change.
     */
    public function disableMagicLinkRoutes(): void
    {
        if ($this->isAdmin() || !isset($this->grav['oauth2'], $this->grav['login'])) {
            return;
        }

        /** @var Login $login */
        $login = $this->grav['login'];
        // Compared as the Login plugin routes them (LoginPlugin::initializeLogin()).
        $path = $this->grav['uri']->path();
        if (($path === $login->getRoute('magic') || $path === $login->getRoute('magic_login')) && $this->isOAuth2Enforced(false)) {
            $this->config->set('plugins.login.magic_link.enabled', false);
        }
    }

    /**
     * [onTask.login.magicRequest:10] "Enforce OAuth2" on the site: refuses a magic-link request before the Login plugin
     * handles it, which with magic links off would still answer that a link was sent.
     */
    public function denyMagicLinkRequest(Event $event): void
    {
        if ($this->isAdmin() || !$this->isOAuth2Enforced(false)) {
            return;
        }

        $this->grav['log']->notice('Login OAuth2: denied a magic-link request (Enforce OAuth2)');
        $this->grav['messages']->add($this->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_MAGIC_LINK_DENIED'), 'error');
        $event->stopPropagation();
        $this->grav->redirectLangSafe($this->grav['login']->getRoute('login') ?: '/');
    }

    /**
     * Task: login.oauth2
     */
    public function loginRedirect(): void
    {
        /** @var OAuth2 $oauth2 */
        $oauth2 = $this->grav['oauth2'];

        $user = $this->grav['user'] ?? null;
        if ($user && $user->authorized) {
            throw new RuntimeException('You have already been logged in', 403);
        }

        $provider_name = isset($_POST['oauth2']) ? htmlspecialchars(strip_tags($_POST['oauth2']), ENT_QUOTES, 'UTF-8') : null;

        if (!isset($provider_name)) {
            throw new RuntimeException('Bad Request', 400);
        }

        if ($oauth2->isValidProvider($provider_name)) {

            $provider = ProviderFactory::create($provider_name, $oauth2->getProviderOptions($provider_name));

            /** @var Session $session */
            $session = $this->grav['session'];
            $session->oauth2_state = $provider->getState();
            $session->oauth2_provider = $provider_name;
            if ($this->isAdmin()) {
                $redirect = (string)$this->grav['admin']->request->getUri();
            } else {
                if ($this->config->get('plugins.login.redirect_after_login')) {
                    $redirect = (string) $this->config->get('plugins.login.route_after_login');
                } else {
                    /** @var Uri $uri */
                    $request = $this->grav['request'];
                    $redirect = (string) $request->getUri();
                }
            }
            $session->redirect_after_login = $redirect;

            $authorizationUrl = $provider->getAuthorizationUrl();

            $this->grav->redirect($authorizationUrl);
        }
    }

    /**
     * Task: callback.oauth2
     */
    public function loginCallback(): void
    {
        /** @var Login $login */
        $login = $this->grav['login'];

        /** @var OAuth2 $oauth2 */
        $oauth2 = $this->grav['oauth2'];

        /** @var Session $session */
        $session = $this->grav['session'];

        $provider_name = $session->oauth2_provider;
        $login_redirect = $session->redirect_after_login;

        /** @var Language $t */
        $t = $this->grav['language'];
        /** @var Message $messages */
        $messages = $this->grav['messages'];

        $is_valid = $oauth2->isValidProvider($provider_name);

        $this->debug("provider: $provider_name - redirect: $login_redirect - is_valid: $is_valid");

        if ($provider_name && $oauth2->isValidProvider($provider_name)) {
            $state = isset($_GET['state']) ? htmlspecialchars(strip_tags($_GET['state']), ENT_QUOTES, 'UTF-8') : null;
            if (empty($state)) {
                $state = isset($_POST['state']) ? htmlspecialchars(strip_tags($_POST['state']), ENT_QUOTES, 'UTF-8') : null;
            }

            $this->debug("sent state: $state, stored state: $session->oauth2_state");

            if (empty($state) || ($state !== $session->oauth2_state)) {
                unset($session->oauth2_state);
                $this->debug("Error: $session->oauth2_state != $state");
                $messages->add($t->translate('PLUGIN_LOGIN.LOGIN_FAILED'), 'error');
            } else {
                // Fire Login process.
                $event = $login->login(
                    ['rememberme' => true],
                    ['admin' => $this->isAdmin(), 'remember_me' => true, 'oauth2' => true, 'provider' => $provider_name],
                    ['authorize' => $this->isAdmin() ? 'admin.login' : 'site.login', 'return_event' => true]);

                // Note: session variables have been reset!
                $user = $event->getUser();
                if ($user->authorized) {
                    $event->defMessage('PLUGIN_LOGIN.LOGIN_SUCCESSFUL', 'info');

                    if ($this->isAdmin()) {
                        $event->defRedirect($login_redirect ?? '/');
                    } else {
                        $event->defRedirect(
                            $login_redirect
                                ?: LoginPlugin::defaultRedirectAfterLogin()
                                ?: $this->grav['uri']->referrer('/')
                        );
                    }
                } elseif ($user->authenticated) {
                    $event->defMessage('PLUGIN_LOGIN.ACCESS_DENIED', 'error');

                    if ($this->isAdmin()) {
                        $event->defRedirect($login_redirect ?? '/');
                    } else {
                        $event->defRedirect($this->grav['config']->get('plugins.login.route_unauthorized', '/'));
                    }
                } else {
                    $event->defMessage('PLUGIN_LOGIN.LOGIN_FAILED', 'error');

                    if ($this->isAdmin()) {
                        $event->defRedirect($login_redirect ?? '/');
                    } else {
                        $event->defRedirect($this->grav['config']->get('plugins.login.route', '/'));
                    }
                }

                $message = $event->getMessage();
                if ($message) {
                    /** @var Debugger $debugger */
                    $debugger = $this->grav['debugger'];
                    $debugger->addMessage($t->translate($message), 'debug');

                    $messages->add($t->translate($message), $event->getMessageType());
                }

                $redirect = $event->getRedirect();
                if ($redirect) {
                    $this->grav->redirect($redirect, $event->getRedirectCode());
                }
            }
        } else {
            $this->grav->redirect($login_redirect ?? '/');
        }

        $uri = $this->grav['uri'];
        $redirect = $uri->url(true);
        $this->grav->redirect($redirect);
    }

    function loginDataDelete()
    {
        /** @var Login $login */
        $login = $this->grav['login'];

        /** @var OAuth2 $oauth2 */
        $oauth2 = $this->grav['oauth2'];

        /** @var Session $session */
        $session = $this->grav['session'];

        $provider_name = $session->oauth2_provider;
        $login_redirect = $session->redirect_after_login;

        /** @var Language $t */
        $t = $this->grav['language'];
        /** @var Message $messages */
        $messages = $this->grav['messages'];

        $is_valid = $oauth2->isValidProvider($provider_name);

        $this->debug("provider: $provider_name - redirect: $login_redirect - is_valid: $is_valid");
    }

    /**
     * [onUserLoginAuthenticate:20000] "Enforce OAuth2": denies every login but an OAuth2 one on that side (site, or
     * admin with Admin2), before the Login plugin checks passwords, magic links or logins after registration. Logins
     * with a "remember me" cookie still pass, as OAuth2 logins set it too.
     */
    public function userLoginEnforceOAuth2(UserLoginEvent $event): void
    {
        $admin = (bool)$event->getOption('admin');
        if ($event->getOption('oauth2') || $event->getOption('remember_me_login') || !$this->isOAuth2Enforced($admin)) {
            return;
        }

        $this->grav['log']->notice('Login OAuth2: denied ' . ($admin ? 'an admin' : 'a site') . ' login without OAuth2 (Enforce OAuth2)');
        $event->setMessage($this->grav['language']->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_DENIED'), 'error');
        $event->setStatus($event::AUTHENTICATION_FAILURE);
        $event->stopPropagation();
    }

    /**
     * [onUserLoginAuthorize:10] "Enforce OAuth2" in the admin: also denies site logins with a password or a magic link
     * of accounts with admin or API access. Their site session would open the admin anyway: the API accepts it (session
     * passthrough), as does the classic admin with a shared session (system.session.split off). Logins with a "remember
     * me" cookie still pass, as on the site (userLoginEnforceOAuth2()).
     */
    public function userLoginEnforceAdminOAuth2(UserLoginEvent $event): void
    {
        if ($this->admin || $event->getOption('admin') || $event->getOption('oauth2') || $event->getOption('remember_me_login')
            || !$this->isOAuth2Enforced(true)) {
            return;
        }
        $user = $event->getUser();
        if (!$user || !static::hasAdminAccess($user)) {
            return;
        }

        $this->grav['log']->notice('Login OAuth2: denied a site login without OAuth2 of an account with admin access (Enforce OAuth2 in the admin)');
        $event->setMessage($this->grav['language']->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_ADMIN_ACCOUNT'), 'error');
        $event->setStatus($event::AUTHORIZATION_DENIED);
        $event->stopPropagation();
    }

    /**
     * Whether an account can use the admin: the classic admin (admin.login, admin.super) or the API that Admin2 uses
     * (api.access, api.super). Asked as if its login were authorized already: until then Grav only answers login
     * actions (and its "test" scope, which would skip that, only exists for Flex accounts).
     */
    protected static function hasAdminAccess(UserInterface $user): bool
    {
        $authorized = $user->authorized;
        $user->authorized = true;
        try {
            foreach (['admin.login', 'admin.super', 'api.access', 'api.super'] as $action) {
                if ($user->authorize($action)) {
                    return true;
                }
            }

            return false;
        } finally {
            $user->authorized = $authorized;
        }
    }

    /**
     * [onApiInvitationAccepted] "Enforce OAuth2" in the admin: an Admin2 invitation doesn't log its user in, who logs
     * in with OAuth2 instead (matched by email). The API has created the account already, and gives no tokens.
     */
    public function onApiInvitationAccepted(Event $event): void
    {
        if ($this->isOAuth2Enforced(true)) {
            throw new ApiForbiddenException($this->grav['language']->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_INVITATION'));
        }
    }

    /**
     * Whether only OAuth2 logins are allowed on a side: while its setting is on and it applies (OAuth2::
     * enforceNotApplying()), i.e. only while the side has at least one active provider (enabled and with its client
     * ID), so a provider disabled or not configured can't lock anyone out; for the admin also only while OAuth2 is
     * enabled there. Satellite plugins (e.g. login-oauth2-extras) add their providers as soon as they're enabled, so
     * the client ID is checked there.
     */
    protected function isOAuth2Enforced(bool $admin): bool
    {
        if (!$this->config->get('plugins.login-oauth2.' . ($admin ? 'admin.' : '') . 'enforce_oauth2')) {
            return false;
        }

        // Admin logins outside the classic admin come from Admin2 (API): their providers are the admin ones, with the
        // ones of satellite plugins.
        $oauth2 = $admin && !$this->admin ? $this->adminProviders() : $this->grav['oauth2'];

        return OAuth2::enforceNotApplying($admin, (array)$this->config->get('plugins.login-oauth2'), $oauth2->getProviders()) === null;
    }

    /**
     * The admin providers outside the classic admin: the service of the SSO bridge once it's built, else one of its
     * own, so the site's service stays in place (e.g. for a site login checked against the admin's "Enforce OAuth2").
     */
    private function adminProviders(): OAuth2
    {
        if ($this->adminOAuth2 instanceof OAuth2) {
            return $this->adminOAuth2;
        }
        if (!$this->adminProviders instanceof OAuth2) {
            $this->adminProviders = OAuth2::registered(true);
        }

        return $this->adminProviders;
    }

    public function userLoginAuthenticate(UserLoginEvent $event): void
    {
        // Second parameter of Login::login() call.
        $options = $event->getOptions();

        if (isset($options['oauth2'])) {
            $code = isset($_GET['code']) ? htmlspecialchars(strip_tags($_GET['code']), ENT_QUOTES, 'UTF-8') : null;
            if (!$code) {
                $code = isset($_POST['code']) ? htmlspecialchars(strip_tags($_POST['code']), ENT_QUOTES, 'UTF-8') : null;
            }

            $provider_name = $options['provider'];

            $provider = ProviderFactory::create($provider_name, $options);

            try {
                // Try to get an access token (using the authorization code grant)
                $token = $provider->getAccessToken('authorization_code', ['code' => $code]);

                // We got an access token, let's now get the user's details
                $user = $provider->getResourceOwner($token);
                $userdata = $provider->getUserData($user);

                $userdata_event = new Event(
                    [
                        'userdata' => $userdata,
                        'oauth2user' => $user,
                        'provider' => $provider,
                        'token' => $token
                    ]
                );
                $this->grav->fireEvent('onOAuth2Userdata', $userdata_event);
                // Set again with any event-based modifications
                $userdata = $userdata_event['userdata'];

                $username_event = new Event(
                    [
                        'userdata' => $userdata,
                        'oauth2user' => $user,
                        'provider' => $provider,
                        'token' => $token
                    ]
                );
                // Get username from an event to allow you to modify oauth2 filename
                $this->grav->fireEvent('onOAuth2Username', $username_event);

                $username = $username_event['username'];

                /** @var UserCollectionInterface $accounts */
                $accounts = $this->grav['accounts'];
                $grav_user = $accounts->load($username);

                // If username cannot be found, fall back to email address.
                $exists = $grav_user->exists();
                if (!$exists) {
                    $found_user = $accounts->find($userdata['email'], ['email']);
                    if ($found_user->exists()) {
                        $grav_user = $found_user;
                        $exists = true;
                    }
                }

                // Make sure we're using the same provider, multiple providers are not supported.
                if ($exists) {
                    $provider_test = $grav_user->get('provider');
                    if ($provider_test && $provider_test !== $provider_name) {
                        throw new RuntimeException($this->translate('PLUGIN_LOGIN_OAUTH2.ERROR_EXISTING_ACCOUNT', $provider_test));
                    }
                }

                if ($this->grav['oauth2']->requireGravUser() && !$exists) {
                    throw new RuntimeException($this->translate('PLUGIN_LOGIN_OAUTH2.ERROR_NO_ACCOUNT', $username));
                }

                // Add token to user
                $grav_user->set('token', json_encode($token, JSON_THROW_ON_ERROR));

                // Set provider
                $grav_user->set('provider', $provider_name);

                // Default Access levels
                $current_access = $grav_user->get('access');
                if (!$current_access) {
                    $access = $this->config->get('plugins.login-oauth2.default_access_levels.access', []);
                    if (count($access) > 0) {
                        $grav_user->set('access', $access);
                    }
                }

                // Remove Provider Userdata if configured
                if (!$this->config->get('plugins.login-oauth2.store_provider_data', false)) {
                    unset($userdata[$provider_name]);
                }

                // getUserData() returns null for values the provider didn't send (e.g. scope not granted). Skip them
                // in this main array, so they don't erase existing values of the Grav user (e.g. its email); the
                // provider's sub-array is still replaced as a whole by merge().
                $userdata = array_filter($userdata, static function ($value) {
                    return $value !== null;
                });

                $grav_user->merge($userdata);

                // Group sync of the provider (Generic provider), before Grav checks the user's access
                if (method_exists($provider, 'syncGroups')) {
                    $provider->syncGroups($grav_user);
                }

                // Default Groups, after the group sync: also for users it left without any
                $current_groups = $grav_user->get('groups');
                if (!$current_groups) {
                    $groups = $this->config->get('plugins.login-oauth2.default_groups', []);
                    if (count($groups) > 0) {
                        $grav_user->set('groups', $groups);
                    }
                }

                $this->debug("userdata: " . json_encode($userdata));

                // Save Grav user if so configured
                if ($this->grav['oauth2']->saveGravUser()) {
                    $grav_user->save();
                }

                $event->setUser($grav_user);

                // Do something...
                $event->setStatus($event::AUTHENTICATION_SUCCESS);
                $event->stopPropagation();
            } catch (Exception $e) {
                $event->setMessage($this->translate('PLUGIN_LOGIN_OAUTH2.OAUTH2_LOGIN_FAILED', ucfirst($provider_name), $e->getMessage()), 'error');
                $event->setStatus($event::AUTHENTICATION_FAILURE);
            }
        }
    }

    public function onOAuth2Username(Event $event): void
    {
        $userdata = $event['userdata'];
        $provider = $event['provider'];
        $provider_name = strtolower($provider->getName());

        // Providers that build their own collision-free username (Generic provider)
        if (method_exists($provider, 'getUsername')) {
            $event['username'] = $provider->getUsername($userdata);
            $event->stopPropagation();

            return;
        }

        $username_parts = [$provider_name, $userdata['id'], $userdata['login']];
        $username = implode('.', $username_parts);

        $event['username'] = $username;

        $event->stopPropagation();
    }

    public function userLoginFailure(UserLoginEvent $event): void
    {
        // This gets fired if user fails to log in.
    }

    public function userLogin(UserLoginEvent $event): void
    {

    }

    public function userLogout(UserLoginEvent $event): void
    {
        // This gets fired on user logout.
    }

    /**
     * [onApiLoginProviders] Advertise the admin OAuth providers to admin-next.
     *
     * Reuses the same `admin.enabled` master switch and `admin.providers.*`
     * config as the classic admin OAuth flow, so enabling SSO for the SvelteKit
     * admin is the same toggle admins already know.
     */
    public function onApiLoginProviders(Event $event): void
    {
        if (!$this->config->get('plugins.login-oauth2.admin.enabled')) {
            return;
        }

        // Go through the shared admin service rather than a local instance, so
        // providers contributed by satellite plugins (login-oauth2-extras and
        // friends) get advertised too — they hang off their own config trees
        // and this plugin can't see them any other way.
        $oauth2 = $this->ensureAdminOAuth2();

        $providers = $event['providers'] ?? [];
        foreach ($oauth2->getProviders() as $name => $options) {
            $providers[] = [
                'id'     => (string) $name,
                'label'  => (string) ($options['label'] ?? '') ?: static::providerLabel((string) $name),
                'icon'   => (string) $name,
                'plugin' => 'login-oauth2',
            ];
        }
        $event['providers'] = $providers;

        // "Enforce OAuth2" in effect: the admin login screen hides its password form (show_password_form, reported by
        // API versions that have the setting).
        if ($this->isOAuth2Enforced(true)) {
            $event['show_password_form'] = false;
        }
    }

    /**
     * Display label for a provider id.
     *
     * Provider classes carry a properly-cased `$name` ('LinkedIn', 'Keycloak'),
     * which reads better on a button than `ucfirst()` on the config key. Read
     * as a class default so no provider gets constructed just to render the
     * login screen — the League provider classes validate their options in the
     * constructor and would throw here on partially-configured credentials.
     */
    private static function providerLabel(string $name): string
    {
        $classname = 'Grav\\Plugin\\Login\\OAuth2\\Providers\\' . ucfirst($name) . 'Provider';

        if (class_exists($classname)) {
            try {
                $defaults = (new ReflectionClass($classname))->getDefaultProperties();
                if (!empty($defaults['name'])) {
                    return (string) $defaults['name'];
                }
            } catch (ReflectionException $e) {
                // Fall through to the humanized id.
            }
        }

        return ucfirst($name);
    }

    /**
     * [onApiLoginStart] Build the provider authorization URL for admin-next.
     *
     * Stores the CSRF state + provider in the session (carried across the
     * provider round-trip by the browser's session cookie) and points the
     * provider at the API callback the bridge supplied, rather than the classic
     * Twig callback route.
     */
    public function onApiLoginStart(Event $event): void
    {
        if (!$this->config->get('plugins.login-oauth2.admin.enabled')) {
            return;
        }

        $provider_name = (string) $event['provider'];
        $oauth2 = $this->ensureAdminOAuth2();
        if (!$oauth2->isValidProvider($provider_name)) {
            return;
        }

        $provider = ProviderFactory::create($provider_name, [
            'redirectUri' => (string) $event['callback_url'],
        ]);

        /** @var Session $session */
        $session = $this->grav['session'];
        $session->oauth2_state = $provider->getState();
        $session->oauth2_provider = $provider_name;

        $event['redirect'] = $provider->getAuthorizationUrl();
        $event->stopPropagation();
    }

    /**
     * [onApiLoginCallback] Exchange the provider's code for a Grav user.
     *
     * Validates the CSRF state, then fires the SAME `Login::login()` chain the
     * classic flow uses so the existing `userLoginAuthenticate()` handler does
     * the code→token→userdata→user mapping unchanged. The API plugin takes the
     * returned user from here and mints the JWT pair.
     */
    public function onApiLoginCallback(Event $event): void
    {
        if (!$this->config->get('plugins.login-oauth2.admin.enabled')) {
            return;
        }

        $provider_name = (string) $event['provider'];
        $oauth2 = $this->ensureAdminOAuth2();
        if (!$oauth2->isValidProvider($provider_name)) {
            return;
        }

        $request = $event['request'];
        $query = $request->getQueryParams();

        /** @var Session $session */
        $session = $this->grav['session'];

        // CSRF: the returned state must match the one we stored at start.
        $state = isset($query['state']) ? htmlspecialchars(strip_tags((string) $query['state']), ENT_QUOTES, 'UTF-8') : '';
        if ($state === '' || $state !== ($session->oauth2_state ?? null)) {
            unset($session->oauth2_state, $session->oauth2_provider);
            $event['error'] = 'sso_state_mismatch';
            return;
        }

        // The shared userLoginAuthenticate() handler reads the auth code from
        // $_GET['code']; make sure it's populated from the PSR-7 request.
        if (isset($query['code'])) {
            $_GET['code'] = htmlspecialchars(strip_tags((string) $query['code']), ENT_QUOTES, 'UTF-8');
        }

        /** @var Login $login */
        $login = $this->grav['login'];

        // Mirror the classic oauth2 login() call. `authorize` is left empty —
        // the API plugin runs its own access gate (admin.super / admin.login /
        // api.access) after this returns, matching how /auth/token behaves.
        $loginEvent = $login->login(
            ['rememberme' => false],
            [
                'admin'       => true,
                'oauth2'      => true,
                'provider'    => $provider_name,
                'redirectUri' => (string) $event['callback_url'],
            ],
            ['authorize' => [], 'return_event' => true]
        );

        unset($session->oauth2_state, $session->oauth2_provider);

        $user = $loginEvent->getUser();
        if ($user && $user->authenticated) {
            $event['user'] = $user;
        } else {
            $message = $loginEvent->getMessage();
            $event['error'] = $message ? $this->grav['language']->translate($message) : 'sso_login_failed';
        }
        $event->stopPropagation();
    }

    /**
     * Re-register the global `oauth2` service in admin mode for the current
     * (API) request. On an `/api/*` request `isAdmin()` is false, so the
     * service the plugin registered at init reads frontend providers; the
     * provider classes read their credentials/scope from whichever subtree this
     * service selects, so the admin-next SSO path forces the `.admin` subtree.
     *
     * Satellite plugins (login-oauth2-extras and friends) keep their providers
     * in their own `plugins.login-oauth2-<x>.admin.providers` config and add
     * them to this service at init — which is too early here, because the
     * service they added to gets replaced above. `onOAuth2ProvidersRegister`
     * gives them a second, correctly-timed chance; without it their providers
     * are invisible to admin-next entirely (#13).
     *
     * Built once per request: rebuilding would drop everything the satellites
     * registered on the previous pass.
     */
    private function ensureAdminOAuth2(): OAuth2
    {
        if ($this->adminOAuth2 instanceof OAuth2) {
            return $this->adminOAuth2;
        }

        $this->adminOAuth2 = new OAuth2(true);
        unset($this->grav['oauth2']);
        $oauth2 = $this->adminOAuth2;
        $this->grav['oauth2'] = static function () use ($oauth2) {
            return $oauth2;
        };

        // Register after the swap: satellite handlers (and the provider classes
        // they construct) read `grav['oauth2']->isAdmin()` to pick their config
        // subtree, so the admin instance has to be in place first.
        return $this->adminOAuth2->registerProviders();
    }

    /**
     * @param mixed ...$args
     * @return string
     */
    private function translate(...$args): string
    {
        /** @var Language $language */
        $language = $this->grav['language'];

        return $language->translate($args);
    }

    private function debug($message): void
    {
        if ($this->debug) {
            $this->grav['log']->debug($message);
        }
    }
}
