<?php
/**
 * Roles
 *
 * Opt-in (Roles tab, "Enable Custom Roles") registration of two CDG
 * client-site roles, plus an optional filter that hides native WordPress
 * roles from the role-assignment dropdown so future users are always put
 * into one of these buckets.
 *
 * - Manager (cdg_client_manager) — Administrator capabilities minus
 *                                   plugin/theme install-and-edit, user
 *                                   management, core updates, and the
 *                                   file editor.
 * - Staff   (cdg_client_staff)   — Clone of Editor. Content only.
 *
 * (Role slugs keep their original "client_manager" / "client_staff" values
 * for stability — only the display labels changed.)
 *
 * CDG staff ("Agency") are handled separately, and deliberately are *not*
 * a cloned role: whichever account's email matches the configured "Agency
 * Email" (Roles tab) is automatically switched to WordPress's native
 * Administrator role — not a lookalike clone — replacing whatever role it
 * had. A cloned role (as Agency used to be, pre-1.3.2) can carry every one
 * of Administrator's WordPress *capabilities* and still be invisible to a
 * third-party plugin that gates its own admin UI on the literal
 * 'administrator' role slug rather than a capability — which is exactly
 * what broke the Gravity Forms menu for Agency users. Real Administrator
 * has no such gap. See is_agency_user() for how the rest of the plugin
 * (CDG_Core_Plugin_Visibility) still exempts this account from Core's own
 * sidebar/plugin-visibility hide rules despite it now being an ordinary
 * Administrator account.
 *
 * Both toggle-driven behaviors below are off by default. Native roles
 * (administrator, editor, author, contributor, subscriber) always remain
 * registered — WordPress core and other plugins assume they exist — the
 * "Hide Default WordPress Roles" setting only affects the Add User / Edit
 * User / Bulk Edit dropdowns, and only hides Editor/Author/Contributor/
 * Subscriber; Administrator always stays selectable there. Existing users
 * keep whatever role they already have; nothing here changes an existing
 * account's role automatically except the Agency email match. Turning
 * "Enable Custom Roles" back off after it's been on does not remove
 * Manager/Staff — it just stops the plugin from managing them further.
 *
 * @package CDG_Core
 * @since 1.1.0
 */

declare(strict_types=1);

class CDG_Core_Roles
{
    public const CLIENT_MANAGER = 'cdg_client_manager';
    public const CLIENT_STAFF   = 'cdg_client_staff';

    /**
     * Retired role slug from before 1.3.2, when Agency was a clone of
     * Administrator rather than the literal Administrator role. Kept here
     * only so migrate_legacy_agency_role() can find and clean up any sites
     * still carrying it.
     */
    private const LEGACY_AGENCY_ROLE = 'cdg_agency';

    /**
     * Capabilities stripped from Administrator's live capability set to
     * build Manager. Anything not listed here — including caps added
     * by third-party plugins (Gravity Forms, Wordfence, Yoast, etc.)
     * riding on top of Administrator — is kept.
     *
     * @var string[]
     */
    private const MANAGER_BLOCKLIST = [
        // Plugins
        'activate_plugins',
        'install_plugins',
        'update_plugins',
        'delete_plugins',
        'edit_plugins',
        // Themes
        'install_themes',
        'update_themes',
        'delete_themes',
        'edit_themes',
        'switch_themes',
        // Users
        'create_users',
        'add_users',
        'edit_users',
        'delete_users',
        'remove_users',
        'promote_users',
        'list_users',
        // Core / files
        'update_core',
        'edit_files',
        // Multisite (defensive; irrelevant on single-site installs)
        'manage_network',
        'manage_network_users',
        'manage_network_plugins',
        'manage_network_themes',
        'manage_network_options',
        'manage_sites',
        'upgrade_network',
        'setup_network',
        'create_sites',
        'delete_sites',
    ];

    /**
     * Native WordPress roles hidden from the role-assignment dropdown when
     * "Hide Default WordPress Roles" is on. Administrator is deliberately
     * excluded — it stays selectable regardless of that setting.
     */
    private const NATIVE_ROLES = [
        'editor',
        'author',
        'contributor',
        'subscriber',
    ];

    /** Default value for the "Agency Email" setting. */
    public const DEFAULT_AGENCY_EMAIL = 'support@crawforddesigngp.com';

