<?php
/**
 * CPT Dashboard Class
 *
 * Adds quick-create dashboard widgets for custom post types.
 *
 * @package CDG_Core
 * @since 1.0.0
 */

declare(strict_types=1);

class CDG_Core_CPT_Dashboard
{
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
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widgets']);
    }

    /**
     * Add dashboard widgets
     *
     * @return void
     */
    public function add_dashboard_widgets(): void
    {
        $selected_cpts = $this->plugin->get_setting('selected_cpts');
        
        if (!is_array($selected_cpts) || empty($selected_cpts)) {
            return;
        }

        $style = $this->plugin->get_setting('cpt_module_style');

        if ($style === 'minimal') {
            wp_add_dashboard_widget(
                'cdg_cpt_quick_add',
                __('Quick Add', 'cdg-core'),
                [$this, 'render_minimal_widget']
            );
        } else {
            // One widget per CPT
            foreach ($selected_cpts as $post_type) {
                $pt_object = get_post_type_object($post_type);
                
                if (!$pt_object || !current_user_can($pt_object->cap->edit_posts)) {
                    continue;
                }

                wp_add_dashboard_widget(
                    'cdg_cpt_' . $post_type,
                    $pt_object->labels->name,
                    [$this, 'render_informative_widget'],
                    null,
                    ['post_type' => $post_type]
                );
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
        $selected_cpts = $this->plugin->get_setting('selected_cpts');
        
        if (!is_array($selected_cpts) || empty($selected_cpts)) {
            echo '<p>' . esc_html__('No post types selected.', 'cdg-core') . '</p>';
            return;
        }

        echo '<div class="cdg-cpt-buttons" style="display: flex; flex-direction: column; gap: 8px;">';
        
        foreach ($selected_cpts as $post_type) {
            $pt_object = get_post_type_object($post_type);
            
            if (!$pt_object || !current_user_can($pt_object->cap->edit_posts)) {
                continue;
            }

            $add_url = admin_url('post-new.php?post_type=' . $post_type);
            
            printf(
                '<a href="%s" class="button button-primary" style="text-align: center;"><span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span> Add %s</a>',
                esc_url($add_url),
                esc_html($pt_object->labels->singular_name)
            );
        }
        
        echo '</div>';
    }

    /**
     * Render informative dashboard widget
     *
     * @param mixed $post Post object (unused)
     * @param array $args Widget arguments
     * @return void
     */
    public function render_informative_widget($post, array $args): void
    {
        $post_type = $args['args']['post_type'] ?? null;
        
        if (!$post_type) {
            return;
        }

        $pt_object = get_post_type_object($post_type);
        
        if (!$pt_object) {
            return;
        }

        $stats = $this->get_post_type_stats($post_type);
        $add_url = admin_url('post-new.php?post_type=' . $post_type);
        $manage_url = admin_url('edit.php?post_type=' . $post_type);
        ?>
        <div class="cdg-cpt-widget">
            <!-- Main Action -->
            <div style="text-align: center; padding: 15px 0; border-bottom: 1px solid #eee;">
                <a href="<?php echo esc_url($add_url); ?>" class="button button-primary button-hero">
                    <span class="dashicons dashicons-plus-alt2" style="margin-top: 5px;"></span>
                    <?php echo esc_html(sprintf(__('Add New %s', 'cdg-core'), $pt_object->labels->singular_name)); ?>
                </a>
            </div>
            
            <!-- Stats -->
            <div style="display: flex; justify-content: center; gap: 30px; padding: 15px 0; border-bottom: 1px solid #eee;">
                <div style="text-align: center;">
                    <span style="display: block; font-size: 24px; font-weight: 600; color: #2271b1;">
                        <?php echo esc_html(number_format_i18n($stats['published'])); ?>
                    </span>
                    <span style="font-size: 11px; color: #666; text-transform: uppercase;">
                        <?php esc_html_e('Published', 'cdg-core'); ?>
                    </span>
                </div>
                
                <?php if ($stats['draft'] > 0): ?>
                <div style="text-align: center;">
                    <span style="display: block; font-size: 24px; font-weight: 600; color: #dba617;">
                        <?php echo esc_html(number_format_i18n($stats['draft'])); ?>
                    </span>
                    <span style="font-size: 11px; color: #666; text-transform: uppercase;">
                        <?php esc_html_e('Drafts', 'cdg-core'); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Recent Posts -->
            <?php if ($this->plugin->get_setting('show_recent_posts')): ?>
                <?php $this->render_recent_posts($post_type); ?>
            <?php endif; ?>
            
            <!-- Footer Actions -->
            <div style="padding: 12px; display: flex; gap: 8px; justify-content: center;">
                <a href="<?php echo esc_url($manage_url); ?>" class="button">
                    <?php esc_html_e('Manage All', 'cdg-core'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Render recent posts section
     *
     * @param string $post_type Post type
     * @return void
     */
    private function render_recent_posts(string $post_type): void
    {
        $limit = $this->plugin->get_setting('recent_posts_limit');
        
        $recent = get_posts([
            'post_type' => $post_type,
            'post_status' => ['publish', 'draft', 'pending'],
            'numberposts' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        if (empty($recent)) {
            return;
        }
        ?>
        <div style="padding: 12px; border-bottom: 1px solid #eee;">
            <h4 style="margin: 0 0 10px; font-size: 12px; color: #666; text-transform: uppercase;">
                <?php esc_html_e('Recent', 'cdg-core'); ?>
            </h4>
            <ul style="margin: 0; padding: 0; list-style: none;">
                <?php foreach ($recent as $item): ?>
                    <li style="display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: 12px;">
                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $item->ID . '&action=edit')); ?>" 
                           style="flex: 1; color: #2271b1; text-decoration: none;">
                            <?php echo esc_html($item->post_title); ?>
                        </a>
                        
                        <?php if ($item->post_status !== 'publish'): ?>
                            <span style="font-size: 10px; padding: 1px 6px; border-radius: 2px; background: #fff3cd; color: #856404;">
                                <?php echo esc_html(ucfirst($item->post_status)); ?>
                            </span>
                        <?php endif; ?>
                        
                        <span style="color: #666; white-space: nowrap;">
                            <?php echo esc_html(human_time_diff(strtotime($item->post_modified))); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Get post type statistics
     *
     * @param string $post_type Post type
     * @return array
     */
    public function get_post_type_stats(string $post_type): array
    {
        $counts = wp_count_posts($post_type);
        
        return [
            'published' => (int) ($counts->publish ?? 0),
            'draft' => (int) ($counts->draft ?? 0),
            'pending' => (int) ($counts->pending ?? 0),
            'private' => (int) ($counts->private ?? 0),
            'total' => (int) ($counts->publish ?? 0) + (int) ($counts->draft ?? 0) + (int) ($counts->pending ?? 0),
        ];
    }

    /**
     * Get available post types for admin
     *
     * @return array
     */
    public static function get_available_post_types(): array
    {
        $post_types = get_post_types([
            'public' => true,
            '_builtin' => false,
        ], 'objects');

        // Filter out documentation CPT if it exists
        if (class_exists('CDG_Core_Documentation')) {
            unset($post_types[CDG_Core_Documentation::POST_TYPE]);
        }

        // Add support for checking capabilities
        $available = [];
        foreach ($post_types as $pt) {
            if (current_user_can($pt->cap->edit_posts)) {
                $available[] = $pt;
            }
        }

        return $available;
    }
}
