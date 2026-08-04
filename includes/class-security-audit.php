<?php
/**
 * Security Audit
 *
 * Registers a Tools -> Security Audit page that runs a series of
 * server-side security checks. Results are cached in a transient for
 * one hour and can be force-refreshed via a Re-run Audit button.
 *
 * Checks included:
 *   1. WP Generator meta tag exposure
 *   2. WP_DEBUG enabled on production
 *   3. debug.log public exposure
 *   4. User enumeration via REST API
 *   5. PHP execution in uploads folder
 *   6. Inactive administrator accounts (90-day threshold)
 *
 * @package CDG_Core
 * @since 1.6.5
 */

declare(strict_types=1);

class CDG_Core_Security_Audit
{
    private const TRANSIENT_KEY  = 'cdg_security_audit_results';
    private const NONCE_ACTION   = 'cdg_security_rerun';
    private const POST_ACTION    = 'cdg_security_rerun';
    private const LOGIN_META_KEY = 'cdg_last_login';
    private const INACTIVE_DAYS  = 90;

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
        add_action('wp_login',   [$this, 'record_last_login'], 10, 2);
        add_action('admin_post_' . self::POST_ACTION, [$this, 'handle_rerun']);
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Register the Tools -> Security Audit submenu page.
     */
    public function register_menu(): void
    {
        add_management_page(
            __('Security Audit', 'cdg-core'),
            __('Security Audit', 'cdg-core'),
            'manage_options',
            'cdg-security-audit',
            [$this, 'render_page']
        );
    }

    /**
     * Record the current time as this user's last login timestamp.
     *
     * @param string  $user_login
     * @param WP_User $user
     */
    public function record_last_login(string $user_login, WP_User $user): void
    {
        update_user_meta($user->ID, self::LOGIN_META_KEY, current_time('mysql'));
    }

