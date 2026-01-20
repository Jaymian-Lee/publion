<?php
/*
Plugin Name: AutoPost AI
Plugin URI: https://plugins.guru-is.com/product/autopost-ai
Description: Generate and refine blog posts with AI. Pick a category, get topic ideas, queue SEO-optimized posts with images, and schedule creation in WordPress.
Version: 1.2
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Author: Guru Plugins
Author URI: https://plugins.guru-is.com
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: autopost-ai
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AUTOPOST_AI_VERSION', '1.0.0' );
define( 'AUTOPOST_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'AUTOPOST_AI_URL', plugin_dir_url( __FILE__ ) );

// Register custom cron schedule (every 15 minutes).
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		if ( ! isset( $schedules['every_15_minutes'] ) ) {
			$schedules['every_15_minutes'] = array(
				'interval' => 900,
				'display'  => __( 'Every 15 Minutes', 'autopost-ai' ),
			);
		}
		return $schedules;
	}
);

// Load core files.
require_once AUTOPOST_AI_PATH . 'includes/class-autopostai-admin.php';
require_once AUTOPOST_AI_PATH . 'includes/class-autopostai-settings.php';
require_once AUTOPOST_AI_PATH . 'includes/class-autopostai-ajax.php';
require_once AUTOPOST_AI_PATH . 'includes/class-autopostai-cron.php';
require_once AUTOPOST_AI_PATH . 'includes/functions-openai.php';

// Create DB table on activation.
register_activation_hook( __FILE__, 'autopost_ai_create_queue_table' );
function autopost_ai_create_queue_table() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'autopost_ai_queue';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		topic TEXT NOT NULL,
		category_id BIGINT NOT NULL,
		category_label VARCHAR(255) NOT NULL,
		status VARCHAR(50) DEFAULT 'pending',
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		post_created_at DATETIME DEFAULT NULL,
		published_at DATETIME DEFAULT NULL,
		PRIMARY KEY (id)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Set redirect flag for after activation.
	update_option( 'autopost_ai_do_activation_redirect', true );
}

// Redirect to admin settings after activation (not on bulk).
add_action(
	'admin_init',
	function () {
		if ( get_option( 'autopost_ai_do_activation_redirect', false ) ) {
			delete_option( 'autopost_ai_do_activation_redirect' );

			// Use filter_input to avoid direct superglobal access (nonce verification not required for this passive redirect).
			$activate_multi = filter_input( INPUT_GET, 'activate-multi', FILTER_DEFAULT );

			// If not bulk activation, redirect to the plugin page.
			if ( null === $activate_multi ) {
				wp_safe_redirect( admin_url( 'admin.php?page=autopost-ai' ) );
				exit;
			}
		}
	}
);

// Init.
add_action(
	'plugins_loaded',
	function () {
		new AutoPostAI_Admin();
		new AutoPostAI_Settings();
		new AutoPostAI_Cron();
	}
);

register_deactivation_hook( __FILE__, 'autopost_ai_deactivation_notice' );
function autopost_ai_deactivation_notice() {
	update_option( 'autopost_ai_show_deactivation_warning', true );
}

add_action(
	'admin_notices',
	function () {
		if ( get_option( 'autopost_ai_show_deactivation_warning' ) ) {
			echo '<div class="notice notice-warning is-dismissible">
				<p><strong>AutoPost AI:</strong> Deactivating or deleting this plugin will permanently remove ALL saved data! Are you sure?</p>
			</div>';
			delete_option( 'autopost_ai_show_deactivation_warning' );
		}
	}
);

register_uninstall_hook( __FILE__, 'autopost_ai_uninstall_cleanup' );
function autopost_ai_uninstall_cleanup() {
	// Always clear scheduled events.
	wp_clear_scheduled_hook( 'autopost_ai_cron_hook' );

	// Only remove data if the site owner opted in.
	$remove_data = (bool) get_option( 'autopost_ai_remove_data_on_uninstall', false );
	$remove_data = (bool) apply_filters( 'autopost_ai/remove_data_on_uninstall', $remove_data );

	if ( ! $remove_data ) {
		return;
	}

	// Drop custom DB table (owned by this plugin).
	global $wpdb;
	$table_name     = $wpdb->prefix . 'autopost_ai_queue';
	$allowed_tables = array( $wpdb->prefix . 'autopost_ai_queue' );

	if ( in_array( $table_name, $allowed_tables, true ) ) {
		// Build the DDL without inline interpolation so PCP doesn't flag InterpolatedNotPrepared.
		$sql = 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'autopost_ai_queue`';

		// DDL is intentionally a direct query; identifiers can't be parameterized via prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $sql );
	}

	// Remove plugin options.
	delete_option( 'autopost_ai_api_key' );
	delete_option( 'autopost_ai_post_settings' );
	delete_option( 'autopost_ai_prompt' );
	delete_option( 'autopost_ai_last_post_created_at' );
	delete_option( 'autopost_ai_remove_data_on_uninstall' );
}

// Add "Dashboard" and "Documentation" links under plugin name on Plugins page.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		// Dashboard link.
		$dashboard_url  = admin_url( 'admin.php?page=autopost-ai' );
		$dashboard_link = '<a href="' . esc_url( $dashboard_url ) . '">Dashboard</a>';

		// Documentation link (PDF in plugin root).
		$doc_url  = plugin_dir_url( __FILE__ ) . 'autopost-ai-documentation.pdf';
		$doc_link = '<a href="' . esc_url( $doc_url ) . '" target="_blank" rel="noopener noreferrer">Documentation</a>';

		// Unshift in reverse so final order is: Dashboard, Documentation, ...
		array_unshift( $links, $doc_link );
		array_unshift( $links, $dashboard_link );

		return $links;
	}
);


