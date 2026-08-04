<?php
/**
 * Roles
 *
 * Opt-in (Roles tab, "Enable Custom Roles") registration of three CDG
 * client-site roles, plus an optional filter that hides native WordPress
 * roles from the role-assignment dropdown so future users are always put
 * into one of these three buckets.
 *
 * - Agency  (cdg_agency)         — CDG staff. Clone of Administrator.
 *                                   Always bypasses sidebar hide rules.
 *                                   Never manually assignable — instead,
 *                                   whichever account's email matches the
 *                                   configured "Agency Email" (Roles tab)
 *                                   is automatically switched to this role
 *                                   on login, registration, and profile
 *                                   edits, replacing whatever role it had.
 * - Manager (cdg_client_manager) — Administrator capabilities minus
 *                                   plugin/theme install-and-edit, user
 *                                   management, core updates, and the
 *                                   file editor.
 * - Staff   (cdg_client_staff)   — Clone of Editor. Content only.
 *
 * (Role slugs keep their original "client_manager" / "client_staff" values
 * for stability — only the display labels changed.)
 *
 * Both toggle-driven behaviors below are off by default. Native roles
 * (administrator, editor, author, contributor, subscriber) always remain
 * registered — WordPress core and other plugins assume they exist — the
 * "Hide Default WordPress Roles" setting only affects the Add User / Edit
 * User / Bulk Edit dropdowns, and only hides Editor/Author/Contributor/
 * Subscriber; Administrator always stays selectable there. Agency is never
 * selectable in those dropdowns regardless of that setting, since it's
 * only ever assigned automatically by email. Existing users keep whatever
 * role they already have; nothing here changes an existing account's role
 * automatically except the Agency email match. Turning "Enable Custom
 * Roles" back off after it's been on does not remove the roles — it just
 * stops the plugin from managing them further.
 *
 * @package CDG_Core
 * @since 1.1.0
 */

declare(strict_types=1);

class CDG_Core_Roles
{
    public const AGENCY         = 'cdg_agency';
    public const CLIENT_MANAGER = 'cdg_client_manager';
    public const CLIENT_STAFF   = 'cdg_client_staff';

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

        // Self-healing: (re)create any of the 3 roles if missing. Cheap —
        // get_role() reads the already-loaded WP_Roles object in memory.
        add_action('init', [$this, 'maybe_register_roles']);

        // Hide native roles from Add User / Edit User / Bulk Edit dropdowns.
        add_filter('editable_roles', [$this, 'hide_native_roles']);

        // Force the Agency role onto whichever account matches the
        // configured Agency Email — on login, right after account
        // creation, and again on any profile edit, so it self-heals if
        // someone reassigns the account elsewhere.
        add_action('wp_login', [$this, 'sync_agency_role_on_login'], 10, 2);
        add_action('user_register', [$this, 'sync_agency_role_on_register']);
        add_action('profile_update', [$this, 'sync_agency_role_on_update']);
    }

    /**
     * Register the 3 roles if the feature is enabled and any of them are
     * missing. No-op (and never removes anything) when the setting is off.
     */
    public function maybe_register_roles(): void
    {
        if (!$this->plugin->get_setting('enable_custom_roles')) {
            return;
        }

        if (
            get_role(self::AGENCY) &&
            get_role(self::CLIENT_MANAGER) &&
            get_role(self::CLIENT_STAFF)
        ) {
            return;
        }

        self::register_roles();
    }

    /**
     * (Re)create all 3 roles from the current live Administrator/Editor
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

        // Agency: exact clone of Administrator.
        remove_role(self::AGENCY);
        add_role(self::AGENCY, __('Agency', 'cdg-core'), $admin->capabilities);

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
     * Bulk Edit screens. Two independent things happen here:
     *
     * 1. Agency is always removed, unconditionally — it's only ever
     *    assigned automatically by email match (see sync_agency_role()),
     *    never picked from a dropdown.
     * 2. Editor/Author/Contributor/Subscriber (NATIVE_ROLES) are removed
     *    only when both "Enable Custom Roles" and "Hide Default WordPress
     *    Roles" are turned on. Administrator is never removed by this
     *    setting — it always stays selectable.
     *
     * The roles themselves stay registered either way; this only affects
     * what can be newly assigned.
     *
     * @param array<string, array<string, mixed>> $roles
     * @return array<string, array<string, mixed>>
     */
    public function hide_native_roles(array $roles): array
    {
        // Agency is never manually assignable, regardless of settings —
        // harmless no-op if the role isn't even registered.
        unset($roles[self::AGENCY]);

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
        $email = (string) $this->plugin->get_setting('agency_email');
        return is_email($email) ? $email : self::DEFAULT_AGENCY_EMAIL;
    }

    /**
     * Force the Agency role onto the given user if their email matches the
     * configured Agency Email, replacing whatever role(s) they currently
     * have — mirrors how Manager/Staff are single roles. No-op unless
     * "Enable Custom Roles" is on and the Agency role is actually
     * registered (both true once maybe_register_roles() has run on init).
     */
    private function sync_agency_role(int $user_id): void
    {
        if (!$this->plugin->get_setting('enable_custom_roles') || !get_role(self::AGENCY)) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user || !$user->exists()) {
            return;
        }

        if (strcasecmp(trim($user->user_email), trim($this->agency_email())) !== 0) {
            return;
        }

        if ($user->roles !== [self::AGENCY]) {
            $user->set_role(self::AGENCY);
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
     * not just the two roles this plugin creates. Agency is never
     * included — it always sees the full, unmodified sidebar.
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
     * controls: every currently registered role except Agency. Unlike
     * target_roles() (Manager/Staff only — the two roles this plugin
     * creates for content-only client users), this includes the native
     * WordPress roles (Administrator, Editor, Author, Contributor,
     * Subscriber), so a plugin can be hidden from a client even on sites
     * that never turn on "Enable Custom Roles" and just use default roles.
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
        $roles = wp_roles()->get_names();
        unset($roles[self::AGENCY]);

        return array_map('translate_user_role', $roles);
    }
}
