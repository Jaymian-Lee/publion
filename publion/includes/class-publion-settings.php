<?php
if (!defined('ABSPATH')) exit;

class Publion_Settings {

    public function __construct() {
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
    }

    public function add_settings_link($links) {
        $links[] = '<a href="' . admin_url( 'admin.php?page=publion&publion_active_tab=publion-settings' ) . '">' . __( 'Instellingen', 'publion' ) . '</a>';
        return $links;
    }

    // Accessors for AJAX-based Post Creation Settings
    public static function get_creation_interval() {
        $settings = get_option('publion_post_settings', []);
        return isset($settings['time_frame_days']) ? absint($settings['time_frame_days']) : 7;
    }

    public static function get_post_status() {
        $settings = get_option('publion_post_settings', []);
        $valid = ['draft', 'publish'];
        return in_array($settings['post_status'] ?? 'draft', $valid) ? $settings['post_status'] : 'draft';
    }

    public static function should_add_cta() {
        $settings = get_option('publion_post_settings', []);
        return ($settings['cta_enabled'] ?? 'no') === 'yes';
    }

    public static function get_cta_text() {
        $settings = get_option('publion_post_settings', []);
        return sanitize_text_field($settings['cta_text'] ?? '');
    }

    public static function get_cta_url() {
        $settings = get_option('publion_post_settings', []);
        return esc_url($settings['cta_link'] ?? '');
    }
}
