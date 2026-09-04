<?php
/**
 * Documentation Class
 *
 * Provides an internal documentation system for client sites.
 *
 * @package CDG_Core
 * @since 1.0.0
 */

declare(strict_types=1);

class CDG_Core_Documentation
{
    /**
     * Post type name
     */
    public const POST_TYPE = 'cdg_documentation';

    /**
     * Taxonomy name
     */
    public const TAXONOMY = 'cdg_doc_category';

    /**
     * Default categories
     */
    public const DEFAULT_CATEGORIES = [
        'getting-started' => 'Getting Started',
        'advanced' => 'Advanced',
        'troubleshooting' => 'Troubleshooting',
    ];

    /**
     * Default dashboard column for each documentation category's widget
     * (matched by term slug). 'normal' = column 1 (main), 'side' = column 2,
     * 'column3' = column 3. Any category not listed here falls back to
     * 'normal'. Note: a 3rd/4th column only renders for users who have set
     * "Number of Columns" to 3+ under Screen Options on the Dashboard page —
     * that's a per-user WordPress preference this plugin doesn't override.
     */
    public const CATEGORY_DASHBOARD_COLUMNS = [
        'getting-started' => 'normal',
        'advanced'        => 'side',
        'troubleshooting' => 'column3',
    ];

    /**
     * Plugin instance
     *
     * @var CDG_Core
     */
    private CDG_Core $plugin;

    /**
     * Constructor
     *
     * @param CDG_Core $plugin Plugin instance
     */
    public function __construct(CDG_Core $plugin)
    {
        $this->plugin = $plugin;
        $this->setup_hooks();
    }

    /**
     * Setup hooks
     *
     * @return void
     */
    private function setup_hooks(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_taxonomy']);
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widgets']);
        add_action('admin_menu', [$this, 'add_viewer_pages']);
    }

    /**
     * Register documentation post type
     *
     * @return void
     */
    public function register_post_type(): void
    {
        $labels = [
            'name' => _x('Documentation', 'Post Type General Name', 'cdg-core'),
            'singular_name' => _x('Documentation', 'Post Type Singular Name', 'cdg-core'),
            'menu_name' => __('Documentation', 'cdg-core'),
            'name_admin_bar' => __('Documentation', 'cdg-core'),
            'archives' => __('Documentation Archives', 'cdg-core'),
            'all_items' => __('All Documentation', 'cdg-core'),
            'add_new_item' => __('Add New Documentation', 'cdg-core'),
            'add_new' => __('Add New', 'cdg-core'),
            'new_item' => __('New Documentation', 'cdg-core'),
            'edit_item' => __('Edit Documentation', 'cdg-core'),
            'view_item' => __('View Documentation', 'cdg-core'),
            'search_items' => __('Search Documentation', 'cdg-core'),
        ];

        $args = [
            'label' => __('Documentation', 'cdg-core'),
            'labels' => $labels,
            'supports' => ['title', 'editor', 'excerpt', 'revisions'],
            'taxonomies' => [self::TAXONOMY],
            'hierarchical' => false,
            'public' => false,
            'show_ui' => true,
            // Nested under Tools rather than its own top-level menu item —
            // 'menu_position'/'menu_icon' are ignored once show_in_menu is a
            // parent slug, so they're intentionally omitted rather than left
            // in as dead config.
            'show_in_menu' => 'tools.php',
            'show_in_admin_bar' => true,
            'can_export' => true,
            'has_archive' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'capability_type' => 'post',
            'show_in_rest' => false,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Register documentation taxonomy
     *
     * @return void
     */
    public function register_taxonomy(): void
    {
        $labels = [
            'name' => _x('Doc Categories', 'Taxonomy General Name', 'cdg-core'),
            'singular_name' => _x('Doc Category', 'Taxonomy Singular Name', 'cdg-core'),
            'menu_name' => __('Categories', 'cdg-core'),
            'all_items' => __('All Categories', 'cdg-core'),
            'add_new_item' => __('Add New Category', 'cdg-core'),
            'edit_item' => __('Edit Category', 'cdg-core'),
            'search_items' => __('Search Categories', 'cdg-core'),
        ];

        $args = [
            'labels' => $labels,
            'hierarchical' => false,
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => false,
            // Without this, show_in_menu defaults to true, which nests the
            // Categories screen under the post type's OWN menu slug
            // (edit.php?post_type=cdg_documentation). That only renders when
            // a post type has its own top-level menu; since it's nested
            // under Tools (see 'show_in_menu' above in register_post_type),
            // that slug isn't a real top-level menu and the link is silently
            // dropped from the sidebar. Pointing straight at 'tools.php'
            // puts Categories as a sibling of Documentation under Tools.
            'show_in_menu' => 'tools.php',
        ];

        register_taxonomy(self::TAXONOMY, [self::POST_TYPE], $args);
    }

    /**
     * Create default categories
     *
     * @return void
     */
    public function create_default_categories(): void
    {
        // Only create if no categories exist
        $existing = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'number' => 1,
        ]);

        if (!is_wp_error($existing) && !empty($existing)) {
            return;
        }

        foreach (self::DEFAULT_CATEGORIES as $slug => $name) {
            if (!term_exists($slug, self::TAXONOMY)) {
                wp_insert_term($name, self::TAXONOMY, ['slug' => $slug]);
            }
        }
    }

