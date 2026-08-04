# CDG Core Standalone

WordPress optimizations, security hardening, and agency features for Crawford Design Group client sites. This is the **Divi-free variant** of CDG Core — functionally identical except that every Divi-specific feature and check has been removed. Use this on sites that do not run the Divi theme/builder.

> Both plugins (`cdg-core` and `cdg-core-standalone`) share the same PHP class and constant names (`CDG_Core`, `CDG_CORE_VERSION`, etc.), so they must not be active on the same WordPress install at the same time. Each site should run one or the other.

## Version 1.3.0

### Requirements

- WordPress 6.0+
- PHP 8.0+
- SpinupWP hosting (recommended)

### Installation

1. Upload the `cdg-core-standalone/` folder to `/wp-content/plugins/`
2. Activate **CDG Core Standalone** from the Plugins page
3. Visit **Settings > CDG Core** to configure

### Features

- WordPress head cleanup & emoji removal
- Security hardening (XML-RPC, uploads, headers)
- **SVG upload support** with admin-only restriction
- **Font upload support** (OTF, TTF, WOFF, WOFF2) with admin-only restriction
- **Lottie/JSON upload support** with admin-only restriction
- Performance optimizations (Gutenberg, queries, images)
- Documentation system for editors
- CPT Dashboard widgets
- **Disable Comments** (full system disable)
- **Plugin Visibility** - hide specific plugins from the Plugins page per role (native WordPress roles included, not just Manager/Staff); Agency always sees every plugin
- **Custom Roles** - opt-in Agency / Manager / Staff roles
- **Sidebar Menu Management** - rename/hide sidebar items and submenus per role
- Admin branding & default admin CSS

### File Structure

```
plugins/
+-- cdg-core-standalone/
    +-- cdg-core.php                  <- Main plugin file
    +-- README.md
    +-- includes/
    |   +-- class-admin.php           <- Admin UI & settings
    |   +-- class-cleanup.php         <- WordPress head cleanup
    |   +-- class-cpt-dashboard.php   <- CPT dashboard widgets
    |   +-- class-defaults.php        <- Comments defaults
    |   +-- class-documentation.php   <- Documentation CPT
    |   +-- class-font-support.php    <- Font upload support
    |   +-- class-lottie-support.php  <- Lottie upload support
    |   +-- class-performance.php     <- Performance optimizations
    |   +-- class-plugin-visibility.php <- Plugin visibility & sidebar manager
    |   +-- class-roles.php           <- Agency / Manager / Staff roles
    |   +-- class-security.php        <- Security hardening
    |   +-- class-security-audit.php  <- Read-only security diagnostics
    |   +-- class-svg-support.php     <- SVG upload support
    |   +-- plugin-update-checker/    <- Vendored update checker (GitHub Releases)
    +-- admin/
        +-- js/
        |   +-- admin-script.js
        +-- css/
            +-- admin-style.css
```

### Settings Tabs

| Tab               | Description                                              |
| ----------------- | -------------------------------------------------------- |
| **Features**      | Documentation system, CPT widgets                        |
| **Defaults**      | Comments                                                  |
| **WP Cleanup**    | Head cleanup, dashboard widgets, heartbeat               |
| **Security**      | XML-RPC, uploads, X-Powered-By, SVG/Font/Lottie support  |
| **Performance**   | Gutenberg, queries, images, revisions                    |
| **Admin**         | Branding, theme color, custom CSS                        |
| **Roles**         | Custom Agency / Manager / Staff roles; Agency auto-assigned by email |
| **Sidebar**       | Rename/hide sidebar menu items and submenus per role, plus per-role Plugin Visibility |

### SpinupWP Compatibility

CDG Core Standalone is designed to work alongside SpinupWP hosting. The following security headers are handled by SpinupWP at the Nginx level and are **not** duplicated by this plugin:

- **Strict-Transport-Security (HSTS)**
- **X-XSS-Protection**
- **X-Frame-Options**
- **X-Content-Type-Options**

CDG Core Standalone complements SpinupWP by handling:

