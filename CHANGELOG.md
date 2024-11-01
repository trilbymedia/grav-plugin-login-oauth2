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