    /**
     * Add dashboard widgets
     *
     * @return void
     */
    public function add_dashboard_widgets(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }

        if (!$this->plugin->get_setting('show_documentation_widgets')) {
            return;
        }

        $style = $this->plugin->get_setting('documentation_module_style');

        if ($style === 'minimal') {
            wp_add_dashboard_widget(
                'cdg_documentation_minimal',
                __('Quick Documentation', 'cdg-core'),
                [$this, 'render_minimal_widget']
            );
        } else {
            // Add one widget per category
            $categories = get_terms([
                'taxonomy' => self::TAXONOMY,
                'hide_empty' => false,
            ]);

            if (!is_wp_error($categories)) {
                foreach ($categories as $category) {
                    $context = self::CATEGORY_DASHBOARD_COLUMNS[$category->slug] ?? 'normal';

                    wp_add_dashboard_widget(
                        'cdg_doc_' . $category->slug,
                        sprintf(__('Docs: %s', 'cdg-core'), $category->name),
                        [$this, 'render_category_widget'],
                        null,
                        ['category' => $category],
                        $context
                    );
                }
            }
        }
    }

    /**
     * Render minimal dashboard widget
     *
     * @return void
     */
    public function render_minimal_widget(): void
    {
        $categories = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
        ]);

        if (is_wp_error($categories) || empty($categories)) {
            echo '<p>' . esc_html__('No documentation categories found.', 'cdg-core') . '</p>';
            return;
        }

        echo '<div class="cdg-doc-buttons" style="display: flex; flex-direction: column; gap: 8px;">';
        
        foreach ($categories as $category) {
            $url = admin_url('admin.php?page=cdg-doc-category&category=' . $category->slug);
            printf(
                '<a href="%s" class="button button-primary" style="text-align: center;">%s</a>',
                esc_url($url),
                esc_html($category->name)
            );
        }
        
        echo '</div>';
    }

    /**
     * Render category dashboard widget
     *
     * @param mixed $post Post object (unused)
     * @param array $args Widget arguments
     * @return void
     */
    public function render_category_widget($post, array $args): void
    {
        $category = $args['args']['category'] ?? null;
        
        if (!$category) {
            return;
        }

        $limit = $this->plugin->get_setting('documentation_widget_limit');

        $docs = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => $limit,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'tax_query' => [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field' => 'term_id',
                    'terms' => $category->term_id,
                ],
            ],
        ]);

        if (empty($docs)) {
            printf(
                '<p>%s</p>',
                esc_html__('No documentation in this category.', 'cdg-core')
            );
            return;
        }

        echo '<ul style="margin: 0;">';
        
        foreach ($docs as $doc) {
            $view_url = admin_url('admin.php?page=cdg-view-doc&post_id=' . $doc->ID);
            printf(
                '<li style="margin-bottom: 8px;"><a href="%s" class="button" style="width: 100%%; text-align: left;">%s</a></li>',
                esc_url($view_url),
                esc_html($doc->post_title)
            );
        }
        
        echo '</ul>';
    }

    /**
     * Add viewer pages
     *
     * @return void
     */
    public function add_viewer_pages(): void
    {
        // View documentation page. Parent must be 'tools.php' directly, not
        // 'edit.php?post_type=' . self::POST_TYPE — that slug was only a
        // valid top-level menu while the post type had its own top-level
        // menu (show_in_menu === true). Since it's nested under Tools now,
        // that slug is just another submenu entry, not a real menu key, so
        // a submenu pointed at it is silently dropped from the sidebar.
        add_submenu_page(
            'tools.php',
            __('View Documentation', 'cdg-core'),
            __('View', 'cdg-core'),
            'edit_posts',
            'cdg-view-doc',
            [$this, 'render_viewer']
        );

        // Hidden category archive page
        add_submenu_page(
            null,
            __('Documentation Category', 'cdg-core'),
            __('Category', 'cdg-core'),
            'edit_posts',
            'cdg-doc-category',
            [$this, 'render_category_archive']
        );
    }

    /**
     * Render documentation viewer
     *
     * @return void
     */
    public function render_viewer(): void
    {
        if (!isset($_GET['post_id'])) {
            echo '<div class="wrap"><p>' . esc_html__('No documentation specified.', 'cdg-core') . '</p></div>';
            return;
        }

        $post_id = absint($_GET['post_id']);
        $post = get_post($post_id);

        if (!$post || $post->post_type !== self::POST_TYPE) {
            echo '<div class="wrap"><p>' . esc_html__('Documentation not found.', 'cdg-core') . '</p></div>';
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($post->post_title); ?></h1>
            
            <p class="description">
                <?php echo esc_html(sprintf(__('Last updated: %s', 'cdg-core'), get_the_modified_date('', $post))); ?>
                
                <?php if (current_user_can('manage_options')): ?>
                    | <a href="<?php echo esc_url(admin_url('post.php?post=' . $post->ID . '&action=edit')); ?>">
                        <?php esc_html_e('Edit', 'cdg-core'); ?>
                    </a>
                <?php endif; ?>
            </p>
            
            <div class="card" style="max-width: 800px; padding: 20px;">
                <?php echo wp_kses_post(apply_filters('the_content', $post->post_content)); ?>
            </div>
            
            <p style="margin-top: 20px;">
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . self::POST_TYPE)); ?>" class="button">
                    ← <?php esc_html_e('Back to All Documentation', 'cdg-core'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render category archive
     *
     * @return void
     */
    public function render_category_archive(): void
    {
        if (!isset($_GET['category'])) {
            echo '<div class="wrap"><p>' . esc_html__('No category specified.', 'cdg-core') . '</p></div>';
            return;
        }

        $category_slug = sanitize_text_field(wp_unslash($_GET['category']));
        $category = get_term_by('slug', $category_slug, self::TAXONOMY);

        if (!$category) {
            echo '<div class="wrap"><p>' . esc_html__('Category not found.', 'cdg-core') . '</p></div>';
            return;
        }

        $docs = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'tax_query' => [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field' => 'term_id',
                    'terms' => $category->term_id,
                ],
            ],
        ]);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(sprintf(__('Documentation: %s', 'cdg-core'), $category->name)); ?></h1>
            
            <?php if (empty($docs)): ?>
                <p><?php esc_html_e('No documentation found in this category.', 'cdg-core'); ?></p>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
                    <?php foreach ($docs as $doc): ?>
                        <div class="card" style="padding: 15px;">
                            <h3 style="margin-top: 0;">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=cdg-view-doc&post_id=' . $doc->ID)); ?>">
                                    <?php echo esc_html($doc->post_title); ?>
                                </a>
                            </h3>
                            
                            <?php if ($doc->post_excerpt): ?>
                                <p><?php echo esc_html($doc->post_excerpt); ?></p>
                            <?php endif; ?>
                            
                            <a href="<?php echo esc_url(admin_url('admin.php?page=cdg-view-doc&post_id=' . $doc->ID)); ?>" class="button button-primary">
                                <?php esc_html_e('View', 'cdg-core'); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <p style="margin-top: 20px;">
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . self::POST_TYPE)); ?>" class="button">
                    ← <?php esc_html_e('All Documentation', 'cdg-core'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url()); ?>" class="button">
                    <?php esc_html_e('Dashboard', 'cdg-core'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