- **X-Powered-By removal** (not handled by SpinupWP defaults)
- **XML-RPC disabling**
- **Dangerous file upload blocking**
- **Code editor restrictions**

### Defaults Tab

#### Disable Comments

Completely disables WordPress comments:

- Removes comment support from all post types
- Hides Comments menu from admin
- Hides Discussion settings page
- Blocks access to comment admin pages
- Disables comment REST API endpoints
- Disables comment feeds (301 redirect to home)
- Removes pingback headers

### Security Tab

#### SVG Upload Support

When enabled, SVG and SVGZ files can be uploaded through the Media Library with preview support and automatic dimension detection.

- **Enable SVG Uploads**: Disabled by default
- **Restrict to Admins**: Enabled by default

#### Font Upload Support

When enabled, custom font files can be uploaded through the Media Library for use with custom CSS `@font-face` declarations.

Supported formats: OTF, TTF, WOFF, WOFF2

- **Enable Font Uploads**: Disabled by default
- **Restrict to Admins**: Enabled by default

#### Lottie Upload Support

When enabled, Lottie animation files can be uploaded through the Media Library for use with animation libraries.

Supported formats: .json, .lottie

- **Enable Lottie Uploads**: Disabled by default
- **Restrict to Admins**: Enabled by default

### Roles Tab

Opt-in **Agency / Manager / Staff** custom roles (off by default):

- **Agency** (`cdg_agency`) — clone of Administrator, for CDG staff. Never manually assignable from a dropdown — instead, the account whose email matches the **Agency Email** setting (default `support@crawforddesigngp.com`) is automatically switched to Agency, replacing whatever role it had, on login, account creation, and profile edits. Always bypasses Sidebar tab hide rules and always sees every plugin regardless of Plugin Visibility settings.
- **Manager** (`cdg_client_manager`) — Administrator capabilities minus plugin/theme installs, user management, core updates, and the file editor.
- **Staff** (`cdg_client_staff`) — clone of Editor. Content only.

"Hide Default WordPress Roles" removes Editor, Author, Contributor, and Subscriber from the Add User / Edit User / Bulk Edit role dropdowns once Custom Roles are enabled. Administrator always stays selectable there, and Agency is never selectable regardless of this toggle.

### Sidebar Tab

- **Sidebar Menu Items** — rename any admin sidebar entry or hide it from Administrator, Manager, or Staff. Items with submenu pages can be expanded to manage those too.
- **Custom Menu Links** — add custom links to the admin sidebar, optionally hidden from Administrator, Manager, or Staff.
- **Plugin Visibility** — hide specific installed plugins from the Plugins page per role. Every native WordPress role can be targeted here (not just Administrator/Manager/Staff), so a plugin can stay hidden from a client even on sites that never enable custom roles. Agency always sees every plugin. Plugins remain active — they are only hidden from the list view.

### Heartbeat Control

Control WordPress heartbeat API behavior:

- **Admin**: Set interval (60s recommended) or disable
- **Frontend**: Set interval or disable (disabled recommended)

### Post Revisions

Control how many revisions WordPress keeps:

- **Unlimited**: WordPress default behavior
- **Disabled**: No revisions saved
- **Limited**: Specify a number (e.g., 5 revisions per post)

Note: The CDG Core setting overrides any `WP_POST_REVISIONS` constant in `wp-config.php`.

### Admin Branding

- Custom admin footer text with CDG branding
- CDG Core version and WordPress version in footer
- Default admin CSS for polished admin UI (rounded corners, consistent borders, CDG accent color)
- Custom admin CSS field for per-site overrides

### Browser Theme Color

Outputs a `<meta name="theme-color">` tag used by mobile browsers to tint the address bar.

- **Custom**: Use a manually specified hex color
- **Disabled**: Do not output a theme-color meta tag

### Deployment

Deployed the same way as CDG Core — from GitHub using a shell script. See `CDG-Core-Deployment-Guide.md` for the full workflow, adjusting the plugin slug/folder to `cdg-core-standalone`.

