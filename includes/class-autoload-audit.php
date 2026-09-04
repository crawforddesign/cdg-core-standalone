<?php
/**
 * Autoload Audit
 *
 * Registers a Tools -> Autoloaded Options page that lists the site's
 * autoloaded wp_options rows by size (mirroring the Site Health "Autoloaded
 * options could affect performance" check) and lets an admin flip autoload
 * off/on per option.
 *
 * Safety model:
 *   - Only the `autoload` column is ever touched. The option's stored value
 *     is never read back or rewritten, so there is no risk of corrupting or
 *     re-serializing data.
 *   - A hard-coded list of WordPress-core and CDG Core options can never be
 *     disabled through this screen, even via a crafted request.
 *   - Every change is appended to a small log (itself not autoloaded) with a
 *     one-click "Undo" link, so a change that turns out to break something
 *     can be reverted without touching phpMyAdmin.
 *
 * @package CDG_Core
 * @since 1.9.11
 */

declare(strict_types=1);

class CDG_Core_Autoload_Audit
{
    private const MENU_SLUG    = 'cdg-autoload-options';
    private const NONCE_ACTION = 'cdg_autoload_change';
    private const POST_ACTION  = 'cdg_autoload_change';
    private const LOG_OPTION   = 'cdg_autoload_change_log';
    private const LOG_MAX      = 20;
    private const DISPLAY_LIMIT = 150;

    /**
     * WordPress's own Site Health threshold (introduced in WP 6.6) for the
     * "Autoloaded options could affect performance" warning.
     */
    private const WARN_THRESHOLD_BYTES = 800 * 1024;

    /**
     * Autoload column values that mean "does NOT autoload" across both the
     * legacy yes/no scheme and the yes/no/on/off/auto-* scheme WP 6.6 added.
     */
    private const NOT_AUTOLOADED_VALUES = ['no', 'off', 'auto-off'];

    /**
     * Options that can never be disabled from this screen. WordPress core
     * (and CDG Core itself) reads these on effectively every request, so
     * disabling autoload here would trade a large payload for an N+1 query
     * problem instead of fixing anything.
     */
    private const PROTECTED_OPTIONS = [
        'siteurl', 'home', 'blogname', 'blogdescription', 'admin_email', 'blog_charset',
        'template', 'stylesheet', 'current_theme', 'stylesheet_root', 'template_root',
        'active_plugins', 'active_sitewide_plugins', 'sidebars_widgets', 'cron',
        'db_version', 'initial_db_version', 'db_upgraded', 'WPLANG',
        'permalink_structure', 'rewrite_rules', 'category_base', 'tag_base',
        'default_role', 'users_can_register', 'timezone_string', 'gmt_offset',
        'date_format', 'time_format', 'start_of_week',
        'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'posts_per_rss',
        'wp_page_for_privacy_policy', 'site_icon', 'fresh_site',
        'finished_splitting_shared_terms', 'auto_update_core_dev',
        'auto_update_core_minor', 'auto_update_core_major', 'wp_force_deactivated_plugins',
        'recovery_keys', 'https_detection_errors', 'can_compress_scripts',
        'new_admin_email', 'secret', 'auth_key', 'auth_salt', 'secure_auth_key',
        'secure_auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt',
        'uninstall_plugins', 'widget_categories', 'widget_text', 'widget_rss',
        'default_comment_status', 'default_ping_status', 'blog_public',
        'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h',
        'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop',
        'medium_large_size_w', 'medium_large_size_h',
        // CDG Core's own state — read on nearly every front-end and admin request.
        'cdg_core_settings', 'cdg_core_version',
    ];

    /**
     * Prefix-based protection for option families rather than exact names.
     */
    private const PROTECTED_PREFIXES = [
        'theme_mods_',
        'widget_',
        'auto_update_',
        '_site_transient_timeout_',
        '_transient_timeout_',
        'cdg_core_',
    ];

