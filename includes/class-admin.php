<?php
/**
 * Admin Class
 *
 * Handles the settings page and admin functionality.
 *
 * @package CDG_Core
 * @since 1.0.0
 */

declare(strict_types=1);

class CDG_Core_Admin
{
  private CDG_Core $plugin;

  public function __construct(CDG_Core $plugin)
  {
    $this->plugin = $plugin;
    add_action("admin_menu", [$this, "add_admin_menu"]);
    add_action("admin_enqueue_scripts", [$this, "enqueue_assets"]);
    add_action("admin_init", [$this, "handle_form_submission"]);
  }

  public function add_admin_menu(): void
  {
    add_options_page(
      __("CDG Core Settings", "cdg-core"),
      __("CDG Core", "cdg-core"),
      "manage_options",
      "cdg-core-settings",
      [$this, "render_settings_page"]
    );
  }

  public function enqueue_assets(string $hook): void
  {
    if ($hook !== "settings_page_cdg-core-settings") {
      return;
    }

    $css_path = CDG_CORE_DIR . "admin/css/admin-style.css";
    $js_path = CDG_CORE_DIR . "admin/js/admin-script.js";

    wp_enqueue_media();

    if (file_exists($css_path)) {
      // Register with src=false so WP outputs only the inline <style>, no <link>.
      wp_register_style("cdg-core-admin", false);
      wp_enqueue_style("cdg-core-admin");
      wp_add_inline_style("cdg-core-admin", file_get_contents($css_path)); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    if (file_exists($js_path)) {
      // Same pattern for JS: register with src=false, then inject inline.
      wp_register_script("cdg-core-admin", false, [], false, true);
      wp_enqueue_script("cdg-core-admin");
      wp_add_inline_script("cdg-core-admin", file_get_contents($js_path)); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }
  }

  public function handle_form_submission(): void
  {
    if (isset($_POST["cdg_core_rebuild_roles"])) {
      $this->handle_rebuild_roles();
      return;
    }

    if (!isset($_POST["cdg_core_save_settings"])) {
      return;
    }

    if (
      !isset($_POST["cdg_core_nonce"]) ||
      !wp_verify_nonce($_POST["cdg_core_nonce"], "cdg_core_settings")
    ) {
      wp_die(__("Security check failed.", "cdg-core"));
    }

    if (!current_user_can("manage_options")) {
      wp_die(__("Permission denied.", "cdg-core"));
    }

    $tab = sanitize_text_field($_POST["cdg_core_tab"] ?? "features");

    $settings = $this->sanitize_settings($_POST);
    $this->plugin->update_settings($settings);

    $tabs_needing_flush = ["features", "cleanup"];
    if (in_array($tab, $tabs_needing_flush, true)) {
      flush_rewrite_rules();
    }

    $redirect_url = add_query_arg(
      ["tab" => $tab, "settings-updated" => "true"],
      admin_url("options-general.php?page=cdg-core-settings")
    );

    wp_safe_redirect($redirect_url);
    exit();
  }

  /**
   * Force Agency/Manager/Staff to be rebuilt from the current live
   * Administrator/Editor capability sets. Unlike the self-healing check on
   * `init` (which only fires if a role is missing entirely), this always
   * re-clones — so it picks up capabilities other plugins (e.g. Gravity
   * Forms) have added to Administrator since the roles were first created.
   *
   * @return void
   */
  private function handle_rebuild_roles(): void
  {
    if (
      !isset($_POST["cdg_core_nonce"]) ||
      !wp_verify_nonce($_POST["cdg_core_nonce"], "cdg_core_settings")
    ) {
      wp_die(__("Security check failed.", "cdg-core"));
    }

    if (!current_user_can("manage_options")) {
      wp_die(__("Permission denied.", "cdg-core"));
    }

    CDG_Core_Roles::register_roles();

    $redirect_url = add_query_arg(
      ["tab" => "roles", "roles-rebuilt" => "true"],
      admin_url("options-general.php?page=cdg-core-settings")
    );

    wp_safe_redirect($redirect_url);
    exit();
  }

  private function sanitize_settings(array $input): array
  {
    $s = $this->plugin->get_settings();
    $tab = sanitize_text_field($input["cdg_core_tab"] ?? "features");

    switch ($tab) {
      case "features":
        $s["enable_documentation"] = !empty($input["enable_documentation"]);
        $s["show_documentation_widgets"] = !empty(
          $input["show_documentation_widgets"]
        );
        $s["documentation_module_style"] = sanitize_text_field(
          $input["documentation_module_style"] ?? "informative"
        );
        $s["documentation_widget_limit"] = absint(
          $input["documentation_widget_limit"] ?? 5
        );

        $s["enable_cpt_widgets"] = !empty($input["enable_cpt_widgets"]);
        $s["cpt_module_style"] = sanitize_text_field(
          $input["cpt_module_style"] ?? "informative"
        );
        $s["selected_cpts"] = array_map(
          "sanitize_text_field",
          (array) ($input["selected_cpts"] ?? [])
        );
        $s["show_recent_posts"] = !empty($input["show_recent_posts"]);
        $s["recent_posts_limit"] = absint($input["recent_posts_limit"] ?? 3);
        break;

      case "cleanup":
        $s["disable_comments"] = !empty($input["disable_comments"]);

        $s["remove_wp_version"] = !empty($input["remove_wp_version"]);
        $s["remove_shortlink"] = !empty($input["remove_shortlink"]);
        $s["remove_adjacent_posts"] = !empty($input["remove_adjacent_posts"]);
        $s["remove_oembed_links"] = !empty($input["remove_oembed_links"]);
        $s["remove_rest_api_link"] = !empty($input["remove_rest_api_link"]);
        $s["disable_emojis"] = !empty($input["disable_emojis"]);

        $s["remove_quick_draft"] = !empty($input["remove_quick_draft"]);
        $s["remove_wp_news"] = !empty($input["remove_wp_news"]);
        $s["remove_php_nag"] = !empty($input["remove_php_nag"]);
        $s["remove_browser_nag"] = !empty($input["remove_browser_nag"]);
        $s["remove_site_health"] = !empty($input["remove_site_health"]);
        $s["remove_welcome_panel"] = !empty($input["remove_welcome_panel"]);
        $s["remove_activity"] = !empty($input["remove_activity"]);
        $s["remove_at_a_glance"] = !empty($input["remove_at_a_glance"]);
        $s["hidden_dashboard_widgets"] = array_map(
          "sanitize_text_field",
          (array) ($input["hidden_dashboard_widgets"] ?? [])
        );
        break;

      case "security":
        $s["disable_xmlrpc"] = !empty($input["disable_xmlrpc"]);
        $s["block_dangerous_uploads"] = !empty(
          $input["block_dangerous_uploads"]
        );
        $s["remove_powered_by"] = !empty($input["remove_powered_by"]);
        $s["disable_code_editor"] = !empty($input["disable_code_editor"]);
        break;

      case "performance":
        $s["gutenberg_mode"] = sanitize_text_field(
          $input["gutenberg_mode"] ?? "optimize"
        );
        $s["optimize_search"] = !empty($input["optimize_search"]);
        $s["optimize_archives"] = !empty($input["optimize_archives"]);
        $s["enable_lazy_loading"] = !empty($input["enable_lazy_loading"]);
        $s["disabled_image_sizes"] = array_map(
          "sanitize_text_field",
          (array) ($input["disabled_image_sizes"] ?? [])
        );
        $s["remove_medium_large"] = !empty($input["remove_medium_large"]);
        $s["post_revisions_mode"] = sanitize_text_field(
          $input["post_revisions_mode"] ?? "limited"
        );
        $s["post_revisions_limit"] = absint(
          $input["post_revisions_limit"] ?? 5
        );
        $s["remove_dns_prefetch"] = !empty($input["remove_dns_prefetch"]);
        $s["heartbeat_admin"] = sanitize_text_field(
          $input["heartbeat_admin"] ?? "default"
        );
        $s["heartbeat_frontend"] = sanitize_text_field(
          $input["heartbeat_frontend"] ?? "disable"
        );
        $s["enable_svg_uploads"] = !empty($input["enable_svg_uploads"]);
        $s["svg_admin_only"] = !empty($input["svg_admin_only"]);
        $s["enable_font_uploads"] = !empty($input["enable_font_uploads"]);
        $s["font_admin_only"] = !empty($input["font_admin_only"]);
        $s["enable_lottie_uploads"] = !empty($input["enable_lottie_uploads"]);
        $s["lottie_admin_only"] = !empty($input["lottie_admin_only"]);
        break;

      case "roles":
        $s["enable_custom_roles"] = !empty($input["enable_custom_roles"]);
        $s["hide_native_roles"]   = !empty($input["hide_native_roles"]);

        // Keep the prior value if the submitted address isn't a valid email.
        $agency_email = sanitize_email(wp_unslash((string) ($input["agency_email"] ?? "")));
        if (is_email($agency_email)) {
          $s["agency_email"] = $agency_email;
        }
        break;

      case "sidebar":
        // Pre-fetch captured slugs and the targetable role slugs once.
        $captured_items = CDG_Core_Plugin_Visibility::get_captured_menu_items();
        $captured_slugs = array_keys($captured_items);
        $target_roles   = array_keys(CDG_Core_Roles::target_roles());

        // ── Sidebar entry renames: slug => display_name ──────────────────
        $entry_names = [];
        foreach ((array) ($input["sidebar_entry_names"] ?? []) as $slug => $name) {
          $slug = sanitize_text_field($slug);
          $name = sanitize_text_field($name);
          if ($name !== "" && in_array($slug, $captured_slugs, true)) {
            $entry_names[$slug] = $name;
          }
        }
        $s["sidebar_entry_names"] = $entry_names;

        // ── Sidebar entry hiding: slug => [role_slug, ...] ────────────────
        $entry_hidden = [];
        foreach ((array) ($input["sidebar_entry_hidden"] ?? []) as $slug => $roles) {
          $slug = sanitize_text_field($slug);
          if (!in_array($slug, $captured_slugs, true)) {
            continue;
          }
          $validated = array_values(
            array_intersect(
              array_map("sanitize_key", (array) $roles),
              $target_roles
            )
          );
          if (!empty($validated)) {
            $entry_hidden[$slug] = $validated;
          }
        }
        $s["sidebar_entry_hidden"] = $entry_hidden;

        // ── Submenu renames: parent_slug => [submenu_slug => display_name] ──
        $submenu_names = [];
        foreach ((array) ($input["sidebar_submenu_names"] ?? []) as $parent => $subs) {
          $parent = sanitize_text_field($parent);
          if (!in_array($parent, $captured_slugs, true)) {
            continue;
          }
          $valid_sub_slugs = array_keys($captured_items[$parent]["submenu"] ?? []);
          foreach ((array) $subs as $sub_slug => $name) {
            $sub_slug = sanitize_text_field($sub_slug);
            $name     = sanitize_text_field($name);
            if ($name !== "" && in_array($sub_slug, $valid_sub_slugs, true)) {
              $submenu_names[$parent][$sub_slug] = $name;
            }
          }
        }
        $s["sidebar_submenu_names"] = $submenu_names;

        // ── Submenu hiding: parent_slug => [submenu_slug => [role_slug,...]] ──
        $submenu_hidden = [];
        foreach ((array) ($input["sidebar_submenu_hidden"] ?? []) as $parent => $subs) {
          $parent = sanitize_text_field($parent);
          if (!in_array($parent, $captured_slugs, true)) {
            continue;
          }
          $valid_sub_slugs = array_keys($captured_items[$parent]["submenu"] ?? []);
          foreach ((array) $subs as $sub_slug => $roles) {
            $sub_slug = sanitize_text_field($sub_slug);
            if (!in_array($sub_slug, $valid_sub_slugs, true)) {
              continue;
            }
            $validated = array_values(
              array_intersect(
                array_map("sanitize_key", (array) $roles),
                $target_roles
              )
            );
            if (!empty($validated)) {
              $submenu_hidden[$parent][$sub_slug] = $validated;
            }
          }
        }
        $s["sidebar_submenu_hidden"] = $submenu_hidden;

        // ── Custom menu links ────────────────────────────────────────────
        $custom_links = [];
        foreach ((array) ($input["custom_menu_links"] ?? []) as $item) {
          if (!is_array($item)) {
            continue;
          }
          $title = sanitize_text_field($item["title"] ?? "");
          if ($title === "") {
            continue;
          }
          // Preserve existing ID or mint a new one.
          $raw_id = preg_replace('/[^a-z0-9]/', '', strtolower($item["id"] ?? ""));
          $id     = strlen($raw_id) === 8 ? $raw_id : substr(bin2hex(random_bytes(4)), 0, 8);

          $target = ($item["target"] ?? "_self") === "_blank" ? "_blank" : "_self";

          $hidden_for = array_values(
            array_intersect(
              array_map("sanitize_key", (array) ($item["hidden_for"] ?? [])),
              $target_roles
            )
          );

          $custom_links[] = [
            "id"         => $id,
            "title"      => $title,
            "icon"       => sanitize_text_field($item["icon"] ?? "admin-generic"),
            "link"       => esc_url_raw($item["link"] ?? ""),
            "target"     => $target,
            "hidden_for" => $hidden_for,
          ];
        }
        $s["custom_menu_links"] = $custom_links;

        // ── Plugin visibility: plugin_file => [role_slug, ...] ───────────
        // Every registered role except Agency is a valid target here (all
        // of $target_roles above, plus Editor/Author/Contributor/
        // Subscriber, which aren't offered as Sidebar hide targets).
        $all_plugin_files = array_keys(CDG_Core_Plugin_Visibility::get_all_plugins());
        $hideable_roles   = array_keys(CDG_Core_Roles::hideable_roles());

        $hidden_plugins = [];
        foreach ((array) ($input["hidden_plugins"] ?? []) as $plugin_file => $roles) {
          $plugin_file = sanitize_text_field(wp_unslash((string) $plugin_file));
          if (!in_array($plugin_file, $all_plugin_files, true)) {
            continue;
          }
          $validated = array_values(
            array_intersect(
              array_map("sanitize_key", (array) $roles),
              $hideable_roles
            )
          );
          if (!empty($validated)) {
            $hidden_plugins[$plugin_file] = $validated;
          }
        }
        $s["hidden_plugins"] = $hidden_plugins;

        break;

      case "snippets":
        $snippets = [];
        foreach ((array) ($input["code_snippets"] ?? []) as $item) {
          if (!is_array($item)) {
            continue;
          }
          $type = sanitize_text_field($item["type"] ?? "css");
          if (!in_array($type, ["css", "js", "html", "php"], true)) {
            $type = "css";
          }
          $location = sanitize_text_field($item["location"] ?? "head");
          if (!in_array($location, ["head", "footer"], true)) {
            $location = "head";
          }
          $snippets[] = [
            "title"       => sanitize_text_field($item["title"] ?? ""),
            "description" => sanitize_text_field($item["description"] ?? ""),
            // wp_unslash() is correct here: WordPress applies add_magic_quotes()
            // to $_POST in wp-settings.php, so every backslash in the textarea
            // arrives doubled. wp_unslash() strips the extra layer before storage.
            // If you ever call sanitize_settings() programmatically with raw
            // (non-magic-quoted) data, skip this or apply addslashes() first.
            "code"        => wp_unslash((string) ($item["code"] ?? "")),
            "type"        => $type,
            "location"    => $location,
            "active"      => !empty($item["active"]),
          ];
        }
        $s["code_snippets"] = $snippets;
        break;

      case "admin":
        $s["login_logo_id"] = absint($input["login_logo_id"] ?? 0);
        $s["enable_custom_login"] = !empty($input["enable_custom_login"]);
        $s["login_generic_errors"] = !empty($input["login_generic_errors"]);
        $s["login_hide_backtoblog"] = !empty($input["login_hide_backtoblog"]);
        $s["login_hide_register_link"] = !empty(
          $input["login_hide_register_link"]
        );
        $s["login_hide_language_switcher"] = !empty(
          $input["login_hide_language_switcher"]
        );

        $s["enable_admin_branding"] = !empty($input["enable_admin_branding"]);
        $s["admin_footer_text"] = wp_kses_post(
          $input["admin_footer_text"] ?? ""
        );
        $s["custom_admin_css"] = wp_strip_all_tags(
          $input["custom_admin_css"] ?? ""
        );

        $s["theme_color_mode"] = in_array(
          $input["theme_color_mode"] ?? "disabled",
          ["custom", "disabled"],
          true
        )
          ? sanitize_text_field($input["theme_color_mode"])
          : "disabled";

        $hex = sanitize_text_field($input["theme_color_hex"] ?? "#ffffff");
        $s["theme_color_hex"] = preg_match(
          '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/',
          $hex
        )
          ? $hex
          : "#ffffff";
        break;
    }

    return $s;
  }

  /* ═══════════════════════════════════════════════════════════
   * RENDER — main page
   * ═══════════════════════════════════════════════════════════ */

  public function render_settings_page(): void
  {
    if (!current_user_can("manage_options")) {
      return;
    }

    $settings = $this->plugin->get_settings();
    $active_tab = sanitize_text_field($_GET["tab"] ?? "features");
    $saved =
      isset($_GET["settings-updated"]) && $_GET["settings-updated"] === "true";
    $roles_rebuilt =
      isset($_GET["roles-rebuilt"]) && $_GET["roles-rebuilt"] === "true";

    $tabs = [
      "features" => "Features",
      "cleanup" => "WP Cleanup",
      "security" => "Security",
      "performance" => "Performance",
      "admin" => "Admin",
      "roles" => "Roles",
      "sidebar" => "Sidebar",
      "snippets" => "Code Snippets",
      "guide" => "Guide",
    ];
    ?>
    <div class="wrap cdg-v2" data-tab="<?php echo esc_attr($active_tab); ?>">

      <div class="cdg-page-header">
        <div class="cdg-page-title">
          <svg height="30" width="30" viewBox="0 0 609.72 609.72" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="flex-shrink:0;display:block;"><path fill="#f34f27" d="M305.06,379.87c-2.37,0-4.37-1.71-4.94-4.01-1.84-7.32-5.32-15.1-10.49-23.34-6.12-9.9-14.84-19.08-26.18-27.54-9.85-7.45-19.71-12.53-29.55-15.24-2.35-.64-4.05-2.71-4.05-5.13s1.65-4.42,3.94-5.07c9.66-2.76,18.95-7.24,27.91-13.44,10.3-7.16,18.89-15.76,25.79-25.79,6.12-8.93,10.3-17.77,12.59-26.5.59-2.29,2.61-3.97,4.96-3.97s4.43,1.72,5.02,4.04c1.31,5.24,3.37,10.6,6.15,16.08,3.52,6.77,8.01,13.28,13.48,19.53,5.6,6.12,11.85,11.66,18.76,16.61,9.01,6.39,18.18,10.89,27.51,13.48,2.29.63,3.94,2.67,3.94,5.04s-1.7,4.46-4.03,5.1c-5.91,1.62-11.98,4.23-18.23,7.83-7.55,4.43-14.6,9.7-21.11,15.82-6.51,5.99-11.85,12.31-16.02,18.95-5.17,8.26-8.67,16.1-10.49,23.52-.57,2.3-2.57,4.02-4.94,4.02Z"/><path fill="#f34f27" d="M134.8,65.81l56.7,56.7c3.11,3.11,7.91,3.73,11.75,1.56,30.68-17.31,65.44-26.52,101.62-26.52,55.37,0,107.44,21.56,146.59,60.72,39.16,39.16,60.72,91.22,60.72,146.59,0,36.18-9.22,70.94-26.52,101.62-2.16,3.84-1.55,8.63,1.56,11.75l56.7,56.7c4.35,4.35,11.61,3.64,15.02-1.48,78.75-118.41,65.93-279.73-38.49-384.15C416.01-15.14,254.69-27.96,136.28,50.79c-5.13,3.41-5.83,10.67-1.48,15.02Z"/><path fill="#f34f27" d="M418.22,487.21c-3.11-3.11-7.91-3.73-11.75-1.56-30.68,17.31-65.44,26.52-101.62,26.52-55.37,0-107.44-21.56-146.59-60.72-39.16-39.16-60.72-91.22-60.72-146.59,0-36.18,9.22-70.94,26.52-101.62,2.16-3.84,1.55-8.63-1.56-11.75l-56.7-56.7c-4.35-4.35-11.61-3.64-15.02,1.48C-27.96,254.69-15.14,416.01,89.28,520.43c104.42,104.42,265.74,117.24,384.15,38.49,5.13-3.41,5.83-10.67,1.48-15.02l-56.7-56.7Z"/></svg>
          <h1><?php esc_html_e("Core", "cdg-core"); ?></h1>
          <span class="cdg-badge">v<?php echo esc_html(
            CDG_CORE_VERSION
          ); ?></span>
        </div>
        <?php if ($saved): ?>
          <div class="cdg-success-notice">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
            <?php esc_html_e("Settings saved.", "cdg-core"); ?>
          </div>
        <?php endif; ?>
        <?php if ($roles_rebuilt): ?>
          <div class="cdg-success-notice">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
            <?php esc_html_e("Roles rebuilt from the current Administrator/Editor capabilities.", "cdg-core"); ?>
          </div>
        <?php endif; ?>
      </div>

      <form method="post" action="<?php echo esc_url(
        admin_url("options-general.php?page=cdg-core-settings")
      ); ?>">
        <?php wp_nonce_field("cdg_core_settings", "cdg_core_nonce"); ?>
        <input type="hidden" name="cdg_core_tab" value="<?php echo esc_attr(
          $active_tab
        ); ?>">

