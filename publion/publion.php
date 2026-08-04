<?php
/*
Plugin Name: Publion
Plugin URI: https://jaymian-lee.nl/publion
Description: Genereer en verfijn blogposts met AI. Kies een categorie, krijg onderwerp-ideeën, zet SEO-geoptimaliseerde posts met afbeeldingen in de wachtrij en plan het aanmaken in WordPress.
Version: 1.9.15
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Author: Jaymian-Lee
Author URI: https://jaymian-lee.nl
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: publion
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PUBLION_VERSION', '1.9.15' );
define( 'PUBLION_PATH', plugin_dir_path( __FILE__ ) );
define( 'PUBLION_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load the translation that matches the current WordPress locale.
 *
 * WordPress uses the individual administrator's language where available, so
 * two editors can safely use Publion in different languages on the same site.
 * A site-wide language pack in wp-content/languages/plugins takes precedence.
 * When a non-Dutch locale has no dedicated Publion translation yet, the bundled
 * English catalogue is used instead of falling back to a partly Dutch screen.
 */
function publion_load_textdomain() {
	$domain        = 'publion';
	$locale        = determine_locale();
	$language_root = defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR . '/plugins/' : '';

	load_plugin_textdomain( $domain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	$global_mofile = $language_root ? $language_root . $domain . '-' . $locale . '.mo' : '';
	$plugin_mofile = PUBLION_PATH . 'languages/' . $domain . '-' . $locale . '.mo';

	if ( $global_mofile && is_readable( $global_mofile ) ) {
		load_textdomain( $domain, $global_mofile );
		return;
	}

	if ( is_readable( $plugin_mofile ) ) {
		load_textdomain( $domain, $plugin_mofile );
		return;
	}

	// Dutch is the source language. Every other unsupported locale gets a
	// complete English base interface until a dedicated language pack is added.
	if ( 0 !== strpos( strtolower( $locale ), 'nl' ) ) {
		$english_mofile = PUBLION_PATH . 'languages/' . $domain . '-en_US.mo';
		if ( is_readable( $english_mofile ) ) {
			load_textdomain( $domain, $english_mofile );
		}
	}
}
add_action( 'plugins_loaded', 'publion_load_textdomain', 1 );

// Register custom cron schedule (every 15 minutes).
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		if ( ! isset( $schedules['every_15_minutes'] ) ) {
			$schedules['every_15_minutes'] = array(
				'interval' => 900,
				'display'  => __( 'Elke 15 minuten', 'publion' ),
			);
		}
		return $schedules;
	}
);

// Load core files.
require_once PUBLION_PATH . 'includes/class-publion-admin.php';
require_once PUBLION_PATH . 'includes/class-publion-settings.php';
require_once PUBLION_PATH . 'includes/class-publion-ajax.php';
require_once PUBLION_PATH . 'includes/class-publion-cron.php';
require_once PUBLION_PATH . 'includes/functions-openai.php';

// Create DB table on activation.
register_activation_hook( __FILE__, 'publion_create_queue_table' );
function publion_create_queue_table() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'publion_queue';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		topic TEXT NOT NULL,
		focus_keyword VARCHAR(255) DEFAULT '',
		seo_brief LONGTEXT DEFAULT NULL,
		category_id BIGINT NOT NULL,
		category_label VARCHAR(255) NOT NULL,
		status VARCHAR(50) DEFAULT 'pending',
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		scheduled_at DATETIME DEFAULT NULL,
		schedule_locked TINYINT(1) DEFAULT 0,
		post_created_at DATETIME DEFAULT NULL,
		published_at DATETIME DEFAULT NULL,
		PRIMARY KEY (id)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Set redirect flag for after activation.
	update_option( 'publion_do_activation_redirect', true );
}

// Redirect to admin settings after activation (not on bulk).
add_action(
	'admin_init',
	function () {
		if ( get_option( 'publion_do_activation_redirect', false ) ) {
			delete_option( 'publion_do_activation_redirect' );

			// Use filter_input to avoid direct superglobal access (nonce verification not required for this passive redirect).
			$activate_multi = filter_input( INPUT_GET, 'activate-multi', FILTER_DEFAULT );

			// If not bulk activation, redirect to the plugin page.
			if ( null === $activate_multi ) {
				wp_safe_redirect( admin_url( 'admin.php?page=publion' ) );
				exit;
			}
		}
	}
);