    /** @var CDG_Core */
    private CDG_Core $plugin;

    public function __construct(CDG_Core $plugin)
    {
        $this->plugin = $plugin;

        // One-time cleanup for sites upgrading from pre-1.3.2: migrate any
        // users still on the retired cdg_agency clone role over to plain
        // Administrator, then drop the role registration. Runs before
        // maybe_register_roles() and is a no-op once the legacy role is
        // gone, so it's cheap to leave hooked in permanently.
        add_action('init', [$this, 'migrate_legacy_agency_role'], 5);

        // Self-healing: (re)create Manager/Staff if missing. Cheap —
        // get_role() reads the already-loaded WP_Roles object in memory.
        add_action('init', [$this, 'maybe_register_roles']);

        // Hide native roles from Add User / Edit User / Bulk Edit dropdowns.
        add_filter('editable_roles', [$this, 'hide_native_roles']);

        // Force Administrator onto whichever account matches the
        // configured Agency Email — on login, right after account
        // creation, and again on any profile edit, so it self-heals if
        // someone reassigns the account elsewhere.
        add_action('wp_login', [$this, 'sync_agency_role_on_login'], 10, 2);
        add_action('user_register', [$this, 'sync_agency_role_on_register']);
        add_action('profile_update', [$this, 'sync_agency_role_on_update']);
    }

    /**
     * Reassign any users still holding the retired cdg_agency role
     * (pre-1.3.2) to plain Administrator, then remove the role
     * registration entirely. No-ops immediately once that's done, since
     * get_role() will return null on every subsequent request.
     */
    public function migrate_legacy_agency_role(): void
    {
        if (!get_role(self::LEGACY_AGENCY_ROLE)) {
            return;
        }

        $legacy_users = get_users([
            'role'   => self::LEGACY_AGENCY_ROLE,
            'fields' => 'ID',
        ]);

        foreach ($legacy_users as $user_id) {
            $user = get_userdata((int) $user_id);
            if ($user) {
                $user->set_role('administrator');
            }
        }

        remove_role(self::LEGACY_AGENCY_ROLE);
    }

    /**
     * Register Manager/Staff if the feature is enabled and either is
     * missing. No-op (and never removes anything) when the setting is off.
     */
    public function maybe_register_roles(): void
    {
        if (!$this->plugin->get_setting('enable_custom_roles')) {
            return;
        }

        if (get_role(self::CLIENT_MANAGER) && get_role(self::CLIENT_STAFF)) {
            return;
        }

        self::register_roles();
    }

    /**
     * (Re)create Manager/Staff from the current live Administrator/Editor
     * capability sets. Safe to call more than once — add_role() after
     * remove_role() fully replaces the role's capability set each time.
     */
    public static function register_roles(): void
    {
        $admin  = get_role('administrator');
        $editor = get_role('editor');

        if (!$admin || !$editor) {
            // Unexpected on a normal WordPress install — bail rather than
            // create roles with no capabilities.
            return;
        }

        // Manager: Administrator minus the blocklist above.
        $manager_caps = $admin->capabilities;
        foreach (self::MANAGER_BLOCKLIST as $cap) {
            unset($manager_caps[$cap]);
        }
        remove_role(self::CLIENT_MANAGER);
        add_role(self::CLIENT_MANAGER, __('Manager', 'cdg-core'), $manager_caps);

        // Staff: exact clone of Editor.
        remove_role(self::CLIENT_STAFF);
        add_role(self::CLIENT_STAFF, __('Staff', 'cdg-core'), $editor->capabilities);
    }

