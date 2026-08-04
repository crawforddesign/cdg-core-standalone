<?php
/**
 * Performance Class
 *
 * Handles performance optimizations including Gutenberg, queries, images, and DNS prefetch.
 *
 * @package CDG_Core
 * @since 1.0.0
 */

declare(strict_types=1);

class CDG_Core_Performance
{
    /**
     * Plugin instance
     *
     * @var CDG_Core
     */
    private CDG_Core $plugin;

    /**
     * WordPress default image sizes
     *
     * @var array
     */
    private array $wp_default_sizes = [
        'thumbnail',
        'medium',
        'medium_large',
        'large',
        '1536x1536',
        '2048x2048',
    ];

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
        // Gutenberg optimization
        $gutenberg_mode = $this->plugin->get_setting('gutenberg_mode');
        if ($gutenberg_mode === 'optimize') {
            add_action('wp_enqueue_scripts', [$this, 'optimize_gutenberg_assets'], 100);
        } elseif ($gutenberg_mode === 'disable') {
            add_filter('use_block_editor_for_post', '__return_false');
            add_filter('use_block_editor_for_post_type', '__return_false');
        }

        // Query optimizations
        if ($this->plugin->get_setting('optimize_search')) {
            add_action('pre_get_posts', [$this, 'optimize_search_queries']);
        }

        if ($this->plugin->get_setting('optimize_archives')) {
            add_action('pre_get_posts', [$this, 'optimize_archive_queries']);
        }

        // Lazy loading
        if ($this->plugin->get_setting('enable_lazy_loading')) {
            add_filter('wp_get_attachment_image_attributes', [$this, 'add_lazy_loading'], 10, 3);
        }

        // Image sizes
        add_filter('intermediate_image_sizes_advanced', [$this, 'filter_image_sizes']);

        // DNS prefetch
        if ($this->plugin->get_setting('remove_dns_prefetch')) {
            add_filter('wp_resource_hints', [$this, 'remove_dns_prefetch'], 10, 2);
        }

