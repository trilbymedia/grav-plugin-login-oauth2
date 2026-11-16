# v2.4.0
## 11/03/2026

1. [](#new)
    * Enforce OAuth2: new settings for the site and the admin (Admin2 and classic admin) that only allow logins through an OAuth2 provider. Passwords, magic links and passkeys are denied, and the login pages hide them
    * Enforce OAuth2 needs the Login plugin's "Remember me" off, and in the admin also the API plugin's Session Authentication, because both let users in without OAuth2. The settings and the command refuse to turn it on otherwise, and a warning shows in the settings and on Admin2's dashboard if one is turned on later
    * New commands: `bin/plugin login-oauth2 status`, `enforce-oauth2 on|off|status` (e.g. to get back in when locked out) and `provider enable|disable|verify-certificate`
    * `enforce-oauth2 on --sign-out` signs out the users who may be logged in without OAuth2: everyone for the site, accounts with admin access for the admin (Login 3.13.0 or newer)
    * Generic provider: "Verify Certificate" can be turned off for testing, e.g. with a self-signed certificate
1. [](#improved)
    * The Callback URIs section lists the admin callback URI of each provider, including the enabled providers of other plugins such as login-oauth2-extras
1. [](#bugfix)
    * The Admin Callback URI showed the classic admin's URI also for Admin2, whose logins return to the API plugin
    * A provider in the settings that the plugin doesn't have (e.g. a typo) no longer breaks the login page: it's ignored with a warning in the log
    * Generic provider: a "Provider Groups to Sync" entry that isn't a group name or pattern (e.g. `yes` or `2.0` without quotes) is ignored with a warning, instead of failing the login or matching other groups. If no valid entry is left, the group sync is skipped
    * Admin2's login button for GitHub reads "GitHub" instead of "Github"

# v2.3.0
## 11/01/2026

1. [](#new)
    * Added a Generic provider to log in with any OAuth2 or OpenID Connect identity provider, offering great flexibility through many configuration options, including group sync
1. [](#improved)
    * Reorganized the plugin settings into clearly named tabs and sections
    * Separate "Save Grav user" settings for site and admin logins (admin follows the site setting until set)
    * Separate "Require Existing Grav user" settings for site and admin logins (admin follows the site setting until set)
1. [](#bugfix)
    * Values a provider doesn't send (e.g. a scope that wasn't granted) no longer erase the email address, name or other values of an existing Grav user
    * Added and fixed several missing help texts of certain options
    * Generic provider: a missing or invalid groups claim counts as no groups, so access the identity provider took away is removed, and a group name prefix without letters or digits skips the sync instead of matching every Grav group
    * Generic provider: the Grav username is built from a hash of the token endpoint and the user ID, so different identity providers or unusual claim values can't map to the same account, and claims that aren't plain text are ignored
    * Updated the bundled Guzzle libraries (guzzle 7.16.1, psr7 2.13.2, promises 2.6.0) to clear their open security advisories

# v2.2.8
## 08/13/2026

1. [](#bugfix)
    * Fixed providers from add-on plugins such as Login OAuth2 Extras (Keycloak, Okta, Azure, GitLab, and others) not appearing on the new admin's login screen ([#13](https://github.com/trilbymedia/grav-plugin-login-oauth2-extras/issues/13)).
    * Provider buttons now use the provider's proper name, so LinkedIn no longer reads as "Linkedin".

# v2.2.7
## 08/01/2026

1. [](#new)
    * OAuth providers can now sign you in to the new Grav admin, not just the classic admin and the site frontend ([#52](https://github.com/trilbymedia/grav-plugin-login-oauth2/issues/52)).

# v2.2.6
## 05/01/2026

1. [](#improved)
    * Added 1.7|2.0 compatibility flags

# v2.2.5
## 10/28/2024

1. [](#improved)
   * Vendor updates for compatibility improvements 

# v2.2.4
## 05/09/2023

1. [](#improved)
   * Removed a deprecation message for PHP 8.2+

# v2.2.3
## 03/06/2023

1. [](#bugfix)
   * Fixed an issue with default `access` and `groups` configuration not being merged into user object correctly.

# v2.2.2
## 01/02/2023

1. [](#bugfix)
   * Set `composer.json` to use `^7.3.6 || ^8.0` + downgraded libs for PHP compatibility

# v2.2.1
## 12/28/2022

1. [](#bugfix)
   * Fix for `remember_me` functionality not working with OAuth2 providers (always defaults to `true`)

# v2.2.0
## 12/28/2022

1. [](#improved)
   * Improved Exception to show any invalid providers [#42](https://github.com/trilbymedia/grav-plugin-login-oauth2/pull/42)
   * CSS has been improved for better consistency of layout
   * Login button text moved to Lang file for easier modification and translation
   * Added some debug information for async/post calls to callback
   * Updated Vendor libraries to latest   
1. [](#bugfix)
   * Don't fail with exception when provider name is invalid or empty
   * Change `self::getCallbackUrl()` to `static::getCallbackUrl()` to support overriding the method
   * Properly support after login redirect logic (was not working as intended)

# v2.1.1
## 05/24/2021

1. [](#bugfix)
    * Fixed Facebook login never showing up [#40](https://github.com/trilbymedia/grav-plugin-login-oauth2/issues/40)

# v2.1.0
## 05/13/2021

1. [](#new)
   * Require **Grav 1.7.0**
   * Added configuration option to require existing Grav user
   * Assign OAuth2 to existing user [#35](https://github.com/trilbymedia/grav-plugin-login-oauth2/issues/35)
1. [](#improved)
   * Code improvements and updates
   * Only enable configured oauth2 providers
1. [](#bugfix)
    * Google: non-hosted google accounts cannot be used [#25](https://github.com/trilbymedia/grav-plugin-login-oauth2/issues/25)
    * Fixed missing translations in the template file [#37](https://github.com/trilbymedia/grav-plugin-login-oauth2/pull/37)
    * Fixed login buttons exceeding available width on mobile screens [#31](https://github.com/trilbymedia/grav-plugin-login-oauth2/pull/31)
    * Fixed login redirects in admin plugin

# v2.0.5
## 12/02/2020

1. [](#improved)   
    * Removed user scope from github by default [#36](https://github.com/trilbymedia/grav-plugin-login-oauth2/pull/36)

# v2.0.4
## 06/03/2020

1. [](#improved)    
    * If no provider is enabled for site connections, simply omit the template [#28](https://github.com/trilbymedia/grav-plugin-login-oauth2/pull/28)
    * Vendor updates
    * Use `UserLogin::defaultRedirectAfterLogin()` helper method

# v2.0.3
## 02/24/2019

1. [](#improved)
    * Added `copy-to-clipboard` support for Callback URIs
    * Added support for providers that callback via POST (ie, Apple)
    * Fixed issues with saving in Admin 1.7 with strict form validation

# v2.0.2
## 04/28/2019

1. [](#improved)
    * Removed configurable callback URL.

# v2.0.1
## 04/28/2019

1. [](#bugfix)
    * Fixed login version requirements (`~3.0`) [#17](https://github.com/trilbymedia/grav-plugin-login-oauth2/issues/17)

# v2.0.0
## 04/26/2019

1. [](#new)
    * Support for OAuth2 login via Admin plugin
    * Support for default groups
1. [](#improved)
    * Updated vendor libraries to use latest Google / LinkedIn providers
1. [](#bugfix)
    * Fix bad redirect on login error

# v1.0.1
## 06/07/2018

1. [](#new)
    * Added new Hosted Domain option for Google Provider that allows to limit the login per domain [#1](https://github.com/trilbymedia/grav-plugin-login-oauth2/issues/1)

# v1.0.0
##  05/18/2018

1. [](#new)
    * Plugin released