    /**
     * Filter the editable-roles list used by the Add User, Edit User, and
     * Bulk Edit screens. Editor/Author/Contributor/Subscriber
     * (NATIVE_ROLES) are removed only when both "Enable Custom Roles" and
     * "Hide Default WordPress Roles" are turned on. Administrator is never
     * removed by this setting — it always stays selectable, which is also
     * what the Agency Email account gets assigned.
     *
     * The roles themselves stay registered either way; this only affects
     * what can be newly assigned.
     *
     * @param array<string, array<string, mixed>> $roles
     * @return array<string, array<string, mixed>>
     */
    public function hide_native_roles(array $roles): array
    {
        if (
            !$this->plugin->get_setting('enable_custom_roles') ||
            !$this->plugin->get_setting('hide_native_roles')
        ) {
            return $roles;
        }

        foreach (self::NATIVE_ROLES as $role) {
            unset($roles[$role]);
        }
        return $roles;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Agency auto-assignment by email
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The configured "Agency Email" (Roles tab), falling back to
     * DEFAULT_AGENCY_EMAIL if the setting is empty or invalid.
     */
    private function agency_email(): string
    {
        return self::resolve_agency_email($this->plugin);
    }

    private static function resolve_agency_email(CDG_Core $plugin): string
    {
        $email = (string) $plugin->get_setting('agency_email');
        return is_email($email) ? $email : self::DEFAULT_AGENCY_EMAIL;
    }

    /**
     * True if the given user's email matches the configured Agency Email
     * and "Enable Custom Roles" is on. Used by CDG_Core_Plugin_Visibility
     * to exempt the CDG staff account from Core's own sidebar/plugin
     * visibility hide rules — necessary now that Agency is a plain
     * Administrator account rather than a distinct role that could simply
     * be left out of those rules' target-role list.
     */
    public static function is_agency_user(WP_User $user, CDG_Core $plugin): bool
    {
        if (!$plugin->get_setting('enable_custom_roles')) {
            return false;
        }

        return strcasecmp(trim($user->user_email), trim(self::resolve_agency_email($plugin))) === 0;
    }

    /**
     * Force plain Administrator onto the given user if their email matches
     * the configured Agency Email, replacing whatever role(s) they
     * currently have. No-op unless "Enable Custom Roles" is on.
     */
    private function sync_agency_role(int $user_id): void
    {
        if (!$this->plugin->get_setting('enable_custom_roles')) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user || !$user->exists()) {
            return;
        }

        if (strcasecmp(trim($user->user_email), trim($this->agency_email())) !== 0) {
            return;
        }

        if ($user->roles !== ['administrator']) {
            $user->set_role('administrator');
        }
    }

    /**
     * @param string  $user_login Unused — required by the wp_login hook signature.
     * @param WP_User $user
     */
    public function sync_agency_role_on_login(string $user_login, WP_User $user): void
    {
        $this->sync_agency_role((int) $user->ID);
    }

    public function sync_agency_role_on_register(int $user_id): void
    {
        $this->sync_agency_role($user_id);
    }

    public function sync_agency_role_on_update(int $user_id): void
    {
        $this->sync_agency_role($user_id);
    }

    /**
     * Roles that can be targeted by the Sidebar tab's hide/rename controls
     * (Sidebar Menu Items and Custom Menu Links), in display order.
     * Administrator is included alongside Manager/Staff so a menu item or
     * link can be hidden from the site's native Administrator role too,
     * not just the two roles this plugin creates. The Agency Email account
     * always bypasses whatever is configured here regardless — see
     * is_agency_user() and CDG_Core_Plugin_Visibility.
     *
     * @return array<string, string> role slug => label
     */
    public static function target_roles(): array
    {
        return [
            'administrator'       => __('Administrator', 'cdg-core'),
            self::CLIENT_MANAGER  => __('Manager', 'cdg-core'),
            self::CLIENT_STAFF    => __('Staff', 'cdg-core'),
        ];
    }

    /**
     * Roles that can be targeted by the Sidebar tab's Plugin Visibility
     * controls: every currently registered role. Unlike target_roles()
     * (Manager/Staff only — the two roles this plugin creates for
     * content-only client users), this includes the native WordPress roles
     * (Administrator, Editor, Author, Contributor, Subscriber), so a
     * plugin can be hidden from a client even on sites that never turn on
     * "Enable Custom Roles" and just use default roles. As with
     * target_roles(), the Agency Email account always bypasses whatever is
     * configured against Administrator here — see is_agency_user().
     *
     * Reflects live registered roles — Manager/Staff only appear once
     * "Enable Custom Roles" has actually registered them; they drop back
     * out (along with any saved selections for them) if that's turned off
     * again, since the role no longer exists to match against.
     *
     * @return array<string, string> role slug => label
     */
    public static function hideable_roles(): array
    {
        return array_map('translate_user_role', wp_roles()->get_names());
    }
}