### Updating an Installed Site (wp-admin)

As of 1.3.0, CDG Core Standalone ships with [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (vendored at `includes/plugin-update-checker/`), pointed at this repo's GitHub Releases. This is what makes "Update available" and the native "Update Now" button show up on a client site's Plugins page — sites no longer need the manual "re-upload the zip and replace" flow, which could throw a critical error on swap.

**Cutting a release:**

1. Bump the `Version:` header in `cdg-core.php` (and `CDG_CORE_VERSION`) to the new version number.
2. Build the plugin zip the way you normally do (`cdg-core-standalone.zip` — same file the deploy script and manual uploads use).
3. On GitHub, draft a new Release. Tag it (e.g. `v1.3.0`), give it a title/changelog, and **attach `cdg-core-standalone.zip` as a release asset**.
4. Publish the release.

Installed sites will see the update within ~12 hours (WordPress's normal update-check cadence), or immediately if an admin clicks "Check again" on the Updates screen. From there it's a normal one-click "Update Now" — no deactivate/reactivate workaround needed.

Auto-updates are not enabled by default. If you want a given site to apply releases unattended, an admin can turn on "Enable auto-updates" for CDG Core Standalone from that site's Plugins page — this uses WordPress's own fatal-error-protected update path.

### Changelog

#### 1.3.0

- Added GitHub-based automatic updates: vendored [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (`includes/plugin-update-checker/`), pointed at this repo's Releases. Client sites now get a native "Update available" / "Update Now" prompt on the Plugins page instead of requiring a manual zip re-upload. See "Updating an Installed Site" above for the release process. Auto-updates are off by default.
- Hardened `cdg-core.php` against being parsed twice in a single request (unguarded class/constant/function declarations could fatal — "critical error" — if the plugin's files were swapped mid-request during a manual update, requiring a deactivate/reactivate to recover). All top-level declarations are now wrapped in `class_exists()` / `function_exists()` guards.
- Removed the "Howdy," greeting from the admin bar account menu (`CDG_Core_Cleanup::remove_howdy()`), leaving just the username.
- Agency is no longer manually assignable from the Add User / Edit User / Bulk Edit role dropdowns, regardless of the "Hide Default WordPress Roles" toggle. Instead, added an **Agency Email** setting (Roles tab, default `support@crawforddesigngp.com`) — the account holding that email is automatically switched to Agency, replacing whatever role it had, on login, account creation, and profile edits.
- "Hide Default WordPress Roles" now hides Editor, Author, Contributor, and Subscriber only; Administrator always stays selectable in those dropdowns.
- Replaced the Sidebar tab's "Menu Order" card (per-role drag-and-drop sidebar reordering) with a new **Plugin Visibility** card: hide specific installed plugins from the Plugins page per role. Targetable roles now include the native WordPress roles (Administrator, Editor, Author, Contributor, Subscriber) in addition to Manager/Staff, so a plugin can be hidden from a client even on sites that never enable custom roles. Agency always bypasses this and sees every plugin. The `sidebar_menu_order` setting has been removed entirely in favor of `hidden_plugins`.
- Sidebar Menu Items and Custom Menu Links can now also be hidden from **Administrator**, not just Manager/Staff (`CDG_Core_Roles::target_roles()` gained a third entry).

#### 1.0.0

- Forked from CDG Core 1.7.0 to create a Divi-free variant for non-Divi client sites
- Removed the "Hide Divi Projects" feature (`class-defaults.php`)
- Removed the Divi Visual Builder heartbeat exception (`class-cleanup.php`)
- Removed the GF Auto-Page Generator, which only produced Divi 5 GF Styler block markup (`class-gf-auto-page.php`, `admin/js/gf-auto-page.js`, `admin/css/gf-auto-page.css`)
- Removed "Auto" theme-color mode, which read Divi's `et_divi` accent color option; only Custom/Disabled modes remain
- Removed the Divi-specific image size list from the Performance image-size labeling helper
- Updated plugin header, text domain, and all admin UI/guide copy to remove Divi references