    /**
     * Handle the Re-run form POST: clear the transient and redirect back.
     */
    public function handle_rerun(): void
    {
        check_admin_referer(self::NONCE_ACTION);

        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'cdg-core'));
        }

        delete_transient(self::TRANSIENT_KEY);

        wp_safe_redirect(admin_url('tools.php?page=cdg-security-audit'));
        exit();
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    /**
     * Render the Security Audit admin page.
     */
    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Serve from transient or run fresh checks.
        $cached = get_transient(self::TRANSIENT_KEY);

        if (false === $cached) {
            $checks    = $this->run_checks();
            $cached_at = time();
            set_transient(
                self::TRANSIENT_KEY,
                ['results' => $checks, 'time' => $cached_at],
                HOUR_IN_SECONDS
            );
        } else {
            $checks    = $cached['results'];
            $cached_at = $cached['time'];
        }

        // Only show Recommended Action column when at least one check has a fix.
        $has_fix_col = !empty(array_filter($checks, fn($c) => !empty($c['fix'])));

        // Summary counts.
        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($checks as $check) {
            $s = $check['status'] ?? 'warn';
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }

        $time_label = sprintf(
            /* translators: %s: formatted time */
            __('Results cached at %s', 'cdg-core'),
            wp_date(get_option('time_format'), $cached_at)
        );

        ?>
        <style>
        /* ---- Security Audit page styles (cdg-sa namespace) ---- */
        .cdg-sa { font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, sans-serif; font-size: 14px; color: #09090b; max-width: 1100px; padding-top: 16px; }
        .cdg-sa * { box-sizing: border-box; }

        /* Header */
        .cdg-sa-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #e4e4e7; }
        .cdg-sa-title { display: flex; align-items: center; gap: 10px; }
        .cdg-sa-title h1 { font-size: 20px; font-weight: 600; margin: 0; padding: 0; line-height: 1; color: #09090b; border: none; }
        .cdg-sa-meta { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .cdg-sa-cache-time { font-size: 12.5px; color: #71717a; }

        /* Re-run button */
        .cdg-sa-rerun-btn { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px; border-radius: 6px; font-size: 13px; font-weight: 500; font-family: inherit; cursor: pointer; border: 1px solid #e4e4e7; background: #fff; color: #09090b; transition: background 0.1s; line-height: 1; }
        .cdg-sa-rerun-btn:hover { background: #f4f4f5; }

        /* Summary pills */
        .cdg-sa-summary { display: flex; gap: 10px; margin-bottom: 24px; flex-wrap: wrap; }
        .cdg-sa-pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 100px; font-size: 12.5px; font-weight: 500; border: 1px solid; }
        .cdg-sa-pill-pass { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
        .cdg-sa-pill-warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
        .cdg-sa-pill-fail { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }

        /* Table */
        .cdg-sa table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e4e4e7; border-radius: 10px; overflow: hidden; }
        .cdg-sa th { padding: 10px 16px; text-align: left; font-size: 11.5px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: #71717a; background: #f4f4f5; border-bottom: 1px solid #e4e4e7; }
        .cdg-sa td { padding: 14px 16px; border-bottom: 1px solid #f4f4f5; vertical-align: top; font-size: 13.5px; }
        .cdg-sa tr:last-child td { border-bottom: none; }
        .cdg-sa tr:hover td { background: #fafafa; }
        .cdg-sa td:first-child { font-weight: 500; color: #09090b; white-space: nowrap; }
        .cdg-sa td:nth-child(2) { white-space: nowrap; }

        /* Detail + fix cells */
        .cdg-sa-detail { color: #3f3f46; line-height: 1.5; }
        .cdg-sa-fix { font-size: 12.5px; color: #3f3f46; line-height: 1.5; }
        .cdg-sa-detail code,
        .cdg-sa-fix code { font-size: 11.5px; background: #f4f4f5; padding: 1px 5px; border-radius: 3px; font-family: "Courier New", Consolas, monospace; color: #09090b; border: 1px solid #e4e4e7; }

        /* Status badges */
        .cdg-sa-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 100px; font-size: 12px; font-weight: 600; white-space: nowrap; border: 1px solid; }
        .cdg-sa-badge-pass { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
        .cdg-sa-badge-warn { background: #fffbeb; color: #92400e; border-color: #fde68a; }
        .cdg-sa-badge-fail { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }

        /* Footnote */
        .cdg-sa-footnote { margin-top: 16px; font-size: 12px; color: #71717a; font-style: italic; }
        </style>

        <div class="wrap cdg-sa">

            <div class="cdg-sa-header">
                <div class="cdg-sa-title">
                    <svg height="20" width="20" viewBox="0 0 609.72 609.72" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="flex-shrink:0;display:block;"><path fill="#f34f27" d="M305.06,379.87c-2.37,0-4.37-1.71-4.94-4.01-1.84-7.32-5.32-15.1-10.49-23.34-6.12-9.9-14.84-19.08-26.18-27.54-9.85-7.45-19.71-12.53-29.55-15.24-2.35-.64-4.05-2.71-4.05-5.13s1.65-4.42,3.94-5.07c9.66-2.76,18.95-7.24,27.91-13.44,10.3-7.16,18.89-15.76,25.79-25.79,6.12-8.93,10.3-17.77,12.59-26.5.59-2.29,2.61-3.97,4.96-3.97s4.43,1.72,5.02,4.04c1.31,5.24,3.37,10.6,6.15,16.08,3.52,6.77,8.01,13.28,13.48,19.53,5.6,6.12,11.85,11.66,18.76,16.61,9.01,6.39,18.18,10.89,27.51,13.48,2.29.63,3.94,2.67,3.94,5.04s-1.7,4.46-4.03,5.1c-5.91,1.62-11.98,4.23-18.23,7.83-7.55,4.43-14.6,9.7-21.11,15.82-6.51,5.99-11.85,12.31-16.02,18.95-5.17,8.26-8.67,16.1-10.49,23.52-.57,2.3-2.57,4.02-4.94,4.02Z"/><path fill="#f34f27" d="M134.8,65.81l56.7,56.7c3.11,3.11,7.91,3.73,11.75,1.56,30.68-17.31,65.44-26.52,101.62-26.52,55.37,0,107.44,21.56,146.59,60.72,39.16,39.16,60.72,91.22,60.72,146.59,0,36.18-9.22,70.94-26.52,101.62-2.16,3.84-1.55,8.63,1.56,11.75l56.7,56.7c4.35,4.35,11.61,3.64,15.02-1.48,78.75-118.41,65.93-279.73-38.49-384.15C416.01-15.14,254.69-27.96,136.28,50.79c-5.13,3.41-5.83,10.67-1.48,15.02Z"/><path fill="#f34f27" d="M418.22,487.21c-3.11-3.11-7.91-3.73-11.75-1.56-30.68,17.31-65.44,26.52-101.62,26.52-55.37,0-107.44-21.56-146.59-60.72-39.16-39.16-60.72-91.22-60.72-146.59,0-36.18,9.22-70.94,26.52-101.62,2.16-3.84,1.55-8.63-1.56-11.75l-56.7-56.7c-4.35-4.35-11.61-3.64-15.02,1.48C-27.96,254.69-15.14,416.01,89.28,520.43c104.42,104.42,265.74,117.24,384.15,38.49,5.13-3.41,5.83-10.67,1.48-15.02l-56.7-56.7Z"/></svg>
                    <h1><?php esc_html_e('Security Audit', 'cdg-core'); ?></h1>
                </div>

                <div class="cdg-sa-meta">
                    <span class="cdg-sa-cache-time"><?php echo esc_html($time_label); ?></span>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                        <?php wp_nonce_field(self::NONCE_ACTION); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::POST_ACTION); ?>">
                        <button type="submit" class="cdg-sa-rerun-btn">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                            <?php esc_html_e('Re-run Audit', 'cdg-core'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <div class="cdg-sa-summary">
                <span class="cdg-sa-pill cdg-sa-pill-pass">&#10003; <?php echo esc_html($counts['pass']); ?> <?php esc_html_e('Passed', 'cdg-core'); ?></span>
                <?php if ($counts['warn'] > 0): ?>
                <span class="cdg-sa-pill cdg-sa-pill-warn">&#9888; <?php echo esc_html($counts['warn']); ?> <?php esc_html_e('Warning', 'cdg-core'); ?></span>
                <?php endif; ?>
                <?php if ($counts['fail'] > 0): ?>
                <span class="cdg-sa-pill cdg-sa-pill-fail">&#10005; <?php echo esc_html($counts['fail']); ?> <?php esc_html_e('Failed', 'cdg-core'); ?></span>
                <?php endif; ?>
            </div>

            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Check', 'cdg-core'); ?></th>
                        <th><?php esc_html_e('Status', 'cdg-core'); ?></th>
                        <th><?php esc_html_e('Details', 'cdg-core'); ?></th>
                        <?php if ($has_fix_col): ?>
                        <th><?php esc_html_e('Recommended Action', 'cdg-core'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $check):
                        $status    = $check['status'] ?? 'warn';
                        $badge_cls = 'cdg-sa-badge cdg-sa-badge-' . esc_attr($status);
                        if ($status === 'pass') {
                            $badge_icon = '&#10003;';
                            $badge_text = __('Pass', 'cdg-core');
                        } elseif ($status === 'fail') {
                            $badge_icon = '&#10005;';
                            $badge_text = __('Fail', 'cdg-core');
                        } else {
                            $badge_icon = '&#9888;';
                            $badge_text = __('Warn', 'cdg-core');
                        }
                    ?>
                    <tr>
                        <td><?php echo esc_html($check['label']); ?></td>
                        <td><span class="<?php echo esc_attr($badge_cls); ?>"><?php echo $badge_icon; // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html($badge_text); ?></span></td>
                        <td class="cdg-sa-detail"><?php echo wp_kses_post($check['detail']); ?></td>
                        <?php if ($has_fix_col): ?>
                        <td class="cdg-sa-fix">
                            <?php if (!empty($check['fix'])): ?>
                                <?php echo wp_kses_post($check['fix']); ?>
                            <?php else: ?>
                                <span style="color:#a1a1aa;">&#8212;</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="cdg-sa-footnote">
                <?php esc_html_e('HTTP checks are made server-side and may not reflect external firewall or CDN rules.', 'cdg-core'); ?>
            </p>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Check runner
    // -------------------------------------------------------------------------

    /**
     * Run all checks and return the results array.
     *
     * @return array<int, array{label: string, status: string, detail: string, fix: string|null}>
     */
    private function run_checks(): array
    {
        return [
            $this->check_wp_generator(),
            $this->check_wp_debug(),
            $this->check_debug_log(),
            $this->check_user_enumeration(),
            $this->check_uploads_php_execution(),
            $this->check_inactive_admins(),
        ];
    }

    // -------------------------------------------------------------------------
    // Individual checks
    // -------------------------------------------------------------------------

    /**
     * Check 1 — WP Generator meta tag.
     *
     * If wp_generator is hooked to wp_head the WordPress version is broadcast
     * in every page's <head>, making version-targeted attacks easier.
     */
    private function check_wp_generator(): array
    {
        if (!has_action('wp_head', 'wp_generator')) {
            return [
                'label'  => 'WP Generator Meta Tag',
                'status' => 'pass',
                'detail' => 'The WordPress version is not exposed in the page &lt;head&gt;.',
                'fix'    => null,
            ];
        }

        return [
            'label'  => 'WP Generator Meta Tag',
            'status' => 'fail',
            'detail' => 'The WordPress version number is being output in every page\'s &lt;head&gt; via the <code>wp_generator</code> hook. This makes version-targeted vulnerability scanning trivial.',
            'fix'    => '<code>remove_action( \'wp_head\', \'wp_generator\' );</code> — add to CDG Core cleanup or a site-specific functionality plugin.',
        ];
    }

    /**
     * Check 2 — WP_DEBUG on production.
     *
     * A true WP_DEBUG exposes PHP errors and stack traces. WP_DEBUG_DISPLAY
     * additionally renders them on-screen for all visitors.
     */
    private function check_wp_debug(): array
    {
        $debug_on   = defined('WP_DEBUG') && WP_DEBUG === true;
        $display_on = defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY === true;

        if (!$debug_on) {
            return [
                'label'  => 'WP_DEBUG on Production',
                'status' => 'pass',
                'detail' => '<code>WP_DEBUG</code> is disabled.',
                'fix'    => null,
            ];
        }

        $detail = '<code>WP_DEBUG</code> is <code>true</code>. PHP errors, warnings, and stack traces may be exposed to visitors.';
        if ($display_on) {
            $detail .= ' <code>WP_DEBUG_DISPLAY</code> is also <code>true</code> — errors are being rendered on-screen.';
        }

        return [
            'label'  => 'WP_DEBUG on Production',
            'status' => 'fail',
            'detail' => $detail,
            'fix'    => 'Set <code>define( \'WP_DEBUG\', false );</code> in <code>wp-config.php</code>. If error logging is needed, keep <code>WP_DEBUG</code> true but set <code>WP_DEBUG_LOG</code> to a path outside the webroot and set <code>WP_DEBUG_DISPLAY</code> to <code>false</code>.',
        ];
    }

    /**
     * Check 3 — debug.log public exposure.
     *
     * An accessible debug.log can leak file paths, database credentials, and
     * other sensitive details recorded during PHP errors.
     */
    private function check_debug_log(): array
    {
        $log_url  = content_url('debug.log');
        $response = wp_remote_get($log_url, ['sslverify' => false, 'timeout' => 5]);
        $code     = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);

        if ($code === 200) {
            return [
                'label'  => 'debug.log Public Exposure',
                'status' => 'fail',
                'detail' => '<code>debug.log</code> returned HTTP 200 and is publicly accessible. It may contain file paths, database credentials, or other sensitive details logged during PHP errors.',
                'fix'    => 'Block via Nginx: <code>location ~* /wp-content/debug\\.log { deny all; }</code> — or move the log path outside the webroot with <code>define( \'WP_DEBUG_LOG\', \'/path/outside/webroot/debug.log\' );</code>. On SpinupWP this can be added as a custom Nginx rule.',
            ];
        }

        $code_display = $code > 0 ? (string) $code : 'no response';

        return [
            'label'  => 'debug.log Public Exposure',
            'status' => 'pass',
            'detail' => '<code>debug.log</code> is not publicly accessible (HTTP ' . esc_html($code_display) . ').',
            'fix'    => null,
        ];
    }

    /**
     * Check 4 — User enumeration via REST API.
     *
     * The /wp/v2/users endpoint exposes login slugs and display names by
     * default unless restricted, enabling targeted credential attacks.
     */
    private function check_user_enumeration(): array
    {
        $users_url = home_url('/wp-json/wp/v2/users');
        $response  = wp_remote_get($users_url, ['sslverify' => false, 'timeout' => 5]);

        if (is_wp_error($response)) {
            return [
                'label'  => 'User Enumeration via REST API',
                'status' => 'warn',
                'detail' => 'Could not reach the REST users endpoint: ' . esc_html($response->get_error_message()),
                'fix'    => null,
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (in_array($code, [401, 403], true)) {
            return [
                'label'  => 'User Enumeration via REST API',
                'status' => 'pass',
                'detail' => 'The <code>/wp-json/wp/v2/users</code> endpoint requires authentication (HTTP ' . esc_html((string) $code) . ').',
                'fix'    => null,
            ];
        }

        $users = json_decode($body, true);

        if (!is_array($users) || empty($users)) {
            return [
                'label'  => 'User Enumeration via REST API',
                'status' => 'pass',
                'detail' => 'The <code>/wp-json/wp/v2/users</code> endpoint returned no user data.',
                'fix'    => null,
            ];
        }

        // Confirm response contains identifiable user fields.
        $exposed = false;
        foreach ($users as $user) {
            if (is_array($user) && (!empty($user['slug']) || !empty($user['name']))) {
                $exposed = true;
                break;
            }
        }

        if (!$exposed) {
            return [
                'label'  => 'User Enumeration via REST API',
                'status' => 'pass',
                'detail' => 'The users endpoint returned data but no identifiable user fields were detected.',
                'fix'    => null,
            ];
        }

        $count = count($users);

        return [
            'label'  => 'User Enumeration via REST API',
            'status' => 'fail',
            'detail' => 'The <code>/wp-json/wp/v2/users</code> endpoint is publicly accessible and returned ' . esc_html((string) $count) . ' user record(s) including login slugs and display names. Attackers can use this for targeted brute-force or credential-stuffing attacks.',
            'fix'    => 'Require authentication on the endpoint by adding a <code>rest_authentication_errors</code> filter, or use Wordfence / a security plugin to restrict REST user enumeration.',
        ];
    }

    /**
     * Check 5 — PHP execution in uploads folder.
     *
     * Writes a benign PHP test file, attempts to fetch it via HTTP, then
     * deletes it immediately regardless of result.
     */
    private function check_uploads_php_execution(): array
    {
        $upload_dir = wp_upload_dir();
        $test_name  = 'cdg-sec-test-' . wp_generate_password(8, false) . '.php';
        $test_file  = $upload_dir['basedir'] . '/' . $test_name;
        $test_url   = $upload_dir['baseurl'] . '/' . $test_name;

        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            $written = @file_put_contents($test_file, '<?php echo "CDG_SEC_TEST"; ?>');

            if ($written === false) {
                return [
                    'label'  => 'PHP Execution in Uploads Folder',
                    'status' => 'warn',
                    'detail' => 'Could not write a temporary test file to the uploads directory. The check could not be performed — verify directory permissions manually.',
                    'fix'    => null,
                ];
            }

            $response = wp_remote_get($test_url, ['sslverify' => false, 'timeout' => 5]);
            @unlink($test_file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);

            if ($code === 200 && strpos($body, 'CDG_SEC_TEST') !== false) {
                return [
                    'label'  => 'PHP Execution in Uploads Folder',
                    'status' => 'fail',
                    'detail' => 'A PHP file written to the uploads directory was executed via HTTP. An attacker who can upload a malicious <code>.php</code> file (e.g., through a vulnerable plugin) could achieve remote code execution.',
                    'fix'    => 'Add an Nginx rule to block PHP execution in uploads: <code>location ~* /wp-content/uploads/.*\\.php$ { deny all; }</code>. On SpinupWP this can be added as a custom Nginx configuration rule.',
                ];
            }

            $code_display = $code > 0 ? (string) $code : 'blocked';

            return [
                'label'  => 'PHP Execution in Uploads Folder',
                'status' => 'pass',
                'detail' => 'PHP files in the uploads directory are not executable (HTTP ' . esc_html($code_display) . ').',
                'fix'    => null,
            ];

        } catch (\Throwable $e) {
            @unlink($test_file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return [
                'label'  => 'PHP Execution in Uploads Folder',
                'status' => 'warn',
                'detail' => 'An unexpected error occurred during the uploads PHP execution test: ' . esc_html($e->getMessage()),
                'fix'    => null,
            ];
        }
    }

    /**
     * Check 6 — Inactive administrator accounts.
     *
     * Queries all admins and flags any whose last recorded login (stored in
     * cdg_last_login user meta via the wp_login hook) is older than 90 days,
     * or who have no recorded login at all.
     */
    private function check_inactive_admins(): array
    {
        $admins   = get_users(['role' => 'administrator']);
        $cutoff   = strtotime('-' . self::INACTIVE_DAYS . ' days');
        $inactive = [];

        foreach ($admins as $admin) {
            $last = get_user_meta($admin->ID, self::LOGIN_META_KEY, true);
            if (!$last || strtotime((string) $last) < $cutoff) {
                $inactive[] = esc_html($admin->user_login);
            }
        }

        if (empty($inactive)) {
            return [
                'label'  => 'Inactive Administrator Accounts',
                'status' => 'pass',
                'detail' => 'All administrator accounts have logged in within the last ' . esc_html((string) self::INACTIVE_DAYS) . ' days.',
                'fix'    => null,
            ];
        }

        $list = implode(', ', $inactive);

        return [
            'label'  => 'Inactive Administrator Accounts',
            'status' => 'warn',
            'detail' => 'The following administrator account(s) have not logged in within the last ' . esc_html((string) self::INACTIVE_DAYS) . ' days, or have no recorded login: <strong>' . $list . '</strong>. Accounts with no <code>cdg_last_login</code> record are flagged as unverified, not definitively inactive.',
            'fix'    => 'Review each account. Disable or delete unused administrators, or downgrade to a lower role if full access is no longer needed.',
        ];
    }
}
