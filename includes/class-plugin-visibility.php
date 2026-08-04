<?php
/**
 * Plugin Visibility & Sidebar Manager
 *
 * Renames and hides top-level sidebar menu entries per-role, and injects
 * custom menu links, via the admin_menu hook. Targetable roles are
 * Administrator, Manager, and Staff (see CDG_Core_Roles::target_roles())
 * — Agency is never targeted, so it always sees the full, unmodified
 * sidebar.
 *
 * Also filters the Installed Plugins list itself (`all_plugins`) so specific
 * plugins can be hidden from any role — every native WordPress role
 * included, not just the three above. Agency always bypasses this and sees
 * every plugin (see CDG_Core_Roles::hideable_roles()).
 *
 * @package CDG_Core
 * @since 1.5.0
 */

declare(strict_types=1);

class CDG_Core_Plugin_Visibility
{
    /** @var CDG_Core */
    private CDG_Core $plugin;

    public function __construct(CDG_Core $plugin)
    {
        $this->plugin = $plugin;

        // Capture the full menu before any removals.
        add_action('admin_menu', [$this, 'capture_sidebar_menu'], 100);

        // Apply customisations at 999 (after all plugins have registered menus).
        add_action('admin_menu', [$this, 'apply_sidebar_changes'], 999);
        add_action('admin_menu', [$this, 'apply_custom_links'], 999);

        // Inject a tiny inline script that sets target="_blank" on custom links.
        add_action('admin_footer', [$this, 'open_new_tab_links']);

        // Invalidate cache when the active plugin set changes.
        add_action('activated_plugin',   [self::class, 'invalidate_menu_cache']);
        add_action('deactivated_plugin', [self::class, 'invalidate_menu_cache']);

        // Hide configured plugins from the Installed Plugins list per-role.
        add_filter('all_plugins', [$this, 'filter_hidden_plugins']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Capture
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Persist all top-level admin menu items (including separators), each
     * with its submenu items nested underneath, into a transient so the
     * settings UI always has the full, unmodified list.
     */
    public function capture_sidebar_menu(): void
    {
        global $menu, $submenu;
        if (!is_array($menu)) {
            return;
        }

        $items = [];

        foreach ($menu as $item) {
            $slug = $item[2] ?? '';
            if ($slug === '') {
                continue;
            }

            $is_separator = str_starts_with($slug, 'separator');
            $title        = $is_separator
                ? ''
                : trim(wp_strip_all_tags($item[0] ?? $slug));

            if ($title === '' && !$is_separator) {
                $title = $slug;
            }

            // Normalise icon: store the dashicons class-name suffix or the
            // raw value (could be an image URL or 'div' for CSS-sprites).
            $icon_raw = $item[6] ?? '';
            $icon     = preg_replace('/^dashicons-/', '', $icon_raw);

            // Submenu items for this parent (WordPress often duplicates the
            // parent as the first submenu entry — that's expected and kept
            // as-is, since some sites rename it independently of the parent).
            $subs = [];
            if (!$is_separator && isset($submenu[$slug]) && is_array($submenu[$slug])) {
                foreach ($submenu[$slug] as $sub_item) {
                    $sub_slug = $sub_item[2] ?? '';
                    if ($sub_slug === '') {
                        continue;
                    }

                    $sub_title = trim(wp_strip_all_tags($sub_item[0] ?? $sub_slug));
                    if ($sub_title === '') {
                        $sub_title = $sub_slug;
                    }

                    $subs[$sub_slug] = [
                        'slug'  => $sub_slug,
                        'title' => $sub_title,
                    ];
                }
            }

            $items[$slug] = [
                'slug'      => $slug,
                'title'     => $title,
                'icon'      => $icon,
                'separator' => $is_separator,
                'submenu'   => $subs,
            ];
        }

        $existing = get_transient('cdg_sidebar_menu_items');
        if ($existing !== $items) {
            set_transient('cdg_sidebar_menu_items', $items, DAY_IN_SECONDS);
        }
    }

    /**
     * @return array<string, array{
     *     slug:string,
     *     title:string,
     *     icon:string,
     *     separator:bool,
     *     submenu:array<string, array{slug:string,title:string}>
     * }>
     */
    public static function get_captured_menu_items(): array
    {
        $items = get_transient('cdg_sidebar_menu_items');
        return is_array($items) ? $items : [];
    }

    public static function invalidate_menu_cache(): void
    {
        delete_transient('cdg_sidebar_menu_items');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rename / hide existing entries
    // ─────────────────────────────────────────────────────────────────────────

    public function apply_sidebar_changes(): void
    {
        global $menu, $submenu;
        if (!is_array($menu)) {
            return;
        }

        $user_roles  = wp_get_current_user()->roles;
        $names       = (array) $this->plugin->get_setting('sidebar_entry_names');
        $hidden      = (array) $this->plugin->get_setting('sidebar_entry_hidden');
        $sub_names   = (array) $this->plugin->get_setting('sidebar_submenu_names');
        $sub_hidden  = (array) $this->plugin->get_setting('sidebar_submenu_hidden');

        if (empty($names) && empty($hidden) && empty($sub_names) && empty($sub_hidden)) {
            return;
        }

        foreach ($menu as $pos => $item) {
            $slug = $item[2] ?? '';
            if ($slug === '') {
                continue;
            }

            // Rename (all users).
            if (isset($names[$slug]) && $names[$slug] !== '') {
                $menu[$pos][0] = esc_html($names[$slug]);
            }

            // Hide (per-role — Administrator, Manager, and Staff are all
            // targetable; Agency is never listed here, so it's never
            // affected).
            if (isset($hidden[$slug])) {
                $roles = (array) $hidden[$slug];
                if (array_intersect($user_roles, $roles)) {
                    remove_menu_page($slug);
                    // Parent is gone — its submenu is inaccessible from the
                    // nav either way, so there's nothing more to do here.
                    continue;
                }
            }

            // Submenu rename / hide for this parent.
            if (isset($submenu[$slug]) && is_array($submenu[$slug])) {
                if (isset($sub_names[$slug]) && is_array($sub_names[$slug])) {
                    foreach ($submenu[$slug] as $sub_pos => $sub_item) {
                        $sub_slug = $sub_item[2] ?? '';
                        if (
                            $sub_slug !== '' &&
                            isset($sub_names[$slug][$sub_slug]) &&
                            $sub_names[$slug][$sub_slug] !== ''
                        ) {
                            $submenu[$slug][$sub_pos][0] = esc_html($sub_names[$slug][$sub_slug]);
                        }
                    }
                }

                if (isset($sub_hidden[$slug]) && is_array($sub_hidden[$slug])) {
                    foreach ($sub_hidden[$slug] as $sub_slug => $sub_roles) {
                        if (array_intersect($user_roles, (array) $sub_roles)) {
                            remove_submenu_page($slug, $sub_slug);
                        }
                    }
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Custom menu links
    // ─────────────────────────────────────────────────────────────────────────

    public function apply_custom_links(): void
    {
        global $menu;

        $links = (array) $this->plugin->get_setting('custom_menu_links');
        if (empty($links)) {
            return;
        }

        $user_roles = wp_get_current_user()->roles;
        $max_pos    = !empty($menu) ? max(array_keys($menu)) : 80;

        foreach ($links as $index => $link) {
            if (!is_array($link)) {
                continue;
            }

            $id    = sanitize_key($link['id'] ?? '');
            $title = sanitize_text_field($link['title'] ?? '');
            if ($id === '' || $title === '') {
                continue;
            }

            // Visibility check (per-role).
            $hidden_for = (array) ($link['hidden_for'] ?? []);
            if (array_intersect($user_roles, $hidden_for)) {
                continue;
            }

            $icon   = sanitize_text_field($link['icon'] ?? 'admin-generic');
            $url    = $link['link'] ?? '#';
            $target = ($link['target'] ?? '_self') === '_blank' ? '_blank' : '_self';

            // Ensure the URL is treated as-is (not wrapped in admin_url).
            if ($url !== '#' && !preg_match('#^https?://#', $url)) {
                $url = admin_url(ltrim($url, '/'));
            }

            $slug = 'cdg_link_' . $id;
            $css  = 'menu-top toplevel_page_' . $slug .
                    ($target === '_blank' ? ' cdg-link-newtab' : '');

            $pos         = $max_pos + 2 + ($index * 2);
            $menu[$pos]  = [
                esc_html($title),
                'read',
                esc_url_raw($url),
                esc_html($title),
                $css,
                'toplevel_page_' . $slug,
                'dashicons-' . preg_replace('/^dashicons-/', '', $icon),
            ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // New-tab support for custom links
    // ─────────────────────────────────────────────────────────────────────────

    public function open_new_tab_links(): void
    {
        ?>
<script>
(function(){
  document.querySelectorAll('#adminmenu .cdg-link-newtab > a').forEach(function(a){
    a.target = '_blank';
    a.rel    = 'noopener noreferrer';
  });
})();
</script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Plugin list visibility
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Remove configured plugins from the Installed Plugins list (and
     * anywhere else `all_plugins` is consulted) for the current user's
     * role(s). Agency always bypasses this entirely, regardless of what's
     * configured — including on sites where a user happens to also hold a
     * native role like Administrator alongside Agency.
     *
     * @param array<string, array<string, string>> $plugins Installed plugins keyed by plugin file.
     * @return array<string, array<string, string>>
     */
    public function filter_hidden_plugins(array $plugins): array
    {
        $user_roles = wp_get_current_user()->roles;

        if (in_array(CDG_Core_Roles::AGENCY, $user_roles, true)) {
            return $plugins;
        }

        $hidden = (array) $this->plugin->get_setting('hidden_plugins');
        if (empty($hidden)) {
            return $plugins;
        }

        foreach ($hidden as $plugin_file => $roles) {
            if (array_intersect($user_roles, (array) $roles)) {
                unset($plugins[$plugin_file]);
            }
        }

        return $plugins;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utilities
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return all installed plugins — used both by the Plugin Visibility
     * card in the settings UI and by filter_hidden_plugins() above (via
     * the admin sanitizer, to validate submitted plugin files).
     *
     * @return array<string, array<string, string>>
     */
    public static function get_all_plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return get_plugins();
    }
}