    /**
     * Option name substrings (case-insensitive) that get an informational
     * "page builder" badge in the table. Not protected — just a heads-up,
     * since Divi/Elementor often need some of their settings on every render.
     */
    private const BUILDER_HINTS = [
        'elementor' => 'Elementor',
        'et_'       => 'Divi',
        'et-'       => 'Divi',
        'divi'      => 'Divi',
    ];

    /**
     * @var CDG_Core
     */
    private CDG_Core $plugin;

    /**
     * @param CDG_Core $plugin
     */
    public function __construct(CDG_Core $plugin)
    {
        $this->plugin = $plugin;

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_' . self::POST_ACTION, [$this, 'handle_change']);
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Register the Tools -> Autoloaded Options submenu page.
     */
    public function register_menu(): void
    {
        add_management_page(
            __('Autoloaded Options', 'cdg-core'),
            __('Autoloaded Options', 'cdg-core'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    /**
     * Handle a disable/enable request from the table or the change log.
     */
    public function handle_change(): void
    {
        $option_name = sanitize_text_field(wp_unslash($_POST['option_name'] ?? ''));

        check_admin_referer(self::NONCE_ACTION . '_' . $option_name);

        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'cdg-core'));
        }

        $direction = sanitize_text_field(wp_unslash($_POST['direction'] ?? ''));

        if ($option_name === '' || !in_array($direction, ['disable', 'enable'], true)) {
            $this->redirect(['cdg-autoload-error' => 'invalid']);
        }

        if ($direction === 'disable' && $this->is_protected($option_name)) {
            $this->redirect(['cdg-autoload-error' => 'protected']);
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $wpdb->options is a WP-owned identifier, not user input.
        $current_autoload = $wpdb->get_var(
            $wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option_name)
        );

        if (null === $current_autoload) {
            $this->redirect(['cdg-autoload-error' => 'missing']);
        }

        $success = $this->set_autoload($option_name, $direction === 'enable');

        if (!$success) {
            $this->redirect(['cdg-autoload-error' => 'failed']);
        }

        $this->log_change($option_name, (string) $current_autoload, $direction === 'enable' ? 'yes' : 'no');
        $this->redirect(['cdg-autoload-updated' => $direction]);
    }

    /**
     * @param array<string, string> $args Extra query args for the Tools page redirect.
     */
    private function redirect(array $args): void
    {
        $args['page'] = self::MENU_SLUG;
        wp_safe_redirect(add_query_arg($args, admin_url('tools.php')));
        exit();
    }

    // -------------------------------------------------------------------------
    // Autoload mutation
    // -------------------------------------------------------------------------

    /**
     * Flip the autoload column only — the option's stored value is never
     * touched. Prefers wp_set_option_autoload() (WP 6.6+); falls back to a
     * direct column update with manual cache-busting on older WP.
     */
    private function set_autoload(string $option_name, bool $autoload): bool
    {
        if (function_exists('wp_set_option_autoload')) {
            return wp_set_option_autoload($option_name, $autoload);
        }

        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->options,
            ['autoload' => $autoload ? 'yes' : 'no'],
            ['option_name' => $option_name]
        );

        if (false === $updated) {
            return false;
        }

        wp_cache_delete($option_name, 'options');
        wp_cache_delete('alloptions', 'options');

        return true;
    }

    /**
     * Prepend a change to the log (kept short, newest first).
     */
    private function log_change(string $option_name, string $from, string $to): void
    {
        $log = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) {
            $log = [];
        }

        array_unshift($log, [
            'option' => $option_name,
            'from'   => $from,
            'to'     => $to,
            'time'   => time(),
            'user'   => wp_get_current_user()->user_login,
        ]);

