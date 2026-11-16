# Login OAuth2 Plugin

The **Login OAuth2** Plugin for [Grav CMS](http://github.com/getgrav/grav) allows user authentication against an OAuth2 Authentication Provider. This plugin makes use of [The League OAuth2 Client](http://oauth2-client.thephpleague.com/)

Currently the core plugin supports the following providers:

* **Generic** - any OAuth2 (or OpenID Connect) provider - <https://oauth2-client.thephpleague.com/usage/>
* **Facebook** - <https://developers.facebook.com/docs/facebook-login/web>
* **GitHub** - <https://developer.github.com/apps/building-oauth-apps/creating-an-oauth-app/>
* **Google** - <https://developers.google.com/identity/protocols/OpenIDConnect>
* **Instagram** - <https://www.instagram.com/developer/authentication/>
* **LinkedIn** - <https://developer.linkedin.com/docs/oauth2>

It's also possible to extend this plugin an create a new OAuth2 for specific providers.

## Callback URL

When you setup your OAuth provider application, you will need to provide a Callback URL.  Grav passes this as part of the OAuth2 request process, but it needs to match the configuration on the server side.  Typically, when logging in via the the frontend **site**, the URL will look something like this:

```text
http://yoursite.com/task:callback.oauth2
```

Where `http://yoursite.com` is the URL of your Grav-based site.  If you are using SSL, then this URL will be:

```text
https://yoursite.com/task:callback.oauth2
```

If you use the OAuth2 plugin to login to the **admin** side of your Grav site with Admin2, your callback URL is the SSO callback of the API plugin, with the name of the provider (e.g. `generic`):

```text
http://yoursite.com/api/v1/auth/sso/generic/callback
```

Where `/api` and `v1` are the route and version prefix of the API plugin. With the classic admin plugin, your callback URL will look like this:

```text
http://yoursite.com/admin/task:callback.oauth2
```

Where `http://yoursite.com/admin` is the URL you use to log into the admin plugin. The **General** tab of the plugin settings shows the callback URLs, for Admin2 one per provider. Again, if you have this under SSL, you would need to use `https://` rather than `http://`.  Some OAuth2 providers allow you to use provide multiple callback URLs, and in this case you can use the same application setup with multiple URLs, to work with the **site** and the **admin** and potentially `http://` and `https://` URLs.  However, some providers only allow a single callback URL, and in these cases you need to create multiple OAuth2 applications to handle each callback URL.

## Installation

Installing the Login OAuth2 plugin can be done in one of two ways. The GPM (Grav Package Manager) installation method enables you to quickly and easily install the plugin with a simple terminal command, while the manual method enables you to do so via a zip file.

### GPM Installation (Preferred)

The simplest way to install this plugin is via the [Grav Package Manager (GPM)](http://learn.getgrav.org/advanced/grav-gpm) through your system's terminal (also called the command line).  From the root of your Grav install type:

```bash
bin/gpm install login-oauth2
```

This will install the Login OAuth2 plugin into your `/user/plugins` directory within Grav. Its files can be found under `/your/site/grav/user/plugins/login-oauth2`.

### Manual Installation

To install this plugin, just download the zip version of this repository and unzip it under `/your/site/grav/user/plugins`. Then, rename the folder to `login-oauth2`. You can find these files on [GitHub](https://github.com/trilbymedia/grav-plugin-login-oauth2) or via [GetGrav.org](http://getgrav.org/downloads/plugins#extras).

You should now have all the plugin files under

```text
/your/site/grav/user/plugins/login-oauth2
```

Before configuring this plugin, you should copy the `user/plugins/login-oauth2/login-oauth2.yaml` to `user/config/plugins/login-oauth2.yaml` and only edit that copy.

### Admin Installation

If you use the admin plugin, you can install directly through the admin plugin by browsing the to `Plugins` in the sidebar menu and clicking on the `Add` button.

Configuring the Login OAuth2 plugin is as easy as navigating to the `Plugins` manager, and editing the configuration options.

## Configuration Options

The default configuration and an explanation of available options:

```yaml
enabled: true

built_in_css: true
button_style: row
enforce_oauth2: false
require_grav_user: false
save_grav_user: false
store_provider_data: true
default_access_levels:
  access:
    site:
      login: true
default_groups:

providers:
  generic:
    enabled: true
    label: ''
    client_id: ''
    client_secret: ''
    authorization_endpoint: ''
    token_endpoint: ''
    userinfo_endpoint: ''
    enforce_email_verified: true
    verify_peer: true
    userdata_id: 'sub'
    userdata_login: 'preferred_username'
    userdata_fullname: 'name'
    userdata_email: 'email'
    userdata_email_verified: 'email_verified'
    userdata_picture: 'picture'
    avatar_from_picture: true
    group_sync: false
    userdata_groups: 'groups'
    group_filter: []
    group_prefix: ''
    group_autocreate: false
    options:
      scope: ['openid', 'email', 'profile']
      scope_separator: 'space'
      pkce_method: 'S256'

  github:
    enabled: true
    client_id: ''
    client_secret: ''
    options:
      scope: ['user', 'user:email']

  instagram:
    enabled: true
    client_id: ''
    client_secret: ''
    options:
      scope: ['basic', 'likes', 'comments']
      host: 'https://api.instagram.com'

  facebook:
    enabled: true
    app_id: ''
    app_secret: ''
    options:
      scope: ['email', 'public_profile', 'user_hometown']
      graph_api_version: 'v2.10'

  google:
    enabled: true
    client_id: ''
    client_secret: ''
    hd: '*'
    options:
      scope: ['email', 'profile']
      avatar_size: 200

  linkedin:
    enabled: true
    client_id: ''
    client_secret: ''
    options:
      scope: ['r_basicprofile','r_emailaddress']



admin:
  enabled: false
  enforce_oauth2: false
  built_in_css: true
  button_style: row
  # require_grav_user: true   # not set: as require_grav_user
  # save_grav_user: true      # not set: as save_grav_user

  providers:
    generic:
      enabled: false
      label: ''
      client_id: ''
      client_secret: ''
      authorization_endpoint: ''
      token_endpoint: ''
      userinfo_endpoint: ''
      enforce_email_verified: true
      verify_peer: true
      userdata_id: 'sub'
      userdata_login: 'preferred_username'
      userdata_fullname: 'name'
      userdata_email: 'email'
      userdata_email_verified: 'email_verified'
      userdata_picture: 'picture'
      avatar_from_picture: true
      group_sync: false
      userdata_groups: 'groups'
      group_filter: []
      group_prefix: ''
      group_autocreate: false
      options:
        scope: ['openid', 'email', 'profile']
        scope_separator: 'space'
        pkce_method: 'S256'

    github:
      enabled: false
      client_id: ''
      client_secret: ''
      options:
        scope: ['user', 'user:email']

    instagram:
      enabled: false
      client_id: ''
      client_secret: ''
      options:
        scope: ['basic', 'likes', 'comments']
        host: 'https://api.instagram.com'

    facebook:
      enabled: false
      app_id: ''
      app_secret: ''
      options:
        scope: ['email', 'public_profile', 'user_hometown']
        graph_api_version: 'v2.10'

    google:
      enabled: false
      client_id: ''
      client_secret: ''
      hd: '*'
      options:
        scope: ['email', 'profile']
        avatar_size: 200

    linkedin:
      enabled: false
      client_id: ''
      client_secret: ''
      options:
        scope: ['r_basicprofile','r_emailaddress']
```

### Server Settings

| Key                          | Description                                                                                                                   | Values                                                 |
| :--------------------------- | :---------------------------------------------------------------------------------------------------------------------------- | :----------------------------------------------------- |
| enabled                      | Enables the plugin                                                                                                            | [default: `true`] \| `false`                           |
| built_in_css                 | Enables the plugin-provided CSS to be loaded                                                                                  | [default: `true`] \| `false`                           |
| button_style                 | If you want to provide your own custom CSS, feel free to disable the CSS provided by the plugin                               | [default: `row`] \| `square`                           |
| enforce_oauth2               | Only allow OAuth2 logins on the site, see [Enforce OAuth2](#enforce-oauth2)                                                   | `true` \| [default: `false`]                           |
| admin.enforce_oauth2         | Only allow OAuth2 logins in the admin (while `admin.enabled` is on), see [Enforce OAuth2](#enforce-oauth2)                    | `true` \| [default: `false`]                           |
| require_grav_user            | Allow oauth login only for existing users (site logins; admin logins: `admin.require_grav_user`)                              | `true` \| [default: `false`]                           |
| admin.require_grav_user      | Like `require_grav_user`, for admin logins, e.g. so only accounts you created can use the admin login                         | `true` \| `false` \| [default: as `require_grav_user`] |
| save_grav_user               | Store the grav user account as a local YAML account (site logins; admin logins: `admin.save_grav_user`)                       | `true` \| [default: `false`]                           |
| admin.save_grav_user         | Like `save_grav_user`, for admin logins (the new admin and admin group sync need it)                                          | `true` \| `false` \| [default: as `save_grav_user`]    |
| store_provider_data          | If storing a local Grav user, you can also store OAuth2 Provider data so its available in Grav                                | `true` \| [default: `false`]                           |
| default_access_levels.access | You can find more information on access levels in the <https://learn.getgrav.org/advanced/groups-and-permissions#permissions> | [default: `site: { login: true }`]                     |
| default_groups               | You can find more information on access levels in the <https://learn.getgrav.org/advanced/groups-and-permissions#permissions> | [default: `[]`]                                        |

### OAuth2 Providers

#### Generic

Works with any OAuth2 provider (e.g. an OpenID Connect identity provider) using the [Generic Provider](https://oauth2-client.thephpleague.com/usage/) of The League OAuth2 Client. The defaults of the user data keys match the standard OpenID Connect claims.

| Key                     | Description                                                                                                                                                                                                         | Values                                          |
| :---------------------- | :------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | :---------------------------------------------- |
| enabled                 | Enable or disable this specific provider. This stops its showing as an valid login option                                                                                                                           | [default: `true`] \| `false`                    |
| label                   | The text of the login button, e.g. the name of your identity provider                                                                                                                                               | [default: `Generic`] \| `<string>`              |
| client_id               | The **Client ID** Provided by the provider when you register an application for OAuth2 authentication                                                                                                               | `<string>`                                      |
| client_secret           | The **Client Secret** Provided by the provider when you register an application for OAuth2 authentication                                                                                                           | `<string>`                                      |
| authorization_endpoint  | The authorization endpoint of the provider                                                                                                                                                                          | e.g. `https://idp.example.com/authorize`        |
| token_endpoint          | The token endpoint of the provider                                                                                                                                                                                  | e.g. `https://idp.example.com/token`            |
| userinfo_endpoint       | The endpoint returning the details of the logged in user (OpenID Connect: userinfo endpoint)                                                                                                                        | e.g. `https://idp.example.com/userinfo`         |
| enforce_email_verified  | Deny the login unless the provider confirms the email address as verified (`userdata_email_verified`); without it, anyone registering there with someone else's email could log in as the Grav user with that email | [default: `true`] \| `false`                    |
| verify_peer             | Verify the TLS certificates of the token and userinfo endpoints; disable only for testing, as anyone who can intercept the connection could then read the secrets and log in as any user                            | [default: `true`] \| `false`                    |
| userdata_id             | The claim with the user's unique ID in the userinfo response                                                                                                                                                        | [default: `sub`] \| `<string>`                  |
| userdata_login          | The claim with the username in the userinfo response                                                                                                                                                                | [default: `preferred_username`] \| `<string>`   |
| userdata_fullname       | The claim with the full name in the userinfo response                                                                                                                                                               | [default: `name`] \| `<string>`                 |
| userdata_email          | The claim with the email address in the userinfo response                                                                                                                                                           | [default: `email`] \| `<string>`                |
| userdata_email_verified | The claim saying whether the email address is verified in the userinfo response, used by `enforce_email_verified`                                                                                                   | [default: `email_verified`] \| `<string>`       |
| userdata_picture        | The claim with the URL of the user's picture in the userinfo response, used by `avatar_from_picture`                                                                                                                | [default: `picture`] \| `<string>`              |
| avatar_from_picture     | Use the user's picture (`userdata_picture`) as avatar in Grav, browsers load it directly from the URL the provider sends                                                                                            | [default: `true`] \| `false`                    |
| group_sync              | Assign the user to Grav groups from the provider's groups on every login (see below)                                                                                                                                | `true` \| [default: `false`]                    |
| userdata_groups         | The claim with the user's group names in the userinfo response, used by `group_sync`                                                                                                                                | [default: `groups`] \| `<string>`               |
| group_filter            | Provider side: identity provider groups to sync, by name or slug, `*` and `?` as wildcards                                                                                                                          | e.g. `['grav-*']` [default: `[]` = all]         |
| group_prefix            | Grav side: prepended to the Grav group names                                                                                                                                                                        | e.g. `idp_` [default: `''`]                     |
| group_autocreate        | Create missing Grav groups (without permissions) instead of skipping them                                                                                                                                           | `true` \| [default: `false`]                    |
| scope                   | An array of strings that define the OAuth2 scope. These can enable retrieving more data, but often require more permissions                                                                                         | e.g. `['openid', 'email', 'profile']`           |
| scope_separator         | The separator of the scopes in the authorization request                                                                                                                                                            | [default: `space`] \| `comma`                   |
| pkce_method             | The PKCE (Proof Key for Code Exchange) method, `plain` is **not** recommended and should only be used if the provider supports nothing else                                                                         | [default: `S256`] \| `plain` \| `''` (disabled) |

> NOTE: Logins failing with `cURL error 60: SSL certificate problem: self-signed certificate` work with `verify_peer: false`, for testing only.

Besides `login`, `fullname` and `email`, only `email_verified` and, with `avatar_from_picture`, the `avatar_url` are stored under the `generic` key (empty if not available), not the whole resource owner details.

With `group_sync`, each login adds the user to the Grav groups named after the provider's groups (lowercase slugs with the `group_prefix`, e.g. `Grav Editors` becomes `grav-editors`; with PHP's intl extension other letters are transliterated, e.g. `Ärzte` becomes `arzte`, without it they're dropped; provider groups with no letters or digits left, e.g. `★`, are skipped). This happens before Grav checks the user's access.

Which groups the sync changes:

* **Groups assigned in Grav are never removed.** The sync only removes groups it added itself, which it records in the user's `synced_groups` (site and admin logins keep track of theirs separately, as both have their own settings).
* **A group assigned in Grav that the provider also sends stays assigned in Grav**, so the sync never takes it over or removes it later.
* **Default groups** (`default_groups`) are given after the sync to a user left with no groups at all. Once given, they count as groups assigned in Grav and stay.
* **A group the sync added is removed** once the provider no longer sends it.
* **A missing or invalid groups claim counts as no groups.** Many providers (e.g. Keycloak) leave the claim out when a user has none, so a user removed from their last group at the provider loses the groups the sync added, but keeps the ones assigned in Grav. If the provider isn't set up to send a groups claim at all, each login removes the groups the sync added before, so check the claim before turning `group_sync` on.

Limits:

* Changes reach logged-in users at their next login.
* Admin logins only sync with `admin.save_grav_user` (the new admin reads users from their account file; without it they skip the sync with a warning, and groups synced before stay meanwhile).
* Without a `group_prefix` the provider can assign any existing Grav group, privileged ones included. Use a `group_filter` or `group_prefix` to limit it.
* Each `group_filter` entry has to be a group name or pattern. Put it in quotes if YAML would read it as something else, e.g. `yes`, `true` or `2.0`. An invalid entry is ignored with a warning in the log, and the sync goes on with the other entries. If no valid entry is left, the sync is skipped and the user's groups stay as they are until it's fixed.
* The prefix is part of the slug, so it needs at least one letter or digit. A prefix made only of characters the slug drops (e.g. `!`, `-` or `.`) would disappear and give the provider every Grav group, so the sync is skipped with a warning instead.
* With `group_autocreate` every new provider group is added to `user/config/groups.yaml` for good, so limit them with `group_filter`.
* Groups deleted at the provider stay in Grav.

> NOTE: The Grav username is `generic.` followed by a hash of the token endpoint and the User ID claim (`userdata_id`), so users of different identity providers, or unusual ID values, never share an account. Use the same identity provider and User ID claim for site and admin logins, and an identity provider setup that sends both the same user ID (some providers, e.g. Microsoft Entra ID, send a different `sub` per application): otherwise a person gets a different username, and so a different Grav account, on each side (or one found by email, see below).
>
> If no Grav user with the resulting username exists, an existing Grav user with the same email address is used. With `enforce_email_verified` (default), logins without a verified email address are denied; without it, only use providers that verify the email addresses of their users.

#### GitHub

| Key           | Description                                                                                                                 | Values                                |
| :------------ | :-------------------------------------------------------------------------------------------------------------------------- | :------------------------------------ |
| enabled       | Enable or disable this specific provider. This stops its showing as an valid login option                                   | [default: `true`] \| `false`          |
| client_id     | The **Client ID** Provided by GitHub when you register an application for OAuth2 authentication                             | `<string>`                            |
| client_secret | The **Client Secret** Provided by GitHub when you register an application for OAuth2 authentication                         | `<string>`                            |
| scope         | An array of strings that define the OAuth2 scope. These can enable retrieving more data, but often require more permissions | e.g. `['user', 'user:email', 'repo']` |

#### Instagram

| Key           | Description                                                                                                                 | Values                                |
| :------------ | :-------------------------------------------------------------------------------------------------------------------------- | :------------------------------------ |
| enabled       | Enable or disable this specific provider. This stops its showing as an valid login option                                   | [default: `true`] \| `false`          |
| client_id     | The **Client ID** Provided by Instagram when you register an application for OAuth2 authentication                          | `<string>`                            |
| client_secret | The **Client Secret** Provided by Instagram when you register an application for OAuth2 authentication                      | `<string>`                            |
| scope         | An array of strings that define the OAuth2 scope. These can enable retrieving more data, but often require more permissions | e.g. `['basic', 'likes', 'comments']` |
| host          | The host address of the Instagram OAuth2 API service _[don't change this unless you know what you are doing]_               | e.g. `https://api.instagram.com`      |

#### Facebook

| Key               | Description                                                                                                                 | Values                                              |
| :---------------- | :-------------------------------------------------------------------------------------------------------------------------- | :-------------------------------------------------- |
| enabled           | Enable or disable this specific provider. This stops its showing as an valid login option                                   | [default: `true`] \| `false`                        |
| app_id            | The **App ID** Provided by Facebook when you register an application for OAuth2 authentication                              | `<string>`                                          |
| app_secret        | The **App Secret** Provided by Facebook when you register an application for OAuth2 authentication                          | `<string>`                                          |
| scope             | An array of strings that define the OAuth2 scope. These can enable retrieving more data, but often require more permissions | e.g. `['email', 'public_profile', 'user_hometown']` |
| graph_api_version | The Graph AP version to use _[don't change this unless you know what you are doing]_.                                       | e.g. `v2.10`                                        |

#### Google

| Key           | Description                                                                                                                 | Values                       |
| :------------ | :-------------------------------------------------------------------------------------------------------------------------- | :--------------------------- |
| enabled       | Enable or disable this specific provider. This stops its showing as an valid login option                                   | [default: `true`] \| `false` |
| client_id     | The **Client ID** Provided by Google when you register an application for OAuth2 authentication                             | `<string>`                   |
| client_secret | The **Client Secret** Provided by Google when you register an application for OAuth2 authentication                         | `<string>`                   |
| scope         | An array of strings that define the OAuth2 scope. These can enable retrieving more data, but often require more permissions | e.g. `['email', 'profile']`  |
| avatar_size   | The size in pixels of the avatar URL to store                                                                               | e.g. `200`                   |

#### LinkedIn

| Key           | Description                                                                                                                 | Values                                     |
| :------------ | :-------------------------------------------------------------------------------------------------------------------------- | :----------------------------------------- |
| enabled       | Enable or disable this specific provider. This stops its showing as an valid login option                                   | [default: `true`] \| `false`               |
| client_id     | The **Client ID** Provided by LinkedIn when you register an application for OAuth2 authentication                           | `<string>`                                 |
| client_secret | The **Client Secret** Provided by LinkedIn when you register an application for OAuth2 authentication                       | `<string>`                                 |
| scope         | An array of strings that define the OAuth2 scope. These can enable retrieving more data, but often require more permissions | e.g. `['r_basicprofile','r_emailaddress']` |

> Note that if you use the admin plugin, a file with your configuration will be saved in the `user/config/plugins/login-oauth2.yaml`.

### Usage

Once properly configured, the functionality of the OAuth2 plugin is simple for the user.  The login form will display `enabled` OAuth2 Providers, and the user can click on one which will then redirect them to the provider to authenticate and `accept` the permissions requested via the `scope` fields.  Upon completion of this process, the user will then be redirected back to the site where they will now be logged in.

#### OAuth2 User Data

Any user data available via the `scope` provider options will be retrieved.  Core fields like `username`, and `email` will be stored on the Grav user object, and anything else that is provider-specific can be optionally stored as well.  By default, the Grav user object **is not** persisted to a physical Grav account YAML file, instead it's just kept in session temporarily.

Values a provider doesn't send (e.g. no public email on GitHub, or a scope that wasn't granted) don't erase the values an existing Grav user already has, such as its email address or full name. The provider-specific data (e.g. under `github:`), however, follows the provider: it's replaced as a whole on every login, so values the provider no longer sends are removed there, and values it sends as `null` are cleared.

#### Storing Grav User

By default the OAuth2 plugin does not store any local user information.  Upon successfully authenticating against the OAuth2 user, a user is created and is available during the session.  However, upon returning, the user must re-authenticate and the OAuth2 data is retrieved again.

If you want to be able to set user data (extra fields, or specific user access) for a particular user, you can enable the `save_grav_user` option, and this will create a local Grav user in the `accounts/` folder. Admin logins use `admin.save_grav_user`, which follows `save_grav_user` as long as it isn't set; the new admin (Admin2) and admin group sync need it.  This is a local record of the user and attributes can be set here.

> NOTE: Any attribute stored under the provider key (e.g. `github:`) in the user account file will be overwritten by the plugin during the next login.  This information is always in sync with latest data from the provider.
>
> Also note that the password will never be stored in the Grav user under `accounts/`.

#### OAuth2 to Grav Access Mapping

The OAuth2 plugin provides a flexible way to map your OAuth2 users into Grav.

> For Groups and Access mapping to work properly a valid `search_dn`, `query_dn` and `group_query` is required.

The default configuration for `default_access_levels.access` looks like:

```yaml
user:
  site:
    login: true
```

In order for a front-end user to be able to log into a Grav site the minimum of `site: [login: true]` is required. You can of course configure this with any access settings you wish to provide.

It is not advised to provide any `admin` access via OAuth2 accounts, but if you wish a particular OAuth user to be able to log into the admin, you should enable the `admin.save_grav_user` option, so the userdata is persisted as a Grav Account YAML file, and then manually add the desired permissions.  These **will not** be reset to the default values on each login.

> NOTE: See the [Groups and Permissions Documentation](https://learn.getgrav.org/advanced/groups-and-permissions?target=_blank) for more information about how Grav permissions work in conjunction with access levels and groups.

#### Enforce OAuth2

With `enforce_oauth2` (site) or `admin.enforce_oauth2` (admin), users can only log in through an OAuth2 provider on that side. Other logins through the Login plugin are denied: passwords, magic links, passkeys, logins after registration, and plugins such as Login LDAP. Plugins that sign users in on their own, such as the older Login OAuth plugin, aren't affected.

It only applies while that side has a provider that is enabled and has its client ID, and for the admin while `admin.enabled` is on. So a missing provider can't lock anyone out. The one exception: if the admin only has providers of another plugin (e.g. login-oauth2-extras) and that plugin fails to load them, Admin2 keeps denying logins without OAuth2, because a failing plugin must not turn it off.

The login pages hide what is denied: the site's password form (Login 3.12.1 or newer), "Login by link" and "Sign in with a passkey", and Admin2's password form (API 1.1.11 and Admin2 2.2.8 or newer) and passkey button. Older versions still show the forms, but refuse the logins.

With `admin.enforce_oauth2`, accounts with admin access (`admin.login`, `admin.super`, `api.access` or `api.super`) can't use a password, magic link or passkey on the site either, because Admin2 accepts their site session. Their password is refused like a wrong one ("Login failed"), so the login form doesn't tell that it was right or that the account has admin access. Admin2 invitations only create the account: the invited user then logs in with OAuth2 and is matched by email.

##### Required settings

Two settings of other plugins let users in without OAuth2 and have to be off:

* Login → "Remember me" (`plugins.login.rememberme.enabled`): its cookie logs users back in without the identity provider, even after their access there was removed.
* For the admin, API → Session Authentication (`plugins.api.auth.session_enabled`, on unless set to `false`): with it, Admin2 accepts any site session. Turning it off also stops front-end features that call the API as the logged-in visitor.

The plugin never changes them. The settings and `enforce-oauth2 on` refuse to turn "Enforce OAuth2" on while one of them is on (`--force` turns it on anyway). They check the environment you change, or the main configuration together with the environments that inherit from it.

If one is turned on later, the settings and Admin2's dashboard show a red warning. Below each toggle, the settings list the required settings with their state, and tell you if "Enforce OAuth2" doesn't apply yet. `bin/plugin login-oauth2 status` shows the same.

##### Users who are already logged in

Turning it on doesn't sign anyone out: sessions, "remember me" cookies, Admin2 tokens and API keys keep working. To end them:

* Run `bin/plugin login-oauth2 enforce-oauth2 on --sign-out` (see [CLI Commands](#cli-commands)).
* Use the Login plugin's sign-out: `bin/plugin login sign-out`, **Sign out everywhere** in Admin2's Users list, or **Sign out all users** in the Login settings.
* For one account, change its password (Login 3.11.0 or newer) and revoke its API keys in Admin2.
* By hand: delete `user/data/rememberme` ("remember me" cookies) and `user/config/plugins/api-private.php` (Admin2 tokens), and change `system.session.name` (sessions).

The first two need Login 3.13.0 or newer, and leave API keys working.

##### Locked out

If nobody can log in, e.g. because the identity provider is down or a plugin that adds providers fails, run `bin/plugin login-oauth2 enforce-oauth2 off` in Grav's folder on the server, or set `enforce_oauth2: false` and `admin.enforce_oauth2: false` in `user/config/plugins/login-oauth2.yaml`. Then log in with your password.

### Admin Notes

New in version `2.0` is the support for OAuth2 providers to be able to login via the admin.  After careful consideration, we decided to provide configuration options for both `Site` and `Admin` as there are some providers that need to have a unique **OAuth2 Application** setup for each unique callback.  This is important because by default, Grav uses a different session for both `Site` and `Admin`.  Therefore you need a unique callback, one that goes to the frontend site, and one to the admin.  This means we have to provide a way to have unique application settings for both `Site` and `Admin`.

There are some providers such as **Discord** and **GitLab** that actually allow for multiple callback URLs to be registered.  In this case, simply copy over the same `client_id` and `client_secret` for both `Site` and `Admin`.

Also, if you have the `system.session.split` option set to `false`, you are effectively sharing the session between `Site` and `Admin`, and again, you can share the `Site` configuration, but they need to be entered into both sets of options.

For admin logins to be useful, you need to ensure you set `store_grav_user: true` (which is not default behavior). This will ensure that when you login, a local accounts `.yaml` file will be create in `user/accounts/` folder.  A this point you can manually add the admin access required to log into the admin by directly editing the `.yaml` file that was auto-created during the login process:

```yaml
  access:
    admin:
      login: true
      super: true
    site:
      login: true
```

Of course adjust this `access.admin:` settings to whatever you need, but **NEVER** set this in the `default_access_levels:` setting for the plugin, or every user will have admin access.  You want to maintain control over who can access and who can't, especially those logging in with OAuth2 providers as there is no control over 'who' can sign in.

### CLI Commands

```text
bin/plugin login-oauth2 status
bin/plugin login-oauth2 enforce-oauth2 <on|off|status> [--sign-out|--sign-out-all-environments]
bin/plugin login-oauth2 provider <enable|disable> <name>
bin/plugin login-oauth2 provider verify-certificate <name> [on|off|status]
```

`--site` or `--admin` limits a command to that side; without them it covers both. `--env=<environment>` (or `GRAV_ENVIRONMENT`) uses that environment's config file instead of `user/config/plugins/login-oauth2.yaml`.

Changes apply from the next request and are written to the Grav log. A command tells you if an environment overrides its change.

* **`status`** shows, for each side, whether "Enforce OAuth2" is on and applies, the settings it requires off, the providers (active, or why not) and the account settings. It changes nothing.
* **`enforce-oauth2 off`** turns it off in the main config file and in every environment that turns it on (with `--env` only there). It fails if it's still on afterwards, e.g. because a config file can't be read or a `GRAV_CONFIG__` environment variable turns it on.
* **`enforce-oauth2 on`** turns it on if the side has an active provider and the [required settings](#required-settings) are off. If not, a side chosen with `--site` or `--admin` is refused; without them, only the side that is ready is turned on. `--force` turns it on anyway, except against a `GRAV_CONFIG__` variable that turns it off.
* **`enforce-oauth2 on --sign-out`** also signs out the users who may be logged in without OAuth2, even if it was on already: everyone if the site is on, else the accounts with admin access (only those with an account file). Their sessions, "remember me" cookies and Admin2 tokens end; API keys keep working. It needs Login 3.13.0 or newer.
* **`--sign-out-all-environments`**: accounts are shared by all environments, so `--sign-out` is refused if it would also sign out users of an environment where "Enforce OAuth2" doesn't apply, e.g. when you only change one with `--env`, or when one turns it off or has no active provider. This option signs them out as well and names the environments.
* **`provider enable|disable`** enables or disables one of this plugin's providers, e.g. `generic`. Providers of other plugins, e.g. login-oauth2-extras, are set in their own settings.
* **`provider verify-certificate`** shows or changes "Verify Certificate" of the Generic provider. Turn it off only for testing.

### Troubleshooting

To get a quick state of your OAuth2 configuration, you can simply dump out the Grav user on a temporary _secure_ page:

```markdown
---
title: OAuth2 Test
cache_enabled: false
process:
    twig: true
access:
    site.login: true
---

# Grav User

{{ vardump(grav.user) }}
```