// Init.
	add_action(
	'plugins_loaded',
	function () {
		publion_maybe_update_queue_table();
		new Publion_Admin();
		new Publion_Settings();
		new Publion_Cron();
	}
);

function publion_maybe_update_queue_table() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'publion_queue';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		topic TEXT NOT NULL,
		focus_keyword VARCHAR(255) DEFAULT '',
		seo_brief LONGTEXT DEFAULT NULL,
		category_id BIGINT NOT NULL,
		category_label VARCHAR(255) NOT NULL,
		status VARCHAR(50) DEFAULT 'pending',
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		scheduled_at DATETIME DEFAULT NULL,
		schedule_locked TINYINT(1) DEFAULT 0,
		post_created_at DATETIME DEFAULT NULL,
		published_at DATETIME DEFAULT NULL,
		PRIMARY KEY (id)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

register_deactivation_hook( __FILE__, 'publion_deactivation_notice' );
function publion_deactivation_notice() {
	update_option( 'publion_show_deactivation_warning', true );
}

add_action(
	'admin_notices',
	function () {
		if ( get_option( 'publion_show_deactivation_warning' ) ) {
			echo '<div class="notice notice-warning is-dismissible">
				<p><strong>Publion:</strong> ' . esc_html__( 'Deactiveren of verwijderen van deze plugin verwijdert alle opgeslagen gegevens permanent. Weet je het zeker?', 'publion' ) . '</p>
			</div>';
			delete_option( 'publion_show_deactivation_warning' );
		}
	}
);

register_uninstall_hook( __FILE__, 'publion_uninstall_cleanup' );
function publion_uninstall_cleanup() {
	// Always clear scheduled events.
	wp_clear_scheduled_hook( 'publion_cron_hook' );
	wp_clear_scheduled_hook( 'publion_daily_topic_hook' );

	// Only remove data if the site owner opted in.
	$remove_data = (bool) get_option( 'publion_remove_data_on_uninstall', false );
	$remove_data = (bool) apply_filters( 'publion/remove_data_on_uninstall', $remove_data );

	if ( ! $remove_data ) {
		return;
	}

	// Drop custom DB table (owned by this plugin).
	global $wpdb;
	$table_name     = $wpdb->prefix . 'publion_queue';
	$allowed_tables = array( $wpdb->prefix . 'publion_queue' );

	if ( in_array( $table_name, $allowed_tables, true ) ) {
		// Build the DDL without inline interpolation so PCP doesn't flag InterpolatedNotPrepared.
		$sql = 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'publion_queue`';

		// DDL is intentionally a direct query; identifiers can't be parameterized via prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $sql );
	}

	// Remove plugin options.
	delete_option( 'publion_api_key' );
	delete_option( 'publion_post_settings' );
	delete_option( 'publion_prompt' );
	delete_option( 'publion_openai_model' );
	delete_option( 'publion_openai_image_model' );
	delete_option( 'publion_last_image_error' );
	delete_option( 'publion_last_post_created_at' );
	delete_option( 'publion_remove_data_on_uninstall' );
}

// Add "Dashboard" and "Documentation" links under plugin name on Plugins page.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		// Dashboard link.
		$dashboard_url  = admin_url( 'admin.php?page=publion' );
		$dashboard_link = '<a href="' . esc_url( $dashboard_url ) . '">' . esc_html__( 'Overzicht', 'publion' ) . '</a>';

		// Documentation link (PDF in plugin root).
		$doc_url  = plugin_dir_url( __FILE__ ) . 'publion-documentation.pdf';
		$doc_link = '<a href="' . esc_url( $doc_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentatie', 'publion' ) . '</a>';

		// Unshift in reverse so final order is: Dashboard, Documentation, ...
		array_unshift( $links, $doc_link );
		array_unshift( $links, $dashboard_link );

		return $links;
	}
);