        <div class="cdg-body-layout">

          <!-- Sidebar nav -->
          <nav class="cdg-sidebar">
            <div class="cdg-sidebar-label"><?php esc_html_e(
              "Settings",
              "cdg-core"
            ); ?></div>
            <?php foreach ($tabs as $id => $label): ?>
              <a href="<?php echo esc_url(
                add_query_arg(
                  "tab",
                  $id,
                  admin_url("options-general.php?page=cdg-core-settings")
                )
              ); ?>"
                 class="cdg-nav-item<?php echo $active_tab === $id
                   ? " cdg-active"
                   : ""; ?>">
                <?php echo $this->nav_icon($id);
              // phpcs:ignore WordPress.Security.EscapeOutput
              ?>
                <?php echo esc_html($label); ?>
              </a>
            <?php endforeach; ?>
            <div class="cdg-sidebar-save">
              <button type="submit" name="cdg_core_save_settings" class="cdg-btn cdg-btn-primary cdg-btn-full">
                <?php esc_html_e("Save Changes", "cdg-core"); ?>
              </button>
            </div>
          </nav>

          <!-- Tab content -->
          <main class="cdg-content">
            <?php $this->render_tab($active_tab, $settings); ?>
          </main>

        </div>


      </form>
    </div>
    <?php
  }

  private function render_tab(string $tab, array $s): void
  {
    switch ($tab) {
      case "features":
        $this->tab_features($s);
        break;
      case "cleanup":
        $this->tab_cleanup($s);
        break;
      case "security":
        $this->tab_security($s);
        break;
      case "performance":
        $this->tab_performance($s);
        break;
      case "admin":
        $this->tab_admin($s);
        break;
      case "roles":
        $this->tab_roles($s);
        break;
      case "sidebar":
        $this->tab_sidebar($s);
        break;
      case "snippets":
        $this->tab_snippets($s);
        break;
      case "guide":
        $this->tab_guide();
        break;
    }
  }

  /* ═══════════════════════════════════════════════════════════
   * HELPERS
   * ═══════════════════════════════════════════════════════════ */

  private function card(string $title, string $desc, callable $body): void
  {
    echo '<div class="cdg-card">';
    echo '<div class="cdg-card-header">';
    echo '<div class="cdg-card-title">' . esc_html($title) . "</div>";
    if ($desc !== "") {
      echo '<p class="cdg-card-desc">' . wp_kses_post($desc) . "</p>";
    }
    echo "</div>";
    echo '<div class="cdg-card-body">';
    $body();
    echo "</div>";
    echo "</div>";
  }

  /**
   * @param string $extra_class  Additional classes (e.g. 'cdg-disabled')
   * @param string $id           HTML id attribute for JS targeting
   */
  private function row(
    string $label,
    string $hint,
    string $control,
    bool $indented = false,
    string $id = "",
    string $extra_class = ""
  ): void {
    $classes = "cdg-setting-row";
    if ($indented) {
      $classes .= " cdg-indented";
    }
    if ($extra_class) {
      $classes .= " " . $extra_class;
    }

    $id_attr = $id !== "" ? ' id="' . esc_attr($id) . '"' : "";

    echo '<div class="' . esc_attr($classes) . '"' . $id_attr . ">";
    echo '<div class="cdg-setting-info">';
    echo '<div class="cdg-setting-label">' . esc_html($label) . "</div>";
    if ($hint !== "") {
      echo '<div class="cdg-setting-hint">' . wp_kses_post($hint) . "</div>";
    }
    echo "</div>";
    echo '<div class="cdg-setting-control">' . $control . "</div>"; // phpcs:ignore WordPress.Security.EscapeOutput
    echo "</div>";
  }

  private function sw(string $name, bool $checked): string
  {
    return '<label class="cdg-switch">' .
      '<input type="checkbox" name="' .
      esc_attr($name) .
      '" value="1"' .
      ($checked ? " checked" : "") .
      ">" .
      '<span class="cdg-switch-slider"></span>' .
      "</label>";
  }

  /**
   * @param array $options  ['value' => 'Label'] or ['value' => ['Label', 'Hint']]
   */
  private function radio_group(
    string $name,
    array $options,
    string $current
  ): string {
    $out = '<div class="cdg-radio-group">';
    foreach ($options as $value => $info) {
      $label = is_array($info) ? $info[0] : $info;
      $hint = is_array($info) && isset($info[1]) ? $info[1] : "";
      $checked = (string) $value === $current ? " checked" : "";

      $out .=
        '<label class="cdg-radio-card">' .
        '<input type="radio" name="' .
        esc_attr($name) .
        '" value="' .
        esc_attr((string) $value) .
        '"' .
        $checked .
        ">" .
        '<span class="cdg-radio-dot"></span>' .
        '<span class="cdg-radio-text"><strong>' .
        esc_html($label) .
        "</strong>";

      if ($hint !== "") {
        $out .= "<span>" . esc_html($hint) . "</span>";
      }

      $out .= "</span></label>";
    }
    $out .= "</div>";
    return $out;
  }

  private function check_item(
    string $name,
    bool $checked,
    string $label,
    string $value = "1"
  ): string {
    return '<label class="cdg-check-item">' .
      '<input type="checkbox" name="' .
      esc_attr($name) .
      '" value="' .
      esc_attr($value) .
      '"' .
      ($checked ? " checked" : "") .
      ">" .
      '<span class="cdg-check-box"></span>' .
      "<span>" .
      esc_html($label) .
      "</span>" .
      "</label>";
  }

  private function section_label(string $text, string $suffix = ""): void
  {
    echo '<div class="cdg-sub-section">' . esc_html($text);
    if ($suffix !== "") {
      echo " <span>" . esc_html($suffix) . "</span>";
    }
    echo "</div>";
  }

  private function nav_icon(string $tab): string
  {
    // width/height attributes are required — without them SVGs render at
    // intrinsic/full size when the stylesheet hasn't loaded yet.
    $icons = [
      "features" =>
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
      "cleanup" =>
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6M9 6V4h6v2"/></svg>',
      "security" =>
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
      "performance" =>
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
      "admin" =>
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
      "roles" =>
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
      "sidebar" =>
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/></svg>',
      "snippets" =>
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
      "guide" =>
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
    ];

    return $icons[$tab] ?? "";
  }

  /* ═══════════════════════════════════════════════════════════
   * TAB: FEATURES
   * ═══════════════════════════════════════════════════════════ */

  private function tab_features(array $s): void
  {
    // Documentation
    $this->card(
      "Documentation System",
      "Internal documentation post type with categorized dashboard widgets.",
      function () use ($s) {
        $this->row(
          "Enable Documentation",
          "Registers the <code>cdg_documentation</code> post type and taxonomies.",
          $this->sw("enable_documentation", $s["enable_documentation"])
        );

        $sub_class = !$s["enable_documentation"] ? "cdg-disabled" : "";
        echo '<div id="cdg-doc-sub-settings" class="' .
          esc_attr($sub_class) .
          '">';

        $this->row(
          "Show Dashboard Widgets",
          "Display documentation articles on the WordPress dashboard.",
          $this->sw(
            "show_documentation_widgets",
            $s["show_documentation_widgets"]
          ),
          true
        );

        $this->row(
          "Widget Style",
          "",
          $this->radio_group(
            "documentation_module_style",
            [
              "informative" => [
                "Informative",
                "One widget per documentation category",
              ],
              "minimal" => ["Minimal", "Single consolidated widget"],
            ],
            $s["documentation_module_style"]
          ),
          true
        );

        $this->row(
          "Docs Per Widget",
          "Maximum articles shown per dashboard widget (1–20).",
          '<input type="number" name="documentation_widget_limit" value="' .
            esc_attr($s["documentation_widget_limit"]) .
            '" min="1" max="20" class="cdg-input cdg-input-sm">',
          true
        );

        echo "</div>";
      }
    );

    // CPT Widgets
    $this->card(
      "CPT Dashboard Widgets",
      "Quick-access dashboard widgets for custom post types.",
      function () use ($s) {
        $this->row(
          "Enable CPT Widgets",
          "Show custom post type widgets on the WordPress dashboard.",
          $this->sw("enable_cpt_widgets", $s["enable_cpt_widgets"])
        );

        $sub_class = !$s["enable_cpt_widgets"] ? "cdg-disabled" : "";
        echo '<div id="cdg-cpt-sub-settings" class="' .
          esc_attr($sub_class) .
          '">';

        $this->row(
          "Widget Style",
          "",
          $this->radio_group(
            "cpt_module_style",
            [
              "informative" => [
                "Informative",
                "Per post type with stats and recent entries",
              ],
              "minimal" => ["Minimal", "Single quick-add widget"],
            ],
            $s["cpt_module_style"]
          ),
          true
        );

        // CPT checkboxes
        $available_cpts = CDG_Core_CPT_Dashboard::get_available_post_types();
        $selected = $s["selected_cpts"] ?? [];

        if (!empty($available_cpts)) {
          $cpt_checks = "";
          foreach ($available_cpts as $pt) {
            $cpt_checks .= $this->check_item(
              "selected_cpts[]",
              in_array($pt->name, $selected, true),
              $pt->labels->name,
              $pt->name
            );
          }
          $this->row(
            "Post Types to Show",
            "Select which CPTs appear in dashboard widgets.",
            '<div class="cdg-check-list">' . $cpt_checks . "</div>",
            true
          );
        } else {
          $this->row(
            "Post Types to Show",
            "No custom post types registered yet.",
            "",
            true
          );
        }

        $this->row(
          "Show Recent Posts in Widgets",
          "",
          $this->sw("show_recent_posts", $s["show_recent_posts"]),
          true
        );

        $this->row(
          "Recent Posts to Show",
          "Per widget (1–10).",
          '<input type="number" name="recent_posts_limit" value="' .
            esc_attr($s["recent_posts_limit"]) .
            '" min="1" max="10" class="cdg-input cdg-input-sm">',
          true
        );

        echo "</div>";
      }
    );
  }

  /* ═══════════════════════════════════════════════════════════
   * TAB: CLEANUP
   * ═══════════════════════════════════════════════════════════ */

  private function tab_cleanup(array $s): void
  {
    $this->card(
      "WordPress Comments",
      "Completely disable the WordPress commenting system site-wide.",
      function () use ($s) {
        $this->row(
          "Disable Comments",
          "Removes all comment functionality: hides the Comments menu, disables Discussion settings, and blocks comment-related admin pages.",
          $this->sw("disable_comments", $s["disable_comments"])
        );
      }
    );

    // Head cleanup
    $this->card(
      "WordPress Head Cleanup",
      "Remove unnecessary tags and scripts from the <code>&lt;head&gt;</code> for cleaner output and reduced fingerprinting.",
      function () use ($s) {
        $items = [
          "remove_wp_version" => "WordPress version",
          "remove_shortlink" => "Shortlink",
          "remove_adjacent_posts" => "Adjacent posts",
          "remove_oembed_links" => "oEmbed links",
          "remove_rest_api_link" => "REST API link",
          "disable_emojis" => "WordPress emojis",
        ];

        echo '<div class="cdg-check-grid">';
        foreach ($items as $key => $label) {
          echo $this->check_item($key, (bool) $s[$key], $label); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo "</div>";
      }
    );

    // Dashboard widgets
    $this->card(
      "Dashboard Widgets",
      "Hide WordPress core and plugin widgets from the admin dashboard.",
      function () use ($s) {
        $this->section_label("WordPress Core");

        $core_items = [
          "remove_welcome_panel" => "Welcome Panel",
          "remove_at_a_glance" => "At a Glance",
          "remove_activity" => "Activity",
          "remove_quick_draft" => "Quick Draft",
          "remove_wp_news" => "Events & News",
          "remove_site_health" => "Site Health Status",
          "remove_php_nag" => "PHP Update Nag",
          "remove_browser_nag" => "Browser Nag",
        ];

        echo '<div class="cdg-check-grid">';
        foreach ($core_items as $key => $label) {
          echo $this->check_item($key, (bool) $s[$key], $label); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo "</div>";

        // Plugin widgets
        $this->section_label("Plugin Widgets");

        // Belt-and-suspenders: the capture already excludes these IDs, but
        // filter defensively here too in case the transient pre-dates this fix.
        $plugin_widgets = array_filter(
          CDG_Core_Cleanup::get_available_widgets(),
          fn($w) => !in_array($w["id"], CDG_Core_Cleanup::CORE_WIDGET_IDS, true)
        );

        $hidden = $s["hidden_dashboard_widgets"] ?? [];

        if (empty($plugin_widgets)) {
          echo '<div class="cdg-empty">' .
            esc_html__(
              "No plugin widgets detected yet. Visit the Dashboard once to populate this list.",
              "cdg-core"
            ) .
            "</div>";
        } else {
          echo '<div class="cdg-check-grid">';
          foreach ($plugin_widgets as $widget) {
            echo '<label class="cdg-check-item">' .
              '<input type="checkbox" name="hidden_dashboard_widgets[]" value="' .
              esc_attr($widget["id"]) .
              '"' .
              (in_array($widget["id"], $hidden, true) ? " checked" : "") .
              ">" .
              '<span class="cdg-check-box"></span>' .
              "<span>" .
              esc_html($widget["title"]) .
              ' <span class="cdg-widget-id">' .
              esc_html($widget["id"]) .
              "</span></span>" .
              "</label>";
          }
          echo "</div>";
        }
      }
    );

  }

  /* ═══════════════════════════════════════════════════════════
   * TAB: SECURITY
   * ═══════════════════════════════════════════════════════════ */

  private function tab_security(array $s): void
  {
    echo '<div class="cdg-notice cdg-notice-info">' .
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>' .
      "<div>" .
      esc_html__(
        "Security headers (X-Frame-Options, HSTS, X-XSS-Protection, X-Content-Type-Options) are handled by SpinupWP at the Nginx level. These settings complement Wordfence.",
        "cdg-core"
      ) .
      "</div>" .
      "</div>";

    $this->card(
      "Security Hardening",
      "Core WordPress security improvements.",
      function () use ($s) {
        $items = [
          "disable_xmlrpc" => [
            "Disable XML-RPC",
            "Common attack vector used for brute-force attacks.",
          ],
          "block_dangerous_uploads" => [
            "Block Dangerous Uploads",
            "Prevents .exe, .php, .js and other executable files from being uploaded.",
          ],
          "remove_powered_by" => [
            "Remove X-Powered-By Header",
            "Hides PHP and server information from HTTP responses.",
          ],
          "disable_code_editor" => [
            "Disable Code Editor",
            "Disables the theme/plugin file editor for non-administrator users.",
          ],
        ];

        foreach ($items as $key => [$label, $hint]) {
          $this->row($label, $hint, $this->sw($key, (bool) $s[$key]));
        }
      }
    );

  }

  /* ═══════════════════════════════════════════════════════════
   * TAB: PERFORMANCE
   * ═══════════════════════════════════════════════════════════ */

  private function tab_performance(array $s): void
  {
    $this->card(
      "Block Editor (Gutenberg)",
      "Control how Gutenberg CSS/JS are loaded across the site.",
      function () use ($s) {
        $this->row(
          "Mode",
          "",
          $this->radio_group(
            "gutenberg_mode",
            [
              "default" => ["Default", "WordPress default Gutenberg behavior"],
              "optimize" => [
                "Optimize",
                "Remove CSS/JS on non-block pages (recommended)",
              ],
              "disable" => [
                "Disable",
                "Disable block editor entirely — use Classic Editor",
              ],
            ],
            $s["gutenberg_mode"]
          )
        );
      }
    );

    $this->card("Query Optimizations", "", function () use ($s) {
      $this->row(
        "Optimize Search Queries",
        "Adds post type limits to search queries to reduce database load.",
        $this->sw("optimize_search", $s["optimize_search"])
      );
      $this->row(
        "Optimize Archive Queries",
        "Limits fields returned for archive page listings.",
        $this->sw("optimize_archives", $s["optimize_archives"])
      );
    });

    $this->card("Images", "", function () use ($s) {
      $this->row(
        "Native Lazy Loading",
        'Adds <code>loading="lazy"</code> and aspect-ratio CSS to images to reduce Cumulative Layout Shift.',
        $this->sw("enable_lazy_loading", $s["enable_lazy_loading"])
      );

      $this->row(
        "Always Remove medium_large",
        "Prevents WordPress from generating the 768px intermediate image size on upload.",
        $this->sw("remove_medium_large", $s["remove_medium_large"])
      );

      // Disable additional image sizes
      $sizes = CDG_Core_Performance::get_available_image_sizes_static();
      $disabled = $s["disabled_image_sizes"] ?? [];

      if (!empty($sizes)) {
        $size_checks = "";
        foreach ($sizes as $name => $data) {
          $size_checks .= $this->check_item(
            "disabled_image_sizes[]",
            in_array($name, $disabled, true),
            "{$name} ({$data["width"]}×{$data["height"]})",
            $name
          );
        }
        $this->row(
          "Disable Image Sizes",
          "Stop WordPress from generating specific sizes on upload.",
          '<div class="cdg-check-list">' . $size_checks . "</div>"
        );
      }

      $this->row(
        "Remove s.w.org DNS Prefetch",
        "Removes the WordPress.org DNS prefetch hint from the document head.",
        $this->sw("remove_dns_prefetch", $s["remove_dns_prefetch"])
      );
    });

    $this->card(
      "Post Revisions",
      "Limit how many revisions WordPress keeps per post to reduce database bloat.",
      function () use ($s) {
        $mode = $s["post_revisions_mode"];
        $limit = absint($s["post_revisions_limit"]);

        $limited_label =
          "Limited &mdash; keep " .
          '<input type="number" name="post_revisions_limit" value="' .
          esc_attr($limit) .
          '" min="1" max="100" class="cdg-input-inline"' .
          ($mode !== "limited" ? " disabled" : "") .
          ">" .
          " revisions per post";

        $this->row(
          "Revision Policy",
          "",
          $this->radio_group_raw(
            "post_revisions_mode",
            [
              "unlimited" => [
                "Unlimited",
                "WordPress default — keep all revisions",
              ],
              "limited" => [$limited_label, ""],
              "disabled" => ["Disabled", "Never save post revisions"],
            ],
            $mode
          )
        );
      }
    );

    $this->card(
      "WordPress Heartbeat",
      "Control how often WordPress polls the server to maintain sessions and enable autosave.",
      function () use ($s) {
        $this->row(
          "Admin Heartbeat",
          "",
          '<select name="heartbeat_admin" class="cdg-select">' .
            $this->select_options(
              [
                "default" => "WordPress Default",
                "60" => "60 seconds (recommended)",
                "120" => "120 seconds",
                "disable" => "Disabled",
              ],
              $s["heartbeat_admin"]
            ) .
            "</select>"
        );

        $this->row(
          "Frontend Heartbeat",
          "",
          '<select name="heartbeat_frontend" class="cdg-select">' .
            $this->select_options(
              [
                "default" => "WordPress Default",
                "120" => "120 seconds",
                "disable" => "Disabled (recommended)",
              ],
              $s["heartbeat_frontend"]
            ) .
            "</select>"
        );
      }
    );

    $this->card(
      "Media &amp; Uploads",
      "Allow additional file types in the Media Library. Enable only what&#8217;s needed; restrict to admins where possible.",
      function () use ($s) {
        // SVG
        $this->section_label("SVG Files");
        $this->row(
          "Allow SVG Uploads",
          "SVGs can contain malicious code &#8212; restrict to admins when possible.",
          $this->sw("enable_svg_uploads", $s["enable_svg_uploads"])
        );
        $this->row(
          "Restrict to Administrators",
          "Only users with <code>manage_options</code> can upload SVGs.",
          $this->sw("svg_admin_only", $s["svg_admin_only"]),
          true,
          "cdg-svg-admin-row",
          !$s["enable_svg_uploads"] ? "cdg-disabled" : ""
        );

        // Fonts
        $this->section_label("Font Files", "OTF, TTF, WOFF, WOFF2");
        $this->row(
          "Allow Font Uploads",
          "Enables custom font files for use with custom CSS.",
          $this->sw("enable_font_uploads", $s["enable_font_uploads"])
        );
        $this->row(
          "Restrict to Administrators",
          "Only users with <code>manage_options</code> can upload font files.",
          $this->sw("font_admin_only", $s["font_admin_only"]),
          true,
          "cdg-font-admin-row",
          !$s["enable_font_uploads"] ? "cdg-disabled" : ""
        );

        // Lottie
        $this->section_label("Lottie Animations", ".json, .lottie");
        $this->row(
          "Allow Lottie Uploads",
          "Enables .json and .lottie files for animation libraries.",
          $this->sw("enable_lottie_uploads", $s["enable_lottie_uploads"])
        );
        $this->row(
          "Restrict to Administrators",
          "Only users with <code>manage_options</code> can upload Lottie files.",
          $this->sw("lottie_admin_only", $s["lottie_admin_only"]),
          true,
          "cdg-lottie-admin-row",
          !$s["enable_lottie_uploads"] ? "cdg-disabled" : ""
        );
      }
    );
  }

  /* ═══════════════════════════════════════════════════════════
   * TAB: ADMIN
   * ═══════════════════════════════════════════════════════════ */

  private function tab_admin(array $s): void
  {
    $this->card(
      "Login Page",
      "Customize the WordPress login page with site branding and a cleaner experience.",
      function () use ($s) {
        $this->row(
          "Enable Custom Login",
          "Replaces the WordPress logo with the site logo, applies the site accent color, and styles the form as a card.",
          $this->sw("enable_custom_login", $s["enable_custom_login"])
        );

        $sub_class = !$s["enable_custom_login"] ? "cdg-disabled" : "";
        echo '<div id="cdg-login-sub-settings" class="' .
          esc_attr($sub_class) .
          '">';

        // Logo upload
        $logo_id = absint($s["login_logo_id"] ?? 0);
        $logo_src = $logo_id
          ? wp_get_attachment_image_url($logo_id, "thumbnail")
          : "";

        $logo_control =
          '<div class="cdg-media-picker">' .
          '<input type="hidden" name="login_logo_id" id="cdg-login-logo-id" value="' .
          esc_attr($logo_id ?: "") .
          '">' .
          '<div class="cdg-media-preview" id="cdg-login-logo-preview"' .
          ($logo_src ? "" : ' style="display:none;"') .
          ">" .
          '<img id="cdg-login-logo-img" src="' .
          esc_url($logo_src) .
          '" alt="">' .
          "</div>" .
          '<div class="cdg-media-btns">' .
          '<button type="button" class="cdg-btn cdg-btn-secondary" id="cdg-login-logo-upload">' .
          esc_html($logo_id ? "Change Logo" : "Select Logo") .
          "</button>" .
          '<button type="button" class="cdg-btn cdg-btn-link" id="cdg-login-logo-remove"' .
          ($logo_id ? "" : ' style="display:none;"') .
          ">Remove</button>" .
          "</div>" .
          "</div>";

        $this->row(
          "Login Logo",
          "Displayed in place of the WordPress logo on the login page. Leave empty to use the site&#8217;s Customizer logo, or the WordPress logo if none is set.",
          $logo_control,
          true
        );

        $this->row(
          "Hide &#8220;Back to Site&#8221; Link",
          "Removes the <code>#backtoblog</code> link beneath the login form.",
          $this->sw(
            "login_hide_backtoblog",
            $s["login_hide_backtoblog"]
          ),
          true
        );

        $this->row(
          "Hide Register Link",
          "Hides the Register link. Only relevant when user registration is enabled in Settings.",
          $this->sw(
            "login_hide_register_link",
            $s["login_hide_register_link"]
          ),
          true
        );

        $this->row(
          "Hide Language Switcher",
          "Removes the language dropdown added in WordPress 5.9+.",
          $this->sw(
            "login_hide_language_switcher",
            $s["login_hide_language_switcher"]
          ),
          true
        );

        echo "</div>";

        $this->row(
          "Generic Error Messages",
          'Replaces specific error messages like &#8220;The password you entered for <em>username</em> is incorrect&#8221; with a generic message to prevent user enumeration.',
          $this->sw("login_generic_errors", $s["login_generic_errors"])
        );
      }
    );

    $this->card(
      "Admin Branding",
      "Customize the WordPress admin footer with CDG branding.",
      function () use ($s) {
        $this->row(
          "Enable Custom Footer",
          "Replaces &#8220;Thank you for creating with WordPress&#8221; with custom text.",
          $this->sw("enable_admin_branding", $s["enable_admin_branding"])
        );

        $this->row(
          "Footer Text",
          "HTML allowed &#8212; links, <code>em</code>, <code>strong</code> are supported.",
          '<input type="text" name="admin_footer_text" value="' .
            esc_attr($s["admin_footer_text"]) .
            '" class="cdg-input" style="width:320px;">'
        );
      }
    );

    $this->card(
      "Browser Theme Color",
      'Controls the <code>&lt;meta name="theme-color"&gt;</code> tag used by mobile browsers to tint the address bar.',
      function () use ($s) {
        $this->row(
          "Mode",
          "",
          $this->radio_group(
            "theme_color_mode",
            [
              "custom" => ["Custom", "Use a manually specified hex color"],
              "disabled" => [
                "Disabled",
                "Do not output a theme-color meta tag",
              ],
            ],
            $s["theme_color_mode"]
          )
        );

        $hex = esc_attr($s["theme_color_hex"]);
        $this->row(
          "Custom Color",
          "Only used when mode is set to &#8220;Custom&#8221;. Enter a valid hex value.",
          '<div class="cdg-color-row">' .
            '<div class="cdg-color-swatch" id="cdg-color-swatch" style="background-color:' .
            $hex .
            ';"></div>' .
            '<input type="text" name="theme_color_hex" value="' .
            $hex .
            '" class="cdg-input cdg-input-w100" placeholder="#F34F27">' .
            "</div>",
          false,
          "cdg-custom-color-row",
          $s["theme_color_mode"] !== "custom" ? "cdg-disabled" : ""
        );
      }
    );

    $this->card(
      "Custom Admin CSS",
      "Raw CSS injected into every admin page via <code>&lt;style&gt;</code> in the <code>&lt;head&gt;</code>.",
      function () use ($s) {
        echo '<div class="cdg-textarea-wrap">';
        echo '<textarea name="custom_admin_css" rows="12" class="cdg-input cdg-input-mono" style="width:100%;">' .
          esc_textarea($s["custom_admin_css"]) .
          "</textarea>";
        echo "</div>";
      }
    );
  }

  /* ═══════════════════════════════════════════════════════════
   * TAB: ROLES
   * ═══════════════════════════════════════════════════════════ */

  private function tab_roles(array $s): void
  {
    $this->card(
      "Custom Roles",
      "Creates dedicated roles for CDG staff and client users, separate from the default WordPress roles. Off by default.",
      function () use ($s) {
        $this->row(
          "Enable Custom Roles",
          "Registers three roles: <strong>Agency</strong> (clone of Administrator &#8212; full access, for CDG staff), <strong>Manager</strong> (Administrator capabilities minus plugin/theme installs, user management, and core updates), and <strong>Staff</strong> (clone of Editor &#8212; content only). Required for the Sidebar tab's role-based hide controls and the Agency Email auto-assignment below to have any effect.",
          $this->sw("enable_custom_roles", $s["enable_custom_roles"])
        );

        $sub_class = !$s["enable_custom_roles"] ? "cdg-disabled" : "";
        echo '<div id="cdg-roles-sub-settings" class="' . esc_attr($sub_class) . '">';

        $this->row(
          "Agency Email",
          "The account with this email is automatically switched to the <strong>Agency</strong> role &#8212; replacing whatever role it currently has &#8212; on login, on account creation, and whenever its profile is edited. Agency is never selectable from a dropdown; this is the only way to assign it.",
          '<input type="email" name="agency_email" value="' . esc_attr($s["agency_email"]) . '" placeholder="' . esc_attr(CDG_Core_Roles::DEFAULT_AGENCY_EMAIL) . '" class="cdg-input">',
          true
        );

        $this->row(
          "Hide Default WordPress Roles",
          "Removes Editor, Author, Contributor, and Subscriber from the Add User / Edit User / Bulk Edit role dropdowns, so only Administrator, Manager, and Staff can be newly assigned. Administrator always stays selectable, and Agency is never selectable regardless of this toggle &#8212; see Agency Email above. Existing users keep whatever role they already have &#8212; nothing is reassigned automatically.",
          $this->sw("hide_native_roles", $s["hide_native_roles"]),
          true
        );

        $this->row(
          "Rebuild Roles",
          "Re-clones Agency, Manager, and Staff from the site's <em>current</em> Administrator/Editor capabilities. Roles are normally only (re)created when missing, so a role created before another plugin (e.g. Gravity Forms) added its own capabilities to Administrator won't pick those up on its own &#8212; use this to force a refresh if Agency or Manager is missing menu items or access another plugin grants to Administrator.",
          '<button type="submit" name="cdg_core_rebuild_roles" value="1" class="cdg-btn cdg-btn-secondary">' .
            esc_html__("Rebuild Roles", "cdg-core") .
            "</button>",
          true
        );

        echo "</div>";
      }
    );
  }

  /* ═══════════════════════════════════════════════════════════
   * TAB: SIDEBAR
   * ═══════════════════════════════════════════════════════════ */

  /**
   * Render a single custom-link repeater row.
   *
   * @param int|string $index  Numeric index (or __INDEX__ for the JS template).
   * @param array      $link   Saved link data; empty array for a blank row.
   */
  private function render_custom_link_row($index, array $link = []): void
  {
    $i          = esc_attr((string) $index);
    $id         = esc_attr($link["id"]     ?? "");
    $title      = esc_attr($link["title"]  ?? "");
    $icon       = esc_attr($link["icon"]   ?? "admin-generic");
    $url        = esc_attr($link["link"]   ?? "");
    $target     = ($link["target"] ?? "_self") === "_blank" ? "_blank" : "_self";
    $hidden_for = array_map("sanitize_key", (array) ($link["hidden_for"] ?? []));
    ?>
    <div class="cdg-custom-link-item">
      <div class="cdg-custom-link-header">
        <button type="button" class="cdg-icon-picker-btn" title="<?php esc_attr_e("Choose icon", "cdg-core"); ?>">
          <span class="dashicons dashicons-<?php echo $icon; ?>" data-icon="<?php echo $icon; ?>"></span>
        </button>
        <input type="hidden" name="custom_menu_links[<?php echo $i; ?>][id]"   value="<?php echo $id; ?>">
        <input type="hidden" name="custom_menu_links[<?php echo $i; ?>][icon]" class="cdg-icon-value" value="<?php echo $icon; ?>">
        <input type="text"
               name="custom_menu_links[<?php echo $i; ?>][title]"
               value="<?php echo $title; ?>"
               placeholder="<?php esc_attr_e("Link title\xe2\x80\xa6", "cdg-core"); ?>"
               class="cdg-input cdg-custom-link-title">
        <button type="button" class="cdg-custom-link-remove cdg-btn-icon" title="<?php esc_attr_e("Remove", "cdg-core"); ?>">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="cdg-custom-link-body">
        <div class="cdg-row">
          <?php $this->row(
            "URL",
            "Full URL for the link. Use a complete address (e.g. https://&hellip;) for external links.",
            '<input type="url" name="custom_menu_links[' . $i . '][link]" value="' . $url . '" placeholder="https://&hellip;" class="cdg-input">'
          ); ?>
        </div>
        <div class="cdg-row">
          <?php
          $target_opts = [
            "_self"  => ["Same window", ""],
            "_blank" => ["New tab",     ""],
          ];
          $this->row(
            "Open in",
            "",
            $this->radio_group_raw("custom_menu_links[{$i}][target]", $target_opts, $target)
          );
          ?>
        </div>
        <div class="cdg-row">
          <?php
          $link_role_checks = "";
          foreach (CDG_Core_Roles::target_roles() as $role_slug => $label) {
            $link_role_checks .= $this->check_item(
              "custom_menu_links[{$i}][hidden_for][]",
              in_array($role_slug, $hidden_for, true),
              sprintf(
                /* translators: %s: role label, e.g. "Manager" */
                __("Hide from %s", "cdg-core"),
                $label
              ),
              $role_slug
            );
          }
          $this->row(
            "Hidden For",
            "Client roles that will <strong>not</strong> see this link in the sidebar.",
            '<div class="cdg-check-list">' . $link_role_checks . "</div>"
          );
          ?>
        </div>
      </div>
    </div>
    <?php
  }

  private function tab_sidebar(array $s): void
  {
    $captured_items = CDG_Core_Plugin_Visibility::get_captured_menu_items();
    $entry_names    = (array) ($s["sidebar_entry_names"]  ?? []);
    $entry_hidden   = (array) ($s["sidebar_entry_hidden"] ?? []);
    $submenu_names  = (array) ($s["sidebar_submenu_names"]  ?? []);
    $submenu_hidden = (array) ($s["sidebar_submenu_hidden"] ?? []);
    $custom_links   = array_values((array) ($s["custom_menu_links"]   ?? []));
    $target_roles   = CDG_Core_Roles::target_roles(); // role_slug => label

    echo '<div class="cdg-notice cdg-notice-info">' .
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>' .
      "<div>" .
      esc_html__(
        "Agency always sees the full, unmodified sidebar. The toggles below can target Administrator, Manager, and Staff.",
        "cdg-core"
      ) .
      "</div></div>";

    // ── Card 1: Sidebar Menu Items ─────────────────────────────────────────
    $this->card(
      "Sidebar Menu Items",
      "Rename any admin sidebar entry or hide it from Administrator, Manager, or Staff. Items with submenu pages can be expanded to manage those too. Visit the WordPress dashboard once to populate this list.",
      function () use ($captured_items, $entry_names, $entry_hidden, $submenu_names, $submenu_hidden, $target_roles) {
        $real = array_filter($captured_items, fn($i) => !($i["separator"] ?? false));

        if (empty($real)) {
          echo '<div class="cdg-empty">' .
            esc_html__(
              "No menu items found yet. Visit the Dashboard to populate this list.",
              "cdg-core"
            ) .
            "</div>";
          return;
        }

        echo '<div class="cdg-si-list">';

        // Column headers.
        echo '<div class="cdg-si-row cdg-si-row-head">';
        echo '<div class="cdg-si-main">' . esc_html__("Menu Item", "cdg-core") . "</div>";
        echo '<div class="cdg-si-rename">' . esc_html__("Display As", "cdg-core") . "</div>";
        foreach ($target_roles as $label) {
          echo '<div class="cdg-si-role-col">' . esc_html($label) . "</div>";
        }
        echo "</div>";

        foreach ($real as $slug => $item) {
          $title        = $item["title"] ?? $slug;
          $icon         = $item["icon"]  ?? "";
          $saved_name   = esc_attr($entry_names[$slug] ?? "");
          $hidden_roles = (array) ($entry_hidden[$slug] ?? []);
          $slug_attr    = esc_attr($slug);
          $subs         = (array) ($item["submenu"] ?? []);
          $has_subs     = !empty($subs);

          // Auto-expand if any child already has saved rename/hide data,
          // same convenience the old accordion offered per-item.
          $sub_has_data = false;
          if ($has_subs) {
            foreach ($subs as $sub_slug => $sub_item) {
              if (
                !empty($submenu_names[$slug][$sub_slug]) ||
                !empty($submenu_hidden[$slug][$sub_slug])
              ) {
                $sub_has_data = true;
                break;
              }
            }
          }
          ?>
          <div class="cdg-si-row cdg-si-parent<?php echo $sub_has_data ? " cdg-si-parent-open" : ""; ?>" data-slug="<?php echo $slug_attr; ?>">
            <div class="cdg-si-main">
              <?php if ($has_subs): ?>
                <button type="button" class="cdg-si-toggle" data-parent="<?php echo $slug_attr; ?>" aria-expanded="<?php echo $sub_has_data ? "true" : "false"; ?>" title="<?php esc_attr_e("Show submenu items", "cdg-core"); ?>">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
                </button>
              <?php else: ?>
                <span class="cdg-si-toggle-spacer" aria-hidden="true"></span>
              <?php endif; ?>
              <?php if ($icon !== "" && strpos($icon, '/') === false && $icon !== 'div'): ?>
                <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
              <?php else: ?>
                <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
              <?php endif; ?>
              <span class="cdg-si-title"><?php echo esc_html($title); ?></span>
            </div>
            <div class="cdg-si-rename">
              <input type="text" name="sidebar_entry_names[<?php echo $slug_attr; ?>]"
                     value="<?php echo $saved_name; ?>"
                     placeholder="<?php esc_attr_e("Display as\xe2\x80\xa6", "cdg-core"); ?>"
                     class="cdg-input">
            </div>
            <?php foreach ($target_roles as $role_slug => $label): ?>
              <div class="cdg-si-role-col">
                <label class="cdg-check-item cdg-check-solo" title="<?php echo esc_attr(
                  sprintf(
                    /* translators: %s: role label, e.g. "Manager" */
                    __("Hide from %s", "cdg-core"),
                    $label
                  )
                ); ?>">
                  <input type="checkbox"
                         name="sidebar_entry_hidden[<?php echo $slug_attr; ?>][]"
                         value="<?php echo esc_attr($role_slug); ?>"
                         <?php checked(in_array($role_slug, $hidden_roles, true)); ?>>
                  <span class="cdg-check-box"></span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($has_subs): foreach ($subs as $sub_slug => $sub_item):
            $sub_title        = $sub_item["title"] ?? $sub_slug;
            $sub_saved_name   = esc_attr($submenu_names[$slug][$sub_slug] ?? "");
            $sub_hidden_roles = (array) ($submenu_hidden[$slug][$sub_slug] ?? []);
            $sub_slug_attr    = esc_attr($sub_slug);
            $parent_attr      = esc_attr($slug);
            ?>
            <div class="cdg-si-row cdg-si-child<?php echo $sub_has_data ? " cdg-si-open" : ""; ?>" data-parent="<?php echo $parent_attr; ?>" data-slug="<?php echo $sub_slug_attr; ?>">
              <div class="cdg-si-main cdg-si-main-child">
                <span class="cdg-si-title cdg-si-title-child"><?php echo esc_html($sub_title); ?></span>
              </div>
              <div class="cdg-si-rename">
                <input type="text" name="sidebar_submenu_names[<?php echo $parent_attr; ?>][<?php echo $sub_slug_attr; ?>]"
                       value="<?php echo $sub_saved_name; ?>"
                       placeholder="<?php esc_attr_e("Display as\xe2\x80\xa6", "cdg-core"); ?>"
                       class="cdg-input">
              </div>
              <?php foreach ($target_roles as $role_slug => $label): ?>
                <div class="cdg-si-role-col">
                  <label class="cdg-check-item cdg-check-solo" title="<?php echo esc_attr(
                    sprintf(
                      /* translators: %s: role label, e.g. "Manager" */
                      __("Hide from %s", "cdg-core"),
                      $label
                    )
                  ); ?>">
                    <input type="checkbox"
                           name="sidebar_submenu_hidden[<?php echo $parent_attr; ?>][<?php echo $sub_slug_attr; ?>][]"
                           value="<?php echo esc_attr($role_slug); ?>"
                           <?php checked(in_array($role_slug, $sub_hidden_roles, true)); ?>>
                    <span class="cdg-check-box"></span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; endif; ?>
          <?php
        }
        echo "</div>";
      }
    );

    // ── Card 2: Custom Menu Links ──────────────────────────────────────────
    $this->card(
      "Custom Menu Links",
      "Add custom links to the admin sidebar. Each link can be shown to everyone or hidden from Administrator, Manager, or Staff.",
      function () use ($custom_links) {
        $count = count($custom_links);

        echo '<div id="cdg-links-list" data-count="' . $count . '">';
        foreach ($custom_links as $i => $link) {
          $this->render_custom_link_row($i, $link);
        }
        echo "</div>";

        // Empty state.
        $empty_style = $count > 0 ? ' style="display:none;"' : "";
        echo '<div class="cdg-snippets-empty" id="cdg-links-empty"' . $empty_style . ">" .
          esc_html__("No custom links yet. Click the button below to add one.", "cdg-core") .
          "</div>";

        // Template for JS cloning.
        echo '<template id="cdg-link-template">';
        $this->render_custom_link_row("__INDEX__");
        echo "</template>";

        echo '<div class="cdg-snippet-add-wrap">' .
          '<button type="button" id="cdg-link-add" class="cdg-btn">' .
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>' .
          esc_html__(" Add Link", "cdg-core") .
          "</button></div>";
      }
    );

    // ── Card 3: Plugin Visibility ──────────────────────────────────────────
    $this->card(
      "Plugin Visibility",
      "Hide specific installed plugins from the Plugins page for a role. Unlike the controls above, this isn&#8217;t limited to Administrator, Manager, and Staff &#8212; every native WordPress role (Editor, Author, etc.) can be targeted too, so a plugin stays hidden from a client even if they&#8217;re not on a custom role. <strong>Agency always sees every plugin</strong>, regardless of what&#8217;s checked here.",
      function () use ($s) {
        $all_plugins    = CDG_Core_Plugin_Visibility::get_all_plugins();
        $hidden_plugins = (array) ($s["hidden_plugins"] ?? []);
        $hideable_roles = CDG_Core_Roles::hideable_roles();

        if (empty($all_plugins)) {
          echo '<div class="cdg-empty">' . esc_html__("No plugins found.", "cdg-core") . "</div>";
          return;
        }

        if (empty($hideable_roles)) {
          echo '<div class="cdg-empty">' . esc_html__("No roles available to hide plugins from.", "cdg-core") . "</div>";
          return;
        }

        uasort($all_plugins, fn($a, $b) => strcasecmp($a["Name"] ?? "", $b["Name"] ?? ""));

        echo '<div class="cdg-pv-list">';

        // Column headers.
        echo '<div class="cdg-pv-row cdg-pv-row-head">';
        echo '<div class="cdg-pv-main">' . esc_html__("Plugin", "cdg-core") . "</div>";
        foreach ($hideable_roles as $label) {
          echo '<div class="cdg-pv-role-col">' . esc_html($label) . "</div>";
        }
        echo "</div>";

        foreach ($all_plugins as $plugin_file => $plugin_data) {
          $name         = $plugin_data["Name"] ?? $plugin_file;
          $hidden_roles = (array) ($hidden_plugins[$plugin_file] ?? []);
          $file_attr    = esc_attr($plugin_file);

          echo '<div class="cdg-pv-row">';
          echo '<div class="cdg-pv-main">';
          echo '<span class="cdg-si-title">' . esc_html($name) . "</span>";
          echo ' <span class="cdg-widget-id">' . esc_html($plugin_file) . "</span>";
          echo "</div>";

          foreach ($hideable_roles as $role_slug => $label) {
            echo '<div class="cdg-pv-role-col">';
            echo '<label class="cdg-check-item cdg-check-solo" title="' .
              esc_attr(
                sprintf(
                  /* translators: %s: role label, e.g. "Manager" */
                  __("Hide from %s", "cdg-core"),
                  $label
                )
              ) . '">';
            echo '<input type="checkbox" name="hidden_plugins[' . $file_attr . '][]" value="' .
              esc_attr($role_slug) . '"' .
              (in_array($role_slug, $hidden_roles, true) ? " checked" : "") . ">";
            echo '<span class="cdg-check-box"></span>';
            echo "</label>";
            echo "</div>";
          }

          echo "</div>";
        }

        echo "</div>";
      }
    );
  }

  /* ═══════════════════════════════════════════════════════════
   * TAB: CODE SNIPPETS
   * ═══════════════════════════════════════════════════════════ */

  private function tab_snippets(array $s): void
  {
    $snippets = array_values((array) ($s["code_snippets"] ?? []));

    echo '<div class="cdg-notice cdg-notice-warn">' .
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' .
      "<div>" .
      esc_html__(
        "PHP snippets are executed server-side via eval(). Errors are caught silently to prevent site lockout — test PHP carefully before activating.",
        "cdg-core"
      ) .
      "</div>" .
      "</div>";

    $this->card(
      "Code Snippets",
      "Snippets run on the <strong>front end only</strong>. CSS, JS, and HTML are injected into the page head or footer. PHP runs on the <code>init</code> hook. For custom admin styles, use the <a href=\"" . esc_url( admin_url("options-general.php?page=cdg-core-settings&tab=admin") ) . "\" class=\"cdg-guide-link\">Admin tab &rsaquo; Custom Admin CSS</a>.",
      function () use ($snippets) {
        $count = count($snippets);

        echo '<div id="cdg-snippets-list" data-count="' .
          esc_attr((string) $count) .
          '">';

        foreach ($snippets as $i => $snippet) {
          $this->render_snippet_row($i, $snippet);
        }

        echo "</div>";

        // Always render — hidden via inline style when rows exist so JS can
        // reveal it after the last row is removed on the same page load.
        $empty_style = $count > 0 ? ' style="display:none;"' : '';
        echo '<div class="cdg-snippets-empty" id="cdg-snippets-empty"' .
          $empty_style .
          '>' .
          esc_html__("No snippets added yet.", "cdg-core") .
          "</div>";

        echo '<div class="cdg-snippet-add-wrap">';
        echo '<button type="button" class="cdg-btn cdg-btn-secondary" id="cdg-snippet-add">';
        echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>';
        echo esc_html__("Add Snippet", "cdg-core");
        echo "</button>";
        echo "</div>";

        echo '<template id="cdg-snippet-template">';
        $this->render_snippet_row("__INDEX__", []);
        echo "</template>";
      }
    );
  }

  private function render_snippet_row($index, array $snippet): void
  {
    $active   = !empty($snippet["active"]);
    $title    = esc_attr($snippet["title"] ?? "");
    $desc     = esc_attr($snippet["description"] ?? "");
    $type     = $snippet["type"] ?? "css";
    $location = $snippet["location"] ?? "head";
    $code     = esc_textarea($snippet["code"] ?? "");
    $idx      = is_string($index) ? $index : (string) $index;

    if (!in_array($type, ["css", "js", "html", "php"], true)) {
      $type = "css";
    }
    if (!in_array($location, ["head", "footer"], true)) {
      $location = "head";
    }

    $show_location = in_array($type, ["css", "js", "html"], true);
    $loc_style     = $show_location ? "" : ' style="display:none;"';

    echo '<div class="cdg-snippet-item">';

    // Active + title + type
    echo '<div class="cdg-snippet-top-row">';
    echo '<label class="cdg-switch" title="Active">';
    echo '<input type="checkbox" name="code_snippets[' .
      $idx .
      '][active]" value="1"' .
      ($active ? " checked" : "") .
      ">";
    echo '<span class="cdg-switch-slider"></span>';
    echo "</label>";
    echo '<input type="text" name="code_snippets[' .
      $idx .
      '][title]" value="' .
      $title .
      '" placeholder="' .
      esc_attr__("Snippet title", "cdg-core") .
      '" class="cdg-input">';
    echo '<select name="code_snippets[' .
      $idx .
      '][type]" class="cdg-select cdg-snippet-type">';
    foreach (
      ["css" => "CSS", "js" => "JavaScript", "html" => "HTML", "php" => "PHP"]
      as $val => $label
    ) {
      echo '<option value="' .
        esc_attr($val) .
        '"' .
        ($type === $val ? " selected" : "") .
        ">" .
        esc_html($label) .
        "</option>";
    }
    echo "</select>";
    echo "</div>";

    // Description
    echo '<input type="text" name="code_snippets[' .
      $idx .
      '][description]" value="' .
      $desc .
      '" placeholder="' .
      esc_attr__("Description (optional)", "cdg-core") .
      '" class="cdg-input">';

    // Location (JS / HTML only)
    echo '<div class="cdg-snippet-location-row"' . $loc_style . ">";
    echo '<span class="cdg-snippet-location-label">' .
      esc_html__("Location", "cdg-core") .
      "</span>";
    echo '<div class="cdg-radio-group">';
    foreach (["head" => "Head", "footer" => "Footer"] as $val => $label) {
      echo '<label class="cdg-radio-card">';
      echo '<input type="radio" name="code_snippets[' .
        $idx .
        '][location]" value="' .
        esc_attr($val) .
        '"' .
        ($location === $val ? " checked" : "") .
        ">";
      echo '<span class="cdg-radio-dot"></span>';
      echo '<span class="cdg-radio-text"><strong>' .
        esc_html($label) .
        "</strong></span>";
      echo "</label>";
    }
    echo "</div>";
    echo "</div>";

    // Code
    echo '<textarea name="code_snippets[' .
      $idx .
      '][code]" rows="8" class="cdg-input cdg-input-mono cdg-snippet-code">' .
      $code .
      "</textarea>";

    // Footer
    echo '<div class="cdg-snippet-footer">';
    echo '<button type="button" class="cdg-btn cdg-btn-link cdg-snippet-remove">' .
      esc_html__("Remove Snippet", "cdg-core") .
      "</button>";
    echo "</div>";

    echo "</div>";
  }

  /* ═══════════════════════════════════════════════════════════
   * TAB: GUIDE
   * ═══════════════════════════════════════════════════════════ */

  private function tab_guide(): void
  {
    // ── Overview ──────────────────────────────────────────────
    $this->card("About CDG Core", "", function () {
      echo '<div class="cdg-guide-body">';
      echo '<p class="cdg-guide-intro">CDG Core handles WordPress optimization, security hardening, and agency-specific features for Crawford Design Group client sites.</p>';
      echo '<p class="cdg-guide-intro" style="margin-top:10px;">Settings are organized into tabs in the left sidebar. The Security Audit is a separate read-only tool available under <a class="cdg-guide-link" href="' .
        esc_url(admin_url("tools.php?page=cdg-security-audit")) .
        '">Tools &rsaquo; Security Audit</a>.</p>';
      echo "</div>";
    });

    // ── Features ──────────────────────────────────────────────
    $this->card(
      "Features",
      "Custom post types and dashboard enhancements.",
      function () {
        $this->section_label("Documentation System");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "Documentation CPT",
          "Registers a <code>cdg_documentation</code> post type with category and tag taxonomies. Articles are created under the Documentation menu and can be assigned to categories. Disable this if the site does not need an internal knowledge base."
        );
        $this->guide_item(
          "Dashboard Widgets",
          "When enabled, documentation articles appear on the WordPress dashboard in card widgets grouped by category. The Widget Style setting controls whether each category gets its own widget (Informative) or all articles are collapsed into one (Minimal)."
        );
        $this->guide_item(
          "Docs Per Widget",
          "Caps the number of articles shown per widget. If a category has more articles than the limit, they are truncated. Increase to 10&ndash;20 for larger knowledge bases."
        );
        echo "</div>";

        $this->section_label("CPT Dashboard Widgets");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "CPT Widgets",
          "Displays quick-access dashboard widgets for selected custom post types. Useful for giving editors fast access to common post types without navigating the admin menu."
        );
        $this->guide_item(
          "Post Types to Show",
          "Only custom post types registered at page load are listed. If a CPT is not appearing, ensure it is registered before CDG Core initializes (priority 10 on <code>init</code>)."
        );
        $this->guide_item(
          "Show Recent Posts",
          "When on, each CPT widget also shows a short list of recent entries. Set the limit to 1&ndash;3 for compact widgets."
        );
        echo "</div>";
      }
    );

    // ── WP Cleanup ────────────────────────────────────────────
    $this->card(
      "WP Cleanup",
      "Site defaults, head tag cleanup, and dashboard widget management.",
      function () {
        $this->section_label("WordPress Comments");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "Disable Comments",
          "Completely removes the WordPress comment system site-wide: hides the Comments admin menu, disables Discussion settings, blocks direct access to comment-related admin pages, disables comment REST API endpoints, and redirects comment feeds. Safe to enable on all sites that do not use comments."
        );
        echo "</div>";

        $this->section_label("WordPress Head Cleanup");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "WordPress Version",
          'Removes the <code>&lt;meta name="generator"&gt;</code> tag that broadcasts the WordPress version. Reducing version exposure is a first-line hardening step. The Security Audit also checks for this.'
        );
        $this->guide_item(
          "Shortlink / Adjacent Posts / oEmbed / REST API Link",
          "Each removes a corresponding <code>&lt;link&gt;</code> tag from the document head. These are rarely needed on production sites and add unnecessary weight to every page response."
        );
        $this->guide_item(
          "WordPress Emojis",
          "Removes the emoji detection script and stylesheet that WordPress injects on every page. Saves two HTTP requests per page load on sites that do not use WordPress emoji shortcodes."
        );
        echo "</div>";

        $this->section_label("Dashboard Widgets");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "WordPress Core Widgets",
          "Toggle individual core dashboard widgets. Quick Draft, Events &amp; News, and the PHP/browser nag banners are hidden by default as they add noise for client accounts."
        );
        $this->guide_item(
          "Plugin Widgets",
          "Plugin-registered dashboard widgets are detected and listed here after you visit the Dashboard at least once. Check any that should be hidden from all users."
        );
        echo "</div>";
      }
    );

    // ── Security ──────────────────────────────────────────────
    $this->card(
      "Security",
      "Hardening toggles.",
      function () {
        $this->section_label("Security Hardening");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "Disable XML-RPC",
          "Completely disables the <code>xmlrpc.php</code> endpoint. XML-RPC is a common target for brute-force amplification attacks. Disable unless a specific integration requires it (almost none do on modern sites)."
        );
        $this->guide_item(
          "Block Dangerous Uploads",
          "Prevents <code>.php</code>, <code>.exe</code>, <code>.js</code>, and other executable file types from being uploaded through the Media Library. Stops a common webshell upload vector."
        );
        $this->guide_item(
          "Remove X-Powered-By Header",
          'Strips the HTTP response header that reveals the PHP version to clients. Complements SpinupWP\'s server-level header hardening.'
        );
        $this->guide_item(
          "Disable Code Editor",
          "Hides the Appearance &rsaquo; Theme Editor and Plugins &rsaquo; Editor from the admin for users without <code>manage_options</code>. Prevents accidental or malicious code edits through the browser."
        );
        echo "</div>";
        echo '<div class="cdg-guide-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg><span>Security headers (HSTS, X-Frame-Options, X-XSS-Protection, X-Content-Type-Options) are managed at the Nginx level by SpinupWP and are not duplicated here.</span></div>';
      }
    );

    // ── Performance ───────────────────────────────────────────
    $this->card(
      "Performance",
      "Gutenberg, query, image, revision, heartbeat, and media upload settings.",
      function () {
        $this->section_label("Block Editor (Gutenberg)");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "Optimize (recommended)",
          "Removes Gutenberg CSS and JS on pages that are not using the block editor. On sites built with a page builder, this eliminates unnecessary asset loading on virtually every front-end page."
        );
        $this->guide_item(
          "Disable",
          "Replaces the block editor entirely with the Classic Editor. Use this only if the site has no block-based content and the team prefers the classic editing experience."
        );
        echo "</div>";

        $this->section_label("Query Optimizations");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "Optimize Search",
          "Limits search queries to specific post types, reducing the number of database rows scanned per search request."
        );
        $this->guide_item(
          "Optimize Archives",
          "Restricts the fields returned by archive page queries to reduce memory usage per request."
        );
        echo "</div>";

        $this->section_label("Images");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "Native Lazy Loading",
          'Adds <code>loading="lazy"</code> and an <code>aspect-ratio</code> inline style to images, reducing Cumulative Layout Shift and deferring off-screen image loads.'
        );
        $this->guide_item(
          "Always Remove medium_large",
          "Prevents WordPress from generating the 768px intermediate size on every upload. This size is rarely used and wastes disk space and upload time."
        );
        $this->guide_item(
          "Disable Image Sizes",
          "Stop specific image sizes from being generated on future uploads. Existing images are not affected; use a plugin like Regenerate Thumbnails if you need to clean up historical sizes."
        );
        $this->guide_item(
          "Remove DNS Prefetch",
          'Removes the <code>&lt;link rel="dns-prefetch" href="//s.w.org"&gt;</code> hint added by WordPress to the document head.'
        );
        echo "</div>";

        $this->section_label("Post Revisions");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "Limited (recommended)",
          "Caps the number of revisions WordPress saves per post. 5 revisions is a reasonable default that preserves meaningful history without accumulating thousands of rows for frequently edited pages. This setting overrides any <code>WP_POST_REVISIONS</code> constant defined in <code>wp-config.php</code>."
        );
        echo "</div>";

        $this->section_label("Heartbeat");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "Admin Heartbeat",
          "Controls how often the browser polls the server in admin pages. The default of 15 seconds is aggressive. 60 seconds is recommended for most sites; it still supports autosave and session keepalive with much less server load."
        );
        $this->guide_item(
          "Frontend Heartbeat",
          "The heartbeat API runs on the front end by default, serving no practical purpose on most sites. Disabled is recommended unless a theme or plugin requires it."
        );
        echo "</div>";

        $this->section_label("Media &amp; Uploads");
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "SVG Uploads",
          "Allows SVG and SVGZ files in the Media Library with preview support and automatic dimension detection. The Restrict to Administrators option limits uploads to <code>manage_options</code> users. Enable the restriction unless editors need to upload SVGs directly."
        );
        $this->guide_item(
          "Font Uploads",
          "Enables OTF, TTF, WOFF, and WOFF2 uploads for use in custom <code>@font-face</code> declarations. Restrict to administrators in most cases."
        );
        $this->guide_item(
          "Lottie Uploads",
          "Enables <code>.json</code> and <code>.lottie</code> animation files in the Media Library. Restrict to administrators unless editors manage their own animation assets."
        );
        echo "</div>";
      }
    );

    // ── Admin ─────────────────────────────────────────────────
    $this->card(
      "Admin",
      "Login page branding, admin footer, theme color, and custom CSS.",
      function () {
      echo '<div class="cdg-guide-body cdg-guide-group">';

      $this->section_label("Login Page");
      $this->guide_item(
        "Enable Custom Login",
        "Applies site branding to the WordPress login page: replaces the WordPress logo with the site&#8217;s custom logo (set via Appearance &rsaquo; Customize), styles the form as a card on a light-gray background, and applies the site&#8217;s accent color (from the Browser Theme Color setting) to the submit button and input focus rings. If no custom logo is set, the layout improvements still apply but the WordPress logo remains."
      );
      $this->guide_item(
        "Generic Error Messages",
        'Replaces WordPress&#8217;s specific login errors (&#8220;The password you entered for <em>username</em> is incorrect&#8221;) with a single generic message. This prevents attackers from confirming valid usernames through login-form enumeration. Operates independently of the custom login toggle so it can be enabled on its own.'
      );
      $this->guide_item(
        "Hide Back to Site / Register / Language Switcher",
        "Removes links and UI elements below the login form that are rarely needed on client sites. The Register link only appears when user registration is enabled in Settings &rsaquo; General, so hiding it here is a belt-and-suspenders measure. The language switcher (added in WP 5.9) is hidden by default as most single-language sites do not need it."
      );

      $this->section_label("Admin Footer &amp; CSS");
      $this->guide_item(
        "Custom Footer",
        'Replaces the default "Thank you for creating with WordPress" footer text with the CDG branding link. The text field supports basic HTML: links, <code>em</code>, and <code>strong</code>.'
      );
      $this->guide_item(
        "Browser Theme Color",
        'Outputs a <code>&lt;meta name="theme-color"&gt;</code> tag used by Chrome, Safari, and other mobile browsers to tint the address bar. Custom mode allows a specific hex value. Disable if the theme outputs its own theme-color tag.'
      );
      $this->guide_item(
        "Custom Admin CSS",
        "Raw CSS injected via a <code>&lt;style&gt;</code> block in the <code>&lt;head&gt;</code> of every admin page. Useful for per-site admin UI tweaks without maintaining a separate admin stylesheet. A set of CDG default styles (rounded inputs, consistent borders, orange accent links) is applied by default."
      );
      echo "</div>";
    });

    // ── Plugins ───────────────────────────────────────────────
    $this->card(
      "Plugin Visibility",
      "Control which plugins and sidebar menu items specific users can see.",
      function () {
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "Plugin Page Visibility",
          "Checked plugins are hidden from the Plugins admin page for that user. The plugins remain fully active — only their row in the list is removed."
        );
        $this->guide_item(
          "Admin Sidebar Menu",
          "Checked menu items are removed from the left admin sidebar for that user. The underlying pages are still accessible by direct URL — this only hides the navigation entry."
        );
        $this->guide_item(
          "Common use",
          "Hide maintenance, security, or developer plugins from specific client accounts to keep the dashboard uncluttered and reduce the risk of accidental deactivation."
        );
        echo "</div>";
        echo '<div class="cdg-guide-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg><span>The Admin Sidebar list is populated on your first dashboard visit after activating a plugin.</span></div>';
      }
    );

    // ── Security Audit ────────────────────────────────────────
    $this->card(
      "Security Audit",
      'A read-only diagnostic page available under <a class="cdg-guide-link" href="' .
        esc_url(admin_url("tools.php?page=cdg-security-audit")) .
        '">Tools &rsaquo; Security Audit</a>.',
      function () {
        echo '<div class="cdg-guide-body cdg-guide-group">';
        $this->guide_item(
          "What it checks",
          "WP Generator meta tag exposure, WP_DEBUG on production, debug.log public accessibility, user enumeration via the REST API, PHP execution in the uploads directory, and inactive administrator accounts (90-day threshold)."
        );
        $this->guide_item(
          "Caching",
          "Results are cached for 1 hour using a WordPress transient. Click Re-run Audit to force a fresh check immediately, or wait for the cache to expire."
        );
        $this->guide_item(
          "Login Tracking",
          "CDG Core records a <code>cdg_last_login</code> timestamp on every successful login. This meta key is used by the inactive admins check. Accounts that have never logged in since the plugin was installed are flagged as unverified, not definitively inactive."
        );
        $this->guide_item(
          "HTTP checks",
          "The debug.log, user enumeration, and PHP execution checks make outbound HTTP requests from the server to itself. SpinupWP firewall rules or a CDN in front of the site may cause these checks to report pass even when the vulnerability exists at the origin."
        );
        echo "</div>";
      }
    );
  }

  /**
   * Render a single guide item (label + description) for the Guide tab.
   *
   * @param string $label Plain-text label.
   * @param string $desc  HTML description (wp_kses_post applied).
   */
  private function guide_item(string $label, string $desc): void
  {
    echo "<div>";
    echo '<div class="cdg-guide-item-label">' . esc_html($label) . "</div>";
    echo '<div class="cdg-guide-item-desc">' . wp_kses_post($desc) . "</div>";
    echo "</div>";
  }

  /* ═══════════════════════════════════════════════════════════
   * PRIVATE UTILITIES
   * ═══════════════════════════════════════════════════════════ */

  /** Build <option> tags for a <select> element. */
  private function select_options(array $options, string $current): string
  {
    $out = "";
    foreach ($options as $value => $label) {
      $selected = (string) $value === $current ? " selected" : "";
      $out .=
        '<option value="' .
        esc_attr((string) $value) .
        '"' .
        $selected .
        ">" .
        esc_html($label) .
        "</option>";
    }
    return $out;
  }

  /**
   * Radio group that allows raw HTML in the label (e.g. inline inputs).
   * Only use for developer-controlled labels, not user data.
   *
   * @param array $options  ['value' => ['HTML label', 'hint']]
   */
  private function radio_group_raw(
    string $name,
    array $options,
    string $current
  ): string {
    $out = '<div class="cdg-radio-group">';
    foreach ($options as $value => $info) {
      $label = $info[0];
      $hint = $info[1] ?? "";
      $checked = (string) $value === $current ? " checked" : "";

      $out .=
        '<label class="cdg-radio-card">' .
        '<input type="radio" name="' .
        esc_attr($name) .
        '" value="' .
        esc_attr((string) $value) .
        '"' .
        $checked .
        ">" .
        '<span class="cdg-radio-dot"></span>' .
        '<span class="cdg-radio-text"><strong>' .
        $label .
        "</strong>"; // phpcs:ignore WordPress.Security.EscapeOutput

      if ($hint !== "") {
        $out .= "<span>" . esc_html($hint) . "</span>";
      }

      $out .= "</span></label>";
    }
    $out .= "</div>";
    return $out;
  }
}