        // Post revisions
        add_filter('wp_revisions_to_keep', [$this, 'limit_post_revisions'], 10, 2);
    }

    /**
     * Limit post revisions based on settings
     *
     * @param mixed $num Current revision limit
     * @param WP_Post $post Post object
     * @return int
     */
    public function limit_post_revisions($num, $post): int
    {
        $mode = $this->plugin->get_setting('post_revisions_mode');

        switch ($mode) {
            case 'disabled':
                return 0;
            case 'limited':
                return (int) $this->plugin->get_setting('post_revisions_limit');
            case 'unlimited':
            default:
                return PHP_INT_MAX;
        }
    }

    /**
     * Optimize Gutenberg assets
     *
     * @return void
     */
    public function optimize_gutenberg_assets(): void
    {
        if (is_admin()) {
            return;
        }

        if (!$this->page_uses_blocks()) {
            wp_dequeue_style('wp-block-library');
            wp_dequeue_style('wp-block-library-theme');
            wp_dequeue_style('wc-blocks-style');
            wp_dequeue_style('global-styles');
            wp_dequeue_style('classic-theme-styles');
        }
    }

    /**
     * Check if current page uses blocks
     *
     * @return bool
     */
    private function page_uses_blocks(): bool
    {
        if (!is_singular()) {
            return false;
        }

        $post = get_post();
        if (!$post) {
            return false;
        }

        return has_blocks($post);
    }

    /**
     * Optimize search queries
     *
     * @param \WP_Query $query Query object
     * @return void
     */
    public function optimize_search_queries($query): void
    {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->is_search()) {
            $query->set('posts_per_page', 20);
        }
    }

    /**
     * Optimize archive queries
     *
     * Note: We intentionally do NOT disable update_post_meta_cache or
     * update_post_term_cache here. Disabling those caches only helps if
     * template code never accesses post meta or terms. Since most themes
     * display categories, tags, and custom fields on archives, disabling
     * the batch prefetch causes N+1 individual queries — worse than the
     * default behavior.
     *
     * @param \WP_Query $query Query object
     * @return void
     */
    public function optimize_archive_queries($query): void
    {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->is_category() || $query->is_tag()) {
            $query->set('posts_per_page', 10);
        }
    }

    /**
     * Add lazy loading to images
     *
     * @param mixed $attr Image attributes
     * @param \WP_Post $attachment Attachment post
     * @param string|array $size Image size
     * @return array
     */
    public function add_lazy_loading($attr, $attachment, $size): array
    {
        if (!is_array($attr)) {
            return [];
        }

        // Skip in admin
        if (is_admin()) {
            return $attr;
        }

        // Skip if already has loading attribute
        if (isset($attr['loading'])) {
            return $attr;
        }

        // Add native lazy loading
        $attr['loading'] = 'lazy';

        // Add aspect ratio for CLS
        if (!empty($attr['width']) && !empty($attr['height'])) {
            $width = (int) $attr['width'];
            $height = (int) $attr['height'];

            if ($width > 0 && $height > 0) {
                $aspect_ratio = sprintf('aspect-ratio: %d/%d;', $width, $height);
                $existing_style = isset($attr['style']) ? rtrim($attr['style'], '; ') . '; ' : '';
                $attr['style'] = $existing_style . $aspect_ratio;
            }
        }

        return $attr;
    }

    /**
     * Filter image sizes
     *
     * @param mixed $sizes Image sizes
     * @return array
     */
    public function filter_image_sizes($sizes): array
    {
        if (!is_array($sizes)) {
            return [];
        }

        $disabled = $this->plugin->get_setting('disabled_image_sizes');

        if (!is_array($disabled)) {
            $disabled = [];
        }

        // Always remove medium_large if setting is enabled
        if ($this->plugin->get_setting('remove_medium_large')) {
            $disabled[] = 'medium_large';
        }

        foreach ($disabled as $size) {
            unset($sizes[$size]);
        }

        return $sizes;
    }

    /**
     * Remove s.w.org DNS prefetch
     *
     * This is the single handler for s.w.org DNS prefetch removal.
     * The emoji disable function in CDG_Core_Cleanup defers to this
     * method to avoid duplicate filters.
     *
     * @param mixed $hints Resource hints
     * @param mixed $relation_type Relation type
     * @return array
     */
    public function remove_dns_prefetch($hints, $relation_type): array
    {
        if (!is_array($hints)) {
            return [];
        }

        if ('dns-prefetch' === $relation_type) {
            $hints = array_filter($hints, function ($hint) {
                if (is_array($hint)) {
                    $href = $hint['href'] ?? '';
                } else {
                    $href = (string) $hint;
                }
                return strpos($href, 's.w.org') === false;
            });
        }

        return array_values($hints);
    }

    /**
     * Get available image sizes for admin
     *
     * Static method that can be called without hooks being set up
     *
     * @return array
     */
    public static function get_available_image_sizes_static(): array
    {
        $wp_default_sizes = [
            'thumbnail',
            'medium',
            'medium_large',
            'large',
            '1536x1536',
            '2048x2048',
        ];

        $sizes = [];

        // WordPress defaults
        foreach ($wp_default_sizes as $size) {
            $sizes[$size] = [
                'name' => $size,
                'type' => 'wordpress',
                'width' => (int) get_option("{$size}_size_w", 0),
                'height' => (int) get_option("{$size}_size_h", 0),
            ];
        }

        // Get registered sizes
        $registered = wp_get_registered_image_subsizes();

        foreach ($registered as $name => $data) {
            if (!isset($sizes[$name])) {
                $sizes[$name] = [
                    'name' => $name,
                    'type' => 'plugin',
                    'width' => $data['width'],
                    'height' => $data['height'],
                ];
            }
        }

        return $sizes;
    }

    /**
     * Get available image sizes for admin (instance method wrapper)
     *
     * @return array
     */
    public function get_available_image_sizes(): array
    {
        return self::get_available_image_sizes_static();
    }
}