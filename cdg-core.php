<?php
/**
 * Plugin Name: CDG Core Standalone
 * Plugin URI: https://crawforddesigngroup.com
 * Description: WordPress optimizations, security hardening, and agency features for Crawford Design Group client sites. Divi-free variant with no theme dependency.
 * Version: 1.3.5
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Crawford Design Group
 * Author URI: https://crawforddesigngroup.com
 * Text Domain: cdg-core-standalone
 *
 * @package CDG_Core
 */

// Prevent direct access
if (!defined("ABSPATH")) {
  exit();
}

// Update checker library (see "Automatic Updates" block below). The `use`
// import has to live at the top level of the file — PHP doesn't allow it
// inside an `if` block — but require_once is keyed on the file's absolute
// path, so it's already safe to run on every load, guard or no guard.
require_once plugin_dir_path(__FILE__) . "includes/plugin-update-checker/plugin-update-checker.php";

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Guard everything below against being parsed twice in the same request —
// e.g. if plugin files get swapped mid-request during a manual "replace"
// update and briefly double-included. Without this, redeclaring the class
// or the cdg_core() function further down is a fatal error ("critical
// error" on the front end) rather than a harmless no-op.
if (!class_exists("CDG_Core")) {

/**
 * Plugin Constants
 */
define("CDG_CORE_VERSION", "1.3.5");
define("CDG_CORE_DIR", plugin_dir_path(__FILE__));
define("CDG_CORE_URL", plugin_dir_url(__FILE__));
define("CDG_CORE_BASENAME", plugin_basename(__FILE__));

/**
 * Autoloader for CDG Core classes
 */
spl_autoload_register(function (string $class): void {
  $prefix = "CDG_Core_";

  if (strpos($class, $prefix) !== 0) {
    return;
  }

  // Convert class name to file name
  $class_name = substr($class, strlen($prefix));
  $file_name =
    "class-" . str_replace("_", "-", strtolower($class_name)) . ".php";
  $file_path = CDG_CORE_DIR . "includes/" . $file_name;

  if (file_exists($file_path)) {
    require_once $file_path;
  }
});

/**
 * Automatic Updates (GitHub Releases)
 *
 * CDG Core Standalone isn't listed on wordpress.org, so WordPress has no
 * way to know a new version exists unless something tells it. This points
 * the update checker at the crawforddesign/cdg-core-standalone GitHub
 * repo's Releases — tag a release, attach the built cdg-core-standalone.zip
 * as a release asset, and bump the "Version" header above to match.
 * WordPress will then show a normal "Update available" notice + "Update
 * Now" button on the Plugins page, going through core's update flow
 * instead of the manual upload/replace flow. Auto-updates are
 * intentionally left off here; this only makes the checker and button
 * appear — nothing installs without a manual click.
 *
 * See README.md for the full release steps.
 */
$cdg_core_update_checker = PucFactory::buildUpdateChecker(
  "https://github.com/crawforddesign/cdg-core-standalone/",
  __FILE__,
  "cdg-core-standalone"
);
$cdg_core_update_checker->getVcsApi()->enableReleaseAssets('/\.zip($|[?&#])/i');

/**
 * Main CDG Core Class
 */
final class CDG_Core
{
  /**
   * The CSS that used to pre-fill the "Custom Admin CSS" textarea by
   * default (pre-1.2.0). Kept only so the one-time migration below can
   * detect "never customized, just inherited the default" and clear it
   * safely — any site that edited this field, even slightly, keeps its
   * own text untouched.
   *
   * @var string
   */
  private const LEGACY_CUSTOM_ADMIN_CSS = '#wpfooter a { color: #F34F27; }
#adminmenu .wp-submenu-head, #adminmenu a.menu-top { font-size: 12px; font-weight: 500; }
#adminmenu .wp-submenu a { font-size: 11px; }
#wpbody-content, #wpcontent { background-color: #f7f7f8; }
.postbox, .stuffbox { border-radius: 8px; overflow: hidden; }
input[type=text], input[type=email], input[type=url], input[type=password], input[type=search], input[type=number], input[type=checkbox], input[type=date], input[type=datetime], input[type=month], textarea, select { border-radius: 6px; box-shadow: none; border-color: #e0e0e0; }
.button, .button-primary, .button-secondary { border-radius: 6px; }
.postbox, #poststuff #post-body .postbox { border-color: #e0e0e0; }
.postbox { margin-bottom: 16px; border: 1px solid #eaeaea; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04); }
.postbox .inside { padding: 12px 16px; }
.button, .button-primary, .button-secondary, input, textarea, select { transition: all 0.15s ease; }
#title { font-size: 1.4em; padding: 8px 12px; border-color: #e0e0e0; border-radius: 6px; }
#title:focus { border-color: #3858e9; box-shadow: 0 0 0 1px #3858e9; }';

  /**
   * Plugin instance
   *
   * @var CDG_Core|null
   */
  private static ?CDG_Core $instance = null;

  /**
   * Plugin settings
   *
   * @var array
   */
  private array $settings = [];

  /**
   * Documentation component instance
   *
   * @var CDG_Core_Documentation|null
   */
  private ?CDG_Core_Documentation $documentation = null;

  /**
   * Default settings
   *
   * @var array
   */
  private array $defaults = [
    // Features
    "enable_documentation" => true,
    "enable_cpt_widgets" => true,
    "enable_admin_branding" => true,

    // Defaults - Comments
    "disable_comments" => false,

    // WordPress Cleanup
    "remove_wp_version" => true,
    "remove_shortlink" => true,
    "remove_adjacent_posts" => true,
    "remove_oembed_links" => true,
    "remove_rest_api_link" => true,
    "disable_emojis" => true,

    // Security
    "disable_xmlrpc" => true,
    "block_dangerous_uploads" => true,
    "remove_powered_by" => true,
    "disable_code_editor" => true,
    "enable_svg_uploads" => false,
    "svg_admin_only" => true,
    "enable_font_uploads" => false,
    "font_admin_only" => true,
    "enable_lottie_uploads" => false,
    "lottie_admin_only" => true,

    // Dashboard Widgets
    "remove_quick_draft" => true,
    "remove_wp_news" => true,
    "remove_php_nag" => true,
    "remove_browser_nag" => true,
    "remove_site_health" => false,
    "remove_welcome_panel" => false,
    "remove_activity" => false,
    "remove_at_a_glance" => false,
    "hidden_dashboard_widgets" => [],

    // Heartbeat
    "heartbeat_admin" => "60",
    "heartbeat_frontend" => "disable",

    // Gutenberg
    "gutenberg_mode" => "optimize",

    // Performance
    "optimize_search" => true,
    "optimize_archives" => true,
    "enable_lazy_loading" => true,
    "remove_medium_large" => true,
    "remove_dns_prefetch" => true,

    // Image Sizes
    "disabled_image_sizes" => [],

    // Post Revisions
    "post_revisions_mode" => "limited",
    "post_revisions_limit" => 5,

    // Code Snippets
    "code_snippets" => [],

    // Roles
    "enable_custom_roles" => false, // creates Manager / Staff roles; also required for agency_email below
    "hide_native_roles"   => false, // hides Editor/Author/Contributor/Subscriber from the role picker (requires enable_custom_roles); Administrator always stays selectable
    "agency_email" => CDG_Core_Roles::DEFAULT_AGENCY_EMAIL, // account with this email is auto-switched to native Administrator, replacing its current role

    // Sidebar management
    "sidebar_entry_names"    => [],   // menu_slug => display_name (global rename)
    "sidebar_entry_hidden"   => [],   // menu_slug => [role_slug, ...] (administrator / cdg_client_manager / cdg_client_staff)
    "sidebar_submenu_names"  => [],   // parent_slug => [submenu_slug => display_name]
    "sidebar_submenu_hidden" => [],   // parent_slug => [submenu_slug => [role_slug, ...]]
    "custom_menu_links"      => [],   // [{id, title, icon, link, target, hidden_for:[role_slug,...]}]

    // Plugin visibility (Sidebar tab)
    "hidden_plugins" => [],   // plugin_file => [role_slug, ...] — hidden from those roles; the agency account always sees every plugin

    // Login Page
    "login_logo_id" => 0,
    "enable_custom_login" => true,
    "login_generic_errors" => true,
    "login_hide_backtoblog" => true,
    "login_hide_register_link" => false,
    "login_hide_language_switcher" => true,

    // Admin
    "admin_footer_text" =>
      'Website by <a href="https://crawforddesigngroup.com" target="_blank">Crawford Design Group</a>',
    "custom_admin_css" => "",

    // Theme Color
    "theme_color_mode" => "disabled",
    "theme_color_hex" => "#ffffff",

    // Documentation
    "show_documentation_widgets" => true,
    "documentation_module_style" => "informative",
    "documentation_widget_limit" => 5,

    // CPT Dashboard
    "show_cpt_widgets" => true,
    "cpt_module_style" => "informative",
    "selected_cpts" => [],
    "show_recent_posts" => true,
    "recent_posts_limit" => 3,
  ];

  /**
   * Get plugin instance
   *
   * @return CDG_Core
   */
  public static function get_instance(): CDG_Core
  {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Constructor
   */
  private function __construct()
  {
    $this->load_settings();
    $this->check_version();
    $this->init_components();
    $this->setup_hooks();
  }

  /**
   * Load settings from database
   *
   * @return void
   */
  private function load_settings(): void
  {
    $saved = get_option("cdg_core_settings", []);

    if (!is_array($saved)) {
      $saved = [];
    }

    $this->settings = wp_parse_args($saved, $this->defaults);
  }

  /**
   * Check version and run upgrades if needed
   *
   * @return void
   */
  private function check_version(): void
  {
    $installed_version = get_option("cdg_core_version", "0.0.0");

    // Re-run activation tasks on version bumps, and also when the
    // plugin-activation hook flagged a pending rewrite-rule flush (e.g.
    // after a deactivate/reactivate cycle where the version is unchanged).
    $needs_activation =
      version_compare($installed_version, CDG_CORE_VERSION, "<") ||
      get_option("cdg_core_flush_rewrite_rules", false);

    if ($needs_activation) {
      // Schedule activation tasks for 'init' hook when WordPress is fully loaded
      add_action("init", [$this, "run_activation"], 0);
      update_option("cdg_core_version", CDG_CORE_VERSION);
      delete_option("cdg_core_flush_rewrite_rules");
    }
  }

  /**
   * Run activation tasks
   *
   * Reuses the stored Documentation component instance rather than
   * creating a duplicate. Runs on 'init' hook to ensure WordPress
   * rewrite rules are available.
   *
   * @return void
   */
  public function run_activation(): void
  {
    // Reuse existing Documentation instance if available
    if ($this->documentation) {
      $this->documentation->register_post_type();
      $this->documentation->register_taxonomy();
      $this->documentation->create_default_categories();
    }

    // Role creation is opt-in (Roles tab) and handled by CDG_Core_Roles
    // itself on 'init' — nothing to force here on activation.

    // One-time migration: sidebar hide/order settings used to be keyed by
    // user ID; they're now keyed by role slug. Old per-user data can't be
    // reinterpreted as roles, so it's cleared once and never touched again.
    $this->migrate_sidebar_settings_to_roles();

    // One-time migration: clear the pre-1.2.0 default "Custom Admin CSS"
    // text on sites that never actually customized it.
    $this->migrate_clear_default_custom_css();

    // Flush rewrite rules - must happen after post types are registered
    flush_rewrite_rules();
  }

  /**
   * One-time clear of the legacy "Custom Admin CSS" default text. Only
   * touches sites where the saved value is an exact match for the old
   * default (i.e. the field was never actually edited) — anything else,
   * including a value that's merely similar, is left alone. Gated by its
   * own flag so it only ever runs once, regardless of future version bumps.
   *
   * @return void
   */
  private function migrate_clear_default_custom_css(): void
  {
    if (get_option("cdg_core_custom_css_migrated", false)) {
      return;
    }

    $settings = get_option("cdg_core_settings", []);

    if (
      is_array($settings) &&
      isset($settings["custom_admin_css"]) &&
      $settings["custom_admin_css"] === self::LEGACY_CUSTOM_ADMIN_CSS
    ) {
      $settings["custom_admin_css"] = "";
      update_option("cdg_core_settings", $settings);
    }

    update_option("cdg_core_custom_css_migrated", true);
  }

  /**
   * One-time clear of the legacy per-user-ID sidebar settings so the new
   * per-role format starts clean. Gated by its own flag (not the plugin
   * version) so it only ever runs once, regardless of future version bumps.
   *
   * @return void
   */
  private function migrate_sidebar_settings_to_roles(): void
  {
    if (get_option("cdg_core_sidebar_roles_migrated", false)) {
      return;
    }

    $settings = get_option("cdg_core_settings", []);

    if (is_array($settings)) {
      unset($settings["sidebar_entry_hidden"], $settings["sidebar_menu_order"]);

      if (isset($settings["custom_menu_links"]) && is_array($settings["custom_menu_links"])) {
        foreach ($settings["custom_menu_links"] as &$link) {
          if (is_array($link)) {
            $link["hidden_for"] = [];
          }
        }
        unset($link);
      }

      update_option("cdg_core_settings", $settings);
    }

    update_option("cdg_core_sidebar_roles_migrated", true);
  }

  /**
   * Initialize components
   *
   * @return void
   */
  private function init_components(): void
  {
    // Core WordPress optimizations
    new CDG_Core_Cleanup($this);
    new CDG_Core_Security($this);
    new CDG_Core_Performance($this);

    // Defaults (Comments, Projects)
    new CDG_Core_Defaults($this);

    // Roles — Manager / Staff, plus Agency Email auto-assignment to native
    // Administrator. Instantiated unconditionally, but the role-creation
    // side is a no-op unless "Enable Custom Roles" is turned on in the
    // Roles tab (the legacy-role migration inside it always runs).
    new CDG_Core_Roles($this);

    // Features
    if ($this->get_setting("enable_documentation")) {
      $this->documentation = new CDG_Core_Documentation($this);
    }

    if ($this->get_setting("enable_cpt_widgets")) {
      new CDG_Core_CPT_Dashboard($this);
    }

    // Plugin Visibility — always active; filter is a no-op when list is empty.
    new CDG_Core_Plugin_Visibility($this);

    // Security Audit — instantiated outside is_admin() so the wp_login
    // hook (login timestamp recording) fires on the login page as well.
    new CDG_Core_Security_Audit($this);

    // Autoload Audit — Tools page to inspect/toggle autoloaded wp_options.
    new CDG_Core_Autoload_Audit($this);

    // SVG Support - initialize regardless of setting (class checks internally)
    new CDG_Core_SVG_Support($this);

    // Font Support - initialize regardless of setting (class checks internally)
    new CDG_Core_Font_Support($this);

    // Lottie Support - initialize regardless of setting (class checks internally)
    new CDG_Core_Lottie_Support($this);

    // Login Page
    new CDG_Core_Login($this);

    // Code Snippets — always instantiated; class checks active flag per snippet.
    new CDG_Core_Code_Snippets($this);

    // Admin
    if (is_admin()) {
      new CDG_Core_Admin($this);
    }
  }

  /**
   * Setup hooks
   *
   * @return void
   */
  private function setup_hooks(): void
  {
    // Admin branding
    if ($this->get_setting("enable_admin_branding")) {
      add_filter("admin_footer_text", [$this, "admin_footer_text"]);
      add_filter("update_footer", [$this, "admin_footer_version"], 11);
      add_action("admin_head", [$this, "output_admin_footer_css"]);
    }

    // Custom admin CSS
    if (!empty($this->get_setting("custom_admin_css"))) {
      add_action("admin_head", [$this, "output_custom_admin_css"]);
    }

    // Admin bar logo CSS override — always on, replaces the WP logo with
    // the bundled CDG icon.
    add_action("admin_head", [$this, "output_admin_bar_logo_css"]);
    add_action("wp_head",    [$this, "output_admin_bar_logo_css"]);

    // Theme color meta tag
    $theme_color_mode = $this->get_setting("theme_color_mode");
    if ($theme_color_mode !== "disabled") {
      add_action("wp_head", [$this, "output_theme_color_meta"], 1);
    }

    // Developer tag in page source
    add_action("wp_head", [$this, "output_developer_tag"], 1);
  }

  /**
   * Get a setting value
   *
   * @param string $key Setting key
   * @param mixed $default Default value
   * @return mixed
   */
  public function get_setting(string $key, $default = null)
  {
    if (array_key_exists($key, $this->settings)) {
      return $this->settings[$key];
    }

    if (array_key_exists($key, $this->defaults)) {
      return $this->defaults[$key];
    }

    return $default;
  }

  /**
   * Get all settings
   *
   * @return array
   */
  public function get_settings(): array
  {
    return $this->settings;
  }

  /**
   * Get default settings
   *
   * @return array
   */
  public function get_defaults(): array
  {
    return $this->defaults;
  }

  /**
   * Update settings
   *
   * @param array $new_settings New settings
   * @return bool
   */
  public function update_settings(array $new_settings): bool
  {
    $this->settings = wp_parse_args($new_settings, $this->defaults);
    return update_option("cdg_core_settings", $this->settings);
  }

  /**
   * Admin footer text
   *
   * @return string
   */
  public function admin_footer_text(): string
  {
    return wp_kses_post($this->get_setting("admin_footer_text"));
  }

  /**
   * Admin footer version
   *
   * @return string
   */
  public function admin_footer_version(): string
  {
    return sprintf(
      "CDG Core %s | WordPress %s",
      esc_html(CDG_CORE_VERSION),
      esc_html(get_bloginfo("version"))
    );
  }

  /**
   * Output the fixed CSS that colors links in the admin footer (including
   * the branded "Website by Crawford Design Group" link) to match CDG's
   * brand color. This is a permanent part of the plugin, not something
   * that lives in the editable "Custom Admin CSS" field, so clearing that
   * field never removes it.
   *
   * @return void
   */
  public function output_admin_footer_css(): void
  {
    echo '<style id="cdg-core-footer-css">#wpfooter a{color:#F34F27}</style>' . "\n";
  }

  /**
   * Output custom admin CSS
   *
   * @return void
   */
  public function output_custom_admin_css(): void
  {
    $css = $this->get_setting("custom_admin_css");
    if (!empty($css)) {
      printf(
        '<style id="cdg-core-admin-css">%s</style>',
        wp_strip_all_tags($css)
      );
    }
  }

  /**
   * Output admin bar logo CSS override.
   *
   * Hides the default WordPress "W" dashicon and replaces it with the
   * bundled CDG icon via background-image on the same element.
   *
   * @return void
   */
  public function output_admin_bar_logo_css(): void
  {
    $url = CDG_CORE_URL . "admin/images/admin-bar-icon.svg";

    printf(
      '<style id="cdg-adminbar-logo-css">' .
        '#wpadminbar>#wp-toolbar>#wp-admin-bar-root-default #wp-admin-bar-wp-logo>.ab-item .ab-icon{' .
          'background-image:url(%s)!important;' .
          'background-size:20px auto!important;' .
          'background-position:0 6px!important;' .
          'background-repeat:no-repeat!important;' .
          'width:20px!important' .
        '}' .
        '#wpadminbar>#wp-toolbar>#wp-admin-bar-root-default #wp-admin-bar-wp-logo>.ab-item .ab-icon::before{content:""!important}' .
      '</style>' . "\n",
      esc_url($url)
    );
  }

  /**
   * Output developer tag HTML comment in page source.
   *
   * @return void
   */
  public function output_developer_tag(): void
  {
    echo "\n<!--\n\n";
    echo "  Developed by\n\n";
    echo "    ___  ____   ____  \n";
    echo "   / __||  _ \ / ___| \n";
    echo "  | |   | | | | |  _  \n";
    echo "  | |___| |_| | |_| | \n";
    echo "   \____|____/ \____| \n\n";
    echo "  Crawford Design Group\n";
    echo "  https://crawforddesigngroup.com\n\n";
    echo "-->\n";
  }

  /**
   * Output theme-color meta tag
   *
   * @return void
   */
  public function output_theme_color_meta(): void
  {
    $color = $this->resolve_accent_color();

    if ($color) {
      printf(
        '<meta name="theme-color" content="%s">' . "\n",
        esc_attr($color)
      );
    }
  }

  /**
   * Resolve the site accent color from plugin settings.
   *
   * Returns a validated hex string (with #) or empty string if unavailable.
   * Used by both the theme-color meta tag and the login page styling.
   *
   * @return string
   */
  public function resolve_accent_color(): string
  {
    $mode = $this->get_setting("theme_color_mode");

    if ($mode === "custom") {
      return $this->normalize_hex((string) $this->get_setting("theme_color_hex"));
    }

    return "";
  }

  /**
   * Normalize a color value to a validated 6-digit hex string with # prefix.
   *
   * Handles values stored without #, with extra whitespace, or in 3-digit
   * shorthand. Returns empty string if the value is not a valid hex color.
   *
   * @param string $hex Raw color value
   * @return string
   */
  private function normalize_hex(string $hex): string
  {
    $hex = trim($hex);

    if (empty($hex)) {
      return "";
    }

    if ($hex[0] !== "#") {
      $hex = "#" . $hex;
    }

    // Expand 3-digit shorthand (#abc → #aabbcc)
    if (preg_match('/^#([A-Fa-f0-9]{3})$/', $hex, $m)) {
      $hex = "#" . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
    }

    return preg_match('/^#[A-Fa-f0-9]{6}$/', $hex) ? $hex : "";
  }
}

/**
 * Initialize CDG Core
 */
add_action(
  "plugins_loaded",
  function () {
    CDG_Core::get_instance();
  },
  5
);

/**
 * Plugin activation
 *
 * Flags a pending rewrite-rule flush. The actual flush happens on the next
 * 'init' (see CDG_Core::check_version()) so post types are registered first.
 *
 * @return void
 */
register_activation_hook(__FILE__, function (): void {
  update_option("cdg_core_flush_rewrite_rules", true);
});

/**
 * Plugin deactivation
 *
 * @return void
 */
register_deactivation_hook(__FILE__, function (): void {
  flush_rewrite_rules();
});

/**
 * Helper function to get CDG Core instance
 *
 * @return CDG_Core
 */
function cdg_core(): CDG_Core
{
  return CDG_Core::get_instance();
}

} // end: if ( ! class_exists( 'CDG_Core' ) ) guard
