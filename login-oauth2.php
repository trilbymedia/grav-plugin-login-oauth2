<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Exception;
use Grav\Common\Data\Data;
use Grav\Common\Debugger;
use Grav\Common\Language\Language;
use Grav\Common\Plugin;
use Grav\Common\Session;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Common\Utils;
use Grav\Plugin\Api\Exceptions\ForbiddenException as ApiForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException as ApiValidationException;
use Grav\Plugin\Login\Events\UserLoginEvent;
use Grav\Plugin\Login\Login;
use Grav\Plugin\Login\OAuth2\OAuth2;
use Grav\Plugin\Login\OAuth2\ProviderFactory;
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

    /** @var OAuth2|null The admin's service outside the classic admin (adminProviders()). */
    private $adminOAuth2;

    /** @var bool Whether it is the request's "oauth2" service yet (ensureAdminOAuth2()). */
    private $adminInstalled = false;

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
                ['closeDeniedLoginRoutes', -1000],
            ],
            // Also while "Enabled in Admin" is off, which onPluginsInitialized() stops at in the classic admin.
            'onAdminSave' => ['onAdminSave', 0],
            'onApiDashboardNotifications' => ['onApiDashboardNotifications', 0],
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

    /** Refuse a save that turns enforcement on while a setting it requires off is on for this request. */
    public function onAdminSave(Event $event): void
    {
        $object = $event['object'] ?? null;
        if (!$object instanceof Data || !$this->isOwnConfig($object)) {
            return;
        }

        $refused = [];
        foreach (['enforce_oauth2' => false, 'admin.enforce_oauth2' => true] as $key => $admin) {
            if ($object->get($key) && !$this->config->get('plugins.login-oauth2.' . $key) && ($unmet = OAuth2::enforceUnmet($admin))) {
                $refused[$key] = $this->translate(
                    'PLUGIN_LOGIN_OAUTH2.ENFORCE_REQUIREMENTS_REFUSED' . (count($unmet) > 1 ? '_PLURAL' : ''),
                    OAuth2::requirementNames($unmet)
                );
            }
        }
        if (!$refused) {
            return;
        }

        $admin = $this->grav['admin'] ?? null;
        if (!$admin instanceof \Grav\Plugin\Admin\Admin && class_exists(ApiValidationException::class)) {
            $errors = [];
            foreach ($refused as $field => $message) {
                $errors[] = ['field' => $field, 'message' => $message];
            }

            throw new ApiValidationException(implode(' ', array_unique($refused)), $errors);
        }

        foreach ($refused as $key => $message) {
            $object->set($key, false);
            if ($admin) {
                $admin->setMessage($message, 'error');
            }
        }
    }

    /** Identify this plugin's settings by file, or the classic admin route. */
    protected function isOwnConfig(Data $object): bool
    {
        $file = $object->file();
        if ($file) {
            $name = str_replace('\\', '/', (string)$file->filename());

            return substr($name, -strlen('/plugins/login-oauth2.yaml')) === '/plugins/login-oauth2.yaml';
        }

        $admin = $this->grav['admin'] ?? null;

        return is_object($admin) && isset($admin->route) && trim((string)$admin->route, '/') === 'plugins/login-oauth2';
    }

    /** Add the enforcement warning to Admin2's dashboard. */
    public function onApiDashboardNotifications(Event $event): void
    {
        $message = OAuth2::enforceDashboardNotice();
        if ($message === null) {
            return;
        }

        $notifications = $event['notifications'] ?? [];
        $notifications['top'][] = [
            'id' => 'login-oauth2-enforce-unmet',
            'date' => date('c'),
            'level' => 'error',
            'icon' => 'shield-alert',
            'location' => ['top'],
            'message' => $message,
            'reappear_after' => '+1 day',
        ];
        $event['notifications'] = $notifications;
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

    /** Add OAuth2 buttons and hide site login methods denied by enforcement. */
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
            $user = $this->grav['user'] ?? null;
            if (!$user || !$user->get('authorized')) {
                $this->config->set('plugins.login.passkeys.enabled', false);
            }
        }
    }

    /** Redirect denied magic-link routes and hide Admin2's passkey sign-in. */
    public function closeDeniedLoginRoutes(): void
    {
        if ($this->isAdmin() || !isset($this->grav['oauth2'], $this->grav['login'])) {
            return;
        }

        $path = (string)$this->grav['uri']->path();
        if (strpos($path . '/', OAuth2::apiRoute() . '/auth/passkey/') === 0) {
            if ($this->isOAuth2Enforced(true)) {
                $this->config->set('plugins.login.passkeys.enabled', false);
            }

            return;
        }

        /** @var Login $login */
        $login = $this->grav['login'];
        // Login 3.5.0 or newer has getRoute(); the ones before have no magic links either.
        if (!method_exists($login, 'getRoute')) {
            return;
        }
        // Compared as the Login plugin routes them (LoginPlugin::initializeLogin()).
        if (($path === $login->getRoute('magic') || $path === $login->getRoute('magic_login')) && $this->isOAuth2Enforced(false)) {
            // After the tasks (denyMagicLinkRequest()), before the Login plugin's own handlers of these routes.
            $this->enable(['onPagesInitialized' => ['redirectMagicLinkRoute', 1000]]);
        }
    }

    /** Use a temporary redirect so disabling enforcement restores these routes. */
    public function redirectMagicLinkRoute(): void
    {
        $this->grav->redirectLangSafe($this->grav['login']->getRoute('login') ?: '/', 302);
    }

    /** Deny a valid magic-link request before Login sends mail. */
    public function denyMagicLinkRequest(Event $event): void
    {
        /** @var Login $login */
        $login = $this->grav['login'];
        if ($this->isAdmin() || !method_exists($login, 'getRoute') || !$this->magicLinkRequested() || !$this->isOAuth2Enforced(false)) {
            return;
        }

        $this->grav['log']->notice('Login OAuth2: denied a magic-link request (Enforce OAuth2)');
        $this->grav['messages']->add($this->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_MAGIC_LINK_DENIED'), 'error');
        $event->stopPropagation();
        $this->grav->redirectLangSafe($login->getRoute('login') ?: '/', 303);
    }

    /** Match Login's enabled setting and nonce check before denying the request. */
    private function magicLinkRequested(): bool
    {
        $nonce = $_POST['magic-form-nonce'] ?? null;

        return $this->config->get('plugins.login.magic_link.enabled', false) && is_string($nonce) && Utils::verifyNonce($nonce, 'magic-form');
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
                    /** @var \Psr\Http\Message\ServerRequestInterface $request */
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

    /** Deny non-OAuth2 logins; existing remember-me cookies still pass. */
    public function userLoginEnforceOAuth2(UserLoginEvent $event): void
    {
        $admin = (bool)$event->getOption('admin');
        if ($event->getOption('oauth2') || $event->getOption('remember_me_login') || !$this->isOAuth2Enforced($admin)) {
            return;
        }

        $this->grav['log']->notice('Login OAuth2: denied ' . ($admin ? 'an admin' : 'a site') . ' login without OAuth2 (Enforce OAuth2)');
        $event->setMessage($this->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_DENIED'), 'error');
        $event->setStatus($event::AUTHENTICATION_FAILURE);
        $event->stopPropagation();
    }

    /** Deny site logins of admin accounts after authentication, without revealing correct passwords. */
    public function userLoginEnforceAdminOAuth2(UserLoginEvent $event): void
    {
        if ($this->admin || $event->getOption('admin') || $event->getOption('oauth2') || $event->getOption('remember_me_login')
            || !$this->isOAuth2Enforced(true)) {
            return;
        }
        $user = $event->getUser();
        if (!OAuth2::hasAdminAccess($user)) {
            return;
        }

        $this->grav['log']->notice('Login OAuth2: denied a site login without OAuth2 of an account with admin access (Enforce OAuth2 in the admin)');
        // The other logins come with something only the user has (the emailed link, the passkey): they're told why.
        if ((string)($event->getCredential('password') ?? '') === '') {
            $event->setMessage($this->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_ADMIN_ACCOUNT'), 'error');
        }
        $event->setStatus($event::AUTHORIZATION_DENIED);
        $event->stopPropagation();
    }

    /** Keep the invited account but require an OAuth2 login instead of issuing tokens. */
    public function onApiInvitationAccepted(Event $event): void
    {
        if ($this->isOAuth2Enforced(true)) {
            throw new ApiForbiddenException($this->translate('PLUGIN_LOGIN_OAUTH2.ENFORCE_OAUTH2_INVITATION'));
        }
    }

    /** Check built-in providers first; a failing satellite plugin must not disable enforcement. */
    protected function isOAuth2Enforced(bool $admin): bool
    {
        $config = (array)$this->config->get('plugins.login-oauth2');
        if (empty($admin ? ($config['admin']['enforce_oauth2'] ?? null) : ($config['enforce_oauth2'] ?? null))) {
            return false;
        }

        $why = OAuth2::enforceNotApplying($admin, $config, OAuth2::enabledProviders($admin, $config));
        if ($why !== 'no_provider') {
            return $why === null;
        }

        try {
            // Admin logins outside the classic admin come from Admin2 (API): their providers are the admin ones, with
            // the ones of satellite plugins.
            $oauth2 = $admin && !$this->admin ? $this->adminProviders() : $this->grav['oauth2'];

            return (bool)OAuth2::configured($oauth2->getProviders());
        } catch (\Throwable $e) {
            $this->grav['log']->error(sprintf("Login OAuth2: 'Enforce OAuth2' (%s) stays in effect although the providers of other plugins can't be registered: %s", $admin ? 'admin' : 'site', $e->getMessage()));

            return true;
        }
    }

    /** Register admin providers once, preserving the site's service. */
    private function adminProviders(): OAuth2
    {
        if (!$this->adminOAuth2 instanceof OAuth2) {
            $this->adminOAuth2 = OAuth2::registered(true);
        }

        return $this->adminOAuth2;
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

                // If username cannot be found, fall back to email address. Only with one: not every provider sends one
                // (e.g. Instagram, or one of another plugin), and Grav's regular accounts would match an empty one to
                // the first account with an empty email.
                $exists = $grav_user->exists();
                $email = $userdata['email'] ?? null;
                if (!$exists && is_string($email) && $email !== '') {
                    $found_user = $accounts->find($email, ['email']);
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
                'label'  => (string) ($options['label'] ?? '') ?: OAuth2::providerLabel((string) $name),
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
        if ($user->authenticated) {
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
     * Built once per request (adminProviders()): rebuilding would drop
     * everything the satellites registered on the previous pass. Made the
     * request's service only once they all registered: if a satellite's
     * handler throws, the request keeps the service it had and the next call
     * registers again, instead of using one that misses providers.
     */
    private function ensureAdminOAuth2(): OAuth2
    {
        // Satellite handlers add to the instance the event hands them, which
        // is also the service while the event fires (OAuth2::registered()).
        // It then becomes the service for the provider classes the login
        // constructs, which read `grav['oauth2']->isAdmin()` to pick their
        // config subtree.
        $oauth2 = $this->adminProviders();
        if (!$this->adminInstalled) {
            unset($this->grav['oauth2']);
            $this->grav['oauth2'] = $oauth2;
            $this->adminInstalled = true;
        }

        return $oauth2;
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