        update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_MAX), false);
    }

    // -------------------------------------------------------------------------
    // Protection rules
    // -------------------------------------------------------------------------

    private function is_protected(string $option_name): bool
    {
        if (in_array($option_name, self::PROTECTED_OPTIONS, true)) {
            return true;
        }

        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($option_name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function builder_hint(string $option_name): ?string
    {
        $lower = strtolower($option_name);
        foreach (self::BUILDER_HINTS as $needle => $label) {
            if (str_contains($lower, $needle)) {
                return $label;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Data
    // -------------------------------------------------------------------------

    /**
     * @return array{count:int, bytes:int}
     */
    private function get_totals(): array
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count(self::NOT_AUTOLOADED_VALUES), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $wpdb->options is a WP-owned identifier, not user input.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)), 0) AS total_bytes
                 FROM {$wpdb->options} WHERE autoload NOT IN ($placeholders)",
                self::NOT_AUTOLOADED_VALUES
            ),
            ARRAY_A
        );

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'bytes' => (int) ($row['total_bytes'] ?? 0),
        ];
    }

    /**
     * @return array<int, array{option_name:string, autoload:string, size_bytes:int}>
     */
    private function get_top_autoloaded(): array
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count(self::NOT_AUTOLOADED_VALUES), '%s'));
        $params       = array_merge(self::NOT_AUTOLOADED_VALUES, [self::DISPLAY_LIMIT]);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $wpdb->options is a WP-owned identifier, not user input.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, autoload, LENGTH(option_value) AS size_bytes
                 FROM {$wpdb->options}
                 WHERE autoload NOT IN ($placeholders)
                 ORDER BY size_bytes DESC
                 LIMIT %d",
                $params
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $totals = $this->get_totals();
        $rows   = $this->get_top_autoloaded();
        $log    = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) {
            $log = [];
        }

        $over_threshold = $totals['bytes'] > self::WARN_THRESHOLD_BYTES;

        $updated_direction = sanitize_text_field(wp_unslash($_GET['cdg-autoload-updated'] ?? ''));
        $error              = sanitize_text_field(wp_unslash($_GET['cdg-autoload-error'] ?? ''));

        ?>
        <style>
        .cdg-ao { font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, sans-serif; font-size: 14px; color: #09090b; max-width: 1100px; padding-top: 16px; }
        .cdg-ao * { box-sizing: border-box; }

        .cdg-ao-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #e4e4e7; }
        .cdg-ao-title { display: flex; align-items: center; gap: 10px; }
        .cdg-ao-title h1 { font-size: 20px; font-weight: 600; margin: 0; padding: 0; line-height: 1; color: #09090b; border: none; }

        .cdg-ao-summary { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
        .cdg-ao-pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 100px; font-size: 12.5px; font-weight: 500; border: 1px solid; }
        .cdg-ao-pill-pass { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
        .cdg-ao-pill-warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
        .cdg-ao-pill-neutral { background: #f4f4f5; border-color: #e4e4e7; color: #3f3f46; }

        .cdg-ao-notice { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; border: 1px solid; }
        .cdg-ao-notice-success { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
        .cdg-ao-notice-error { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }

        .cdg-ao-card { background: #fff; border: 1px solid #e4e4e7; border-radius: 10px; margin-bottom: 20px; overflow: hidden; }
        .cdg-ao-card-header { padding: 14px 16px; border-bottom: 1px solid #e4e4e7; background: #f4f4f5; font-size: 13px; font-weight: 600; color: #3f3f46; }

        .cdg-ao table { width: 100%; border-collapse: collapse; }
        .cdg-ao th { padding: 10px 16px; text-align: left; font-size: 11.5px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: #71717a; background: #fafafa; border-bottom: 1px solid #e4e4e7; }
        .cdg-ao td { padding: 12px 16px; border-bottom: 1px solid #f4f4f5; vertical-align: middle; font-size: 13px; }
        .cdg-ao tr:last-child td { border-bottom: none; }
        .cdg-ao tr:hover td { background: #fafafa; }
        .cdg-ao-name { font-family: "Courier New", Consolas, monospace; font-size: 12.5px; word-break: break-all; }
        .cdg-ao-size { white-space: nowrap; font-variant-numeric: tabular-nums; }

        .cdg-ao-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 100px; font-size: 11px; font-weight: 600; border: 1px solid; white-space: nowrap; }
        .cdg-ao-badge-protected { background: #f4f4f5; color: #52525b; border-color: #e4e4e7; }
        .cdg-ao-badge-builder { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; margin-left: 6px; }

        .cdg-ao-btn { display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 10px; border-radius: 6px; font-size: 12.5px; font-weight: 500; font-family: inherit; cursor: pointer; border: 1px solid #e4e4e7; background: #fff; color: #09090b; text-decoration: none; line-height: 1; }
        .cdg-ao-btn:hover { background: #f4f4f5; color: #09090b; }
        .cdg-ao-btn-danger { border-color: #fecaca; color: #b91c1c; }
        .cdg-ao-btn-danger:hover { background: #fef2f2; color: #b91c1c; }

        .cdg-ao-empty { padding: 24px 16px; text-align: center; color: #71717a; font-size: 13px; }
        .cdg-ao-footnote { margin-top: 16px; font-size: 12px; color: #71717a; font-style: italic; line-height: 1.6; }
        </style>

        <div class="wrap cdg-ao">

            <div class="cdg-ao-header">
                <div class="cdg-ao-title">
                    <svg height="20" width="20" viewBox="0 0 609.72 609.72" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="flex-shrink:0;display:block;"><path fill="#f34f27" d="M305.06,379.87c-2.37,0-4.37-1.71-4.94-4.01-1.84-7.32-5.32-15.1-10.49-23.34-6.12-9.9-14.84-19.08-26.18-27.54-9.85-7.45-19.71-12.53-29.55-15.24-2.35-.64-4.05-2.71-4.05-5.13s1.65-4.42,3.94-5.07c9.66-2.76,18.95-7.24,27.91-13.44,10.3-7.16,18.89-15.76,25.79-25.79,6.12-8.93,10.3-17.77,12.59-26.5.59-2.29,2.61-3.97,4.96-3.97s4.43,1.72,5.02,4.04c1.31,5.24,3.37,10.6,6.15,16.08,3.52,6.77,8.01,13.28,13.48,19.53,5.6,6.12,11.85,11.66,18.76,16.61,9.01,6.39,18.18,10.89,27.51,13.48,2.29.63,3.94,2.67,3.94,5.04s-1.7,4.46-4.03,5.1c-5.91,1.62-11.98,4.23-18.23,7.83-7.55,4.43-14.6,9.7-21.11,15.82-6.51,5.99-11.85,12.31-16.02,18.95-5.17,8.26-8.67,16.1-10.49,23.52-.57,2.3-2.57,4.02-4.94,4.02Z"/><path fill="#f34f27" d="M134.8,65.81l56.7,56.7c3.11,3.11,7.91,3.73,11.75,1.56,30.68-17.31,65.44-26.52,101.62-26.52,55.37,0,107.44,21.56,146.59,60.72,39.16,39.16,60.72,91.22,60.72,146.59,0,36.18-9.22,70.94-26.52,101.62-2.16,3.84-1.55,8.63,1.56,11.75l56.7,56.7c4.35,4.35,11.61,3.64,15.02-1.48,78.75-118.41,65.93-279.73-38.49-384.15C416.01-15.14,254.69-27.96,136.28,50.79c-5.13,3.41-5.83,10.67-1.48,15.02Z"/><path fill="#f34f27" d="M418.22,487.21c-3.11-3.11-7.91-3.73-11.75-1.56-30.68,17.31-65.44,26.52-101.62,26.52-55.37,0-107.44-21.56-146.59-60.72-39.16-39.16-60.72-91.22-60.72-146.59,0-36.18,9.22-70.94,26.52-101.62,2.16-3.84,1.55-8.63-1.56-11.75l-56.7-56.7c-4.35-4.35-11.61-3.64-15.02,1.48C-27.96,254.69-15.14,416.01,89.28,520.43c104.42,104.42,265.74,117.24,384.15,38.49,5.13-3.41,5.83-10.67,1.48-15.02l-56.7-56.7Z"/></svg>
                    <h1><?php esc_html_e('Autoloaded Options', 'cdg-core'); ?></h1>
                </div>
            </div>

            <?php if ($updated_direction === 'disable'): ?>
                <div class="cdg-ao-notice cdg-ao-notice-success">
                    <?php esc_html_e('Autoload disabled for that option. If something on the site breaks, use the Undo link in Recent Changes below.', 'cdg-core'); ?>
                </div>
            <?php elseif ($updated_direction === 'enable'): ?>
                <div class="cdg-ao-notice cdg-ao-notice-success">
                    <?php esc_html_e('Autoload re-enabled for that option.', 'cdg-core'); ?>
                </div>
            <?php elseif ($error === 'protected'): ?>
                <div class="cdg-ao-notice cdg-ao-notice-error">
                    <?php esc_html_e('That option is protected and cannot be disabled from this screen — WordPress core (or CDG Core) reads it on nearly every request.', 'cdg-core'); ?>
                </div>
            <?php elseif ($error === 'missing'): ?>
                <div class="cdg-ao-notice cdg-ao-notice-error">
                    <?php esc_html_e('That option no longer exists — nothing changed.', 'cdg-core'); ?>
                </div>
            <?php elseif ($error === 'failed'): ?>
                <div class="cdg-ao-notice cdg-ao-notice-error">
                    <?php esc_html_e('The update failed. Nothing was changed.', 'cdg-core'); ?>
                </div>
            <?php elseif ($error === 'invalid'): ?>
                <div class="cdg-ao-notice cdg-ao-notice-error">
                    <?php esc_html_e('Invalid request. Nothing was changed.', 'cdg-core'); ?>
                </div>
            <?php endif; ?>

            <div class="cdg-ao-summary">
                <span class="cdg-ao-pill cdg-ao-pill-neutral">
                    <?php echo esc_html(number_format_i18n($totals['count'])); ?> <?php esc_html_e('autoloaded options', 'cdg-core'); ?>
                </span>
                <span class="cdg-ao-pill <?php echo $over_threshold ? 'cdg-ao-pill-warn' : 'cdg-ao-pill-pass'; ?>">
                    <?php echo $over_threshold ? '&#9888;' : '&#10003;'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php echo esc_html(size_format($totals['bytes'], 1)); ?> <?php esc_html_e('total', 'cdg-core'); ?>
                </span>
                <span class="cdg-ao-pill cdg-ao-pill-neutral">
                    <?php
                    printf(
                        /* translators: %s: formatted size, e.g. 800 KB */
                        esc_html__('WordPress warns above %s', 'cdg-core'),
                        esc_html(size_format(self::WARN_THRESHOLD_BYTES))
                    );
                    ?>
                </span>
            </div>

            <?php if (!empty($log)): ?>
            <div class="cdg-ao-card">
                <div class="cdg-ao-card-header"><?php esc_html_e('Recent Changes (this screen only)', 'cdg-core'); ?></div>
                <table>
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Option', 'cdg-core'); ?></th>
                            <th><?php esc_html_e('Change', 'cdg-core'); ?></th>
                            <th><?php esc_html_e('When', 'cdg-core'); ?></th>
                            <th><?php esc_html_e('By', 'cdg-core'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($log, 0, 10) as $entry):
                            $name = (string) ($entry['option'] ?? '');
                            $to   = (string) ($entry['to'] ?? '');
                            $now_disabled = in_array($to, self::NOT_AUTOLOADED_VALUES, true);
                            $revert_direction = $now_disabled ? 'enable' : 'disable';
                        ?>
                        <tr>
                            <td class="cdg-ao-name"><?php echo esc_html($name); ?></td>
                            <td>
                                <?php echo $now_disabled
                                    ? esc_html__('Disabled', 'cdg-core')
                                    : esc_html__('Enabled', 'cdg-core'); ?>
                            </td>
                            <td><?php echo esc_html(human_time_diff((int) ($entry['time'] ?? time())) . ' ' . __('ago', 'cdg-core')); ?></td>
                            <td><?php echo esc_html((string) ($entry['user'] ?? '')); ?></td>
                            <td><?php echo $this->action_link($name, $revert_direction, __('Undo', 'cdg-core')); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="cdg-ao-card">
                <div class="cdg-ao-card-header">
                    <?php
                    printf(
                        /* translators: %d: number of rows shown */
                        esc_html__('Largest Autoloaded Options (top %d)', 'cdg-core'),
                        self::DISPLAY_LIMIT
                    );
                    ?>
                </div>
                <?php if (empty($rows)): ?>
                    <div class="cdg-ao-empty"><?php esc_html_e('No autoloaded options found.', 'cdg-core'); ?></div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Option Name', 'cdg-core'); ?></th>
                            <th><?php esc_html_e('Size', 'cdg-core'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row):
                            $name      = (string) $row['option_name'];
                            $size      = (int) $row['size_bytes'];
                            $protected = $this->is_protected($name);
                            $builder   = $this->builder_hint($name);
                        ?>
                        <tr>
                            <td>
                                <span class="cdg-ao-name"><?php echo esc_html($name); ?></span>
                                <?php if ($builder): ?>
                                    <span class="cdg-ao-badge cdg-ao-badge-builder" title="<?php esc_attr_e('Informational only — not blocked. Verify before disabling.', 'cdg-core'); ?>">
                                        <?php echo esc_html($builder); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="cdg-ao-size"><?php echo esc_html(size_format($size, 1)); ?></td>
                            <td>
                                <?php if ($protected): ?>
                                    <span class="cdg-ao-badge cdg-ao-badge-protected" title="<?php esc_attr_e('Read on nearly every request by WordPress core or CDG Core — disabling autoload here is blocked.', 'cdg-core'); ?>">
                                        <?php esc_html_e('Protected', 'cdg-core'); ?>
                                    </span>
                                <?php else: ?>
                                    <?php echo $this->action_link($name, 'disable', __('Disable Autoload', 'cdg-core'), true); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <p class="cdg-ao-footnote">
                <?php esc_html_e('Disabling autoload only stops an option from loading on every request — it does not delete or modify the stored value, and the option is still available normally via get_option(). If disabling one breaks something, use the Undo link above to restore it instantly.', 'cdg-core'); ?>
                <br>
                <?php esc_html_e('Options marked "Protected" are known to be read by WordPress core or CDG Core on nearly every page load and cannot be toggled from this screen.', 'cdg-core'); ?>
            </p>

        </div>
        <?php
    }

    /**
     * Render a small POST form + submit button styled as a link/button, used
     * for both the "Disable Autoload" table action and the "Undo" log action.
     */
    private function action_link(string $option_name, string $direction, string $label, bool $confirm = false): string
    {
        $confirm_msg = sprintf(
            /* translators: %s: option name */
            __('Disable autoload for "%s"? You can undo this from the Recent Changes list.', 'cdg-core'),
            $option_name
        );

        $onclick = $confirm
            ? " onclick=\"return confirm('" . esc_js($confirm_msg) . "');\""
            : '';

        $btn_class = $direction === 'disable' ? 'cdg-ao-btn cdg-ao-btn-danger' : 'cdg-ao-btn';

        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <?php wp_nonce_field(self::NONCE_ACTION . '_' . $option_name); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::POST_ACTION); ?>">
            <input type="hidden" name="option_name" value="<?php echo esc_attr($option_name); ?>">
            <input type="hidden" name="direction" value="<?php echo esc_attr($direction); ?>">
            <button type="submit" class="<?php echo esc_attr($btn_class); ?>"<?php echo $onclick; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
                <?php echo esc_html($label); ?>
            </button>
        </form>
        <?php
        return (string) ob_get_clean();
    }
}
