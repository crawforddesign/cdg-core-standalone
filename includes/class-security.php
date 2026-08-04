<?php
/**
 * Security Class
 *
 * Handles security hardening that complements Wordfence.
 * Note: X-Frame-Options, HSTS, X-XSS-Protection, and X-Content-Type-Options
 * are handled by SpinupWP at the Nginx level.
 *
 * @package CDG_Core
 * @since 1.0.0
 */

declare(strict_types=1);

class CDG_Core_Security
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
        // Disable XML-RPC
        if ($this->plugin->get_setting('disable_xmlrpc')) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', '__return_empty_array');
        }
        
        // Block dangerous uploads
        if ($this->plugin->get_setting('block_dangerous_uploads')) {
            add_filter('upload_mimes', [$this, 'restrict_upload_mimes']);
        }
        
        // Remove X-Powered-By header
        if ($this->plugin->get_setting('remove_powered_by')) {
            add_filter('wp_headers', [$this, 'remove_powered_by_header']);
        }
        
        // Disable code editor for non-admins
        if ($this->plugin->get_setting('disable_code_editor')) {
            add_filter('wp_editor_settings', [$this, 'disable_code_editor'], 10, 2);
        }
    }

    /**
     * Restrict upload mime types
     *
     * @param mixed $mimes Allowed mime types
     * @return array
     */
    public function restrict_upload_mimes($mimes): array
    {
        if (!is_array($mimes)) {
            return [];
        }
        
        // Remove dangerous file types
        $dangerous = [
            'exe'   => false,
            'php'   => false,
            'phtml' => false,
            'php3'  => false,
            'php4'  => false,
            'php5'  => false,
            'php7'  => false,
            'phps'  => false,
            'pht'   => false,
            'js'    => false,
            'jsx'   => false,
            'swf'   => false,
            'flv'   => false,
            'sh'    => false,
            'bash'  => false,
            'bat'   => false,
            'cmd'   => false,
            'com'   => false,
            'cgi'   => false,
            'pl'    => false,
            'py'    => false,
            'asp'   => false,
            'aspx'  => false,
            'jsp'   => false,
            'htaccess' => false,
        ];
        
        foreach ($dangerous as $ext => $value) {
            unset($mimes[$ext]);
        }
        
        return $mimes;
    }

    /**
     * Remove X-Powered-By header
     *
     * @param mixed $headers Headers
     * @return array
     */
    public function remove_powered_by_header($headers): array
    {
        if (!is_array($headers)) {
            $headers = [];
        }
        
        unset($headers['X-Powered-By']);
        
        // Also try to remove via PHP
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
        
        return $headers;
    }

    /**
     * Disable code editor for non-admins
     *
     * @param mixed $settings Editor settings
     * @param string $editor_id Editor ID
     * @return array
     */
    public function disable_code_editor($settings, $editor_id): array
    {
        if (!is_array($settings)) {
            $settings = [];
        }
        
        if (!current_user_can('manage_options')) {
            $settings['codeEditingEnabled'] = false;
        }
        
        return $settings;
    }
}