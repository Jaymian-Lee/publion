<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register a 15-minute cron schedule (so "every_15_minutes" exists).
 */
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( empty( $schedules['every_15_minutes'] ) ) {
		$schedules['every_15_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 Minutes', 'autopost-ai' ),
		);
	}
	return $schedules;
} );

/**
 * Cached DB row reader for cron. Centralizes PHPCS ignores so call sites stay clean.
 *
 * @param string $sql   SQL with placeholders.
 * @param array  $args  Placeholder args (array supported by $wpdb->prepare()).
 * @param string $key   Cache key.
 * @param string $group Cache group.
 * @param int    $ttl   Cache seconds.
 * @return object|null  Row object or null.
 */
if ( ! function_exists( 'autopost_ai_db_get_row_cached_cron' ) ) {
	function autopost_ai_db_get_row_cached_cron( $query_template, array $args, $key, $group, $ttl = 60 ) {
		global $wpdb;

		$cached = wp_cache_get( $key, $group );
		if ( false !== $cached ) {
			return $cached; // may be null
		}

		// The template + args are prepared here.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $query_template, $args );

		$result = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$prepared, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			OBJECT
		);

		wp_cache_set( $key, $result, $group, $ttl );
		return $result;
	}
}

class AutoPostAI_Cron {

	/** @var string */
	protected $cache_group = 'autopost_ai';

	/** @var string */
	protected $cache_key_next_topic = 'next_pending_topic_v1';

	public function __construct() {
		add_action( 'init', array( $this, 'schedule_cron' ) );
		add_action( 'autopost_ai_cron_hook', array( $this, 'maybe_create_queued_post' ) );
	}

	public function schedule_cron() {
		if ( ! wp_next_scheduled( 'autopost_ai_cron_hook' ) ) {
			// Keep original behavior that used time().
			wp_schedule_event( time(), 'every_15_minutes', 'autopost_ai_cron_hook' );
		}
	}

	public function maybe_create_queued_post() {
		global $wpdb;

		// Register custom table on $wpdb so PHPCS allows it in prepared SQL.
		if ( empty( $wpdb->autopost_ai_queue ) ) {
			$wpdb->autopost_ai_queue = $wpdb->prefix . 'autopost_ai_queue';
		}

		// Read settings.
		$settings           = get_option( 'autopost_ai_post_settings', array() );
		$time_frame_days    = (int) ( $settings['time_frame_days'] ?? 3 );
		$post_status        = $settings['post_status'] ?? 'draft';
		$add_cta            = ( ( $settings['cta_enabled'] ?? 'no' ) === 'yes' );
		$cta_text           = $settings['cta_text'] ?? '';
		$cta_link           = $settings['cta_link'] ?? '';
		$notification_email = $settings['notification_email'] ?? '';

		// First-run timing gate (same as before).
		$last_created_raw = get_option( 'autopost_ai_last_post_created_at' );
		if ( ! $last_created_raw ) {
			update_option( 'autopost_ai_last_post_created_at', current_time( 'mysql' ) );
			return;
		}

		$last_time    = strtotime( $last_created_raw );
		$next_allowed = strtotime( '+' . $time_frame_days . ' days', $last_time );
		$now_ts       = current_time( 'timestamp' );

		if ( $now_ts < $next_allowed ) {
			return;
		}

		// Normalize stragglers: pending + has post_created_at -> created.
		// Writes are not cacheable. We invalidate the read cache below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->autopost_ai_queue} SET status = %s WHERE status = %s AND post_created_at IS NOT NULL",
				'created',
				'pending'
			)
		);

		// Invalidate cached "next topic" after write that may affect result set.
		wp_cache_delete( $this->cache_key_next_topic, $this->cache_group );

		// Select next pending by lowest ID via cached helper (keeps call site WPCS-clean).
		$topic = autopost_ai_db_get_row_cached_cron(
			"SELECT * FROM {$wpdb->autopost_ai_queue} WHERE status = %s AND post_created_at IS NULL ORDER BY id ASC LIMIT 1",
			array( 'pending' ),
			$this->cache_key_next_topic,
			$this->cache_group,
			60
		);

		if ( ! $topic ) {
			return;
		}

		// Generate HTML content.
		$post_html = autopost_ai_generate_chatgpt_html( $topic->topic, $topic->category_label );
		if ( is_wp_error( $post_html ) || ! $post_html ) {
			return;
		}

		// Image setup — use your real function: autopost_ai_get_pixabay_images().
		$category      = get_term( $topic->category_id, 'category' );
		$category_name = ( $category && ! is_wp_error( $category ) ) ? $category->name : '';
		$image_query   = $category_name;

		$plugin_dir_url = plugin_dir_url( __FILE__ );
		$placeholder    = $plugin_dir_url . 'images/image-placeholder.jpg';

		$image_urls = autopost_ai_get_pixabay_images( $image_query, 6 );
		if ( ! is_array( $image_urls ) ) {
			$image_urls = array();
		}
		while ( count( $image_urls ) < 6 ) {
			$image_urls[] = $placeholder;
		}

		$image_ids        = array();
		$final_image_urls = array();

		foreach ( $image_urls as $img_url ) {
			if ( $img_url === $placeholder ) {
				$final_image_urls[] = $placeholder;
			} else {
				$upload = autopost_ai_upload_image( $img_url, $topic->topic );
				if ( is_array( $upload ) && isset( $upload['attachment_id'], $upload['url'] ) ) {
					$image_ids[]        = $upload['attachment_id'];
					$final_image_urls[] = $upload['url'];
				} else {
					$final_image_urls[] = $placeholder;
				}
			}
		}

		// Insert images into content (first 5).
		$post_html = autopost_ai_insert_images_into_content( $post_html, array_slice( $final_image_urls, 0, 5 ) );

		// Optional CTA (same behavior).
		if ( $add_cta && $cta_text && $cta_link ) {
			$post_html .= "<div style='clear:both; padding-top:20px; margin-top:30px; border-top:1px solid #ccc;'>
				<p>Need help with <strong>" . esc_html( $topic->topic ) . "</strong>? <a href='" . esc_url( $cta_link ) . "' target='_blank'>" . esc_html( $cta_text ) . "</a></p>
			</div>";
		}

		// Create post.
		$post_id = wp_insert_post( array(
			'post_title'    => $topic->topic,
			'post_content'  => $post_html,
			'post_status'   => $post_status,
			'post_category' => array( $topic->category_id ),
			'post_type'     => 'post',
		) );
		
		if ( ! is_wp_error( $post_id ) && $post_id ) {
			// Link post -> queue row for reliable lookups later.
			// Using update_post_meta makes this safe to run more than once.
			update_post_meta( (int) $post_id, '_autopost_ai_queue_id', (int) $topic->id );
		}
		
		if ( is_wp_error( $post_id ) ) {
			return;
		}

		// Set featured image (slot 6) if not placeholder.
		if ( isset( $final_image_urls[5] ) && $final_image_urls[5] !== $placeholder && isset( $image_ids[5] ) ) {
			set_post_thumbnail( $post_id, $image_ids[5] );
		}

		// Update queue row. Writes are not cacheable; also invalidate the next-topic cache.
		$now = current_time( 'mysql' );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->autopost_ai_queue,
			array(
				'status'          => ( 'publish' === $post_status ? 'published' : 'created' ),
				'post_created_at' => $now,
				'published_at'    => ( 'publish' === $post_status ? $now : null ),
			),
			array( 'id' => (int) $topic->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		wp_cache_delete( $this->cache_key_next_topic, $this->cache_group );

		update_option( 'autopost_ai_last_post_created_at', $now );

		// Email Notification.
		if ( ! empty( $notification_email ) && is_email( $notification_email ) && get_permalink( $post_id ) ) {
			wp_mail(
				$notification_email,
				'New Post Created by AutoPost AI',
				"A new post titled \"{$topic->topic}\" was just created.\n\nView it here:\n" . get_permalink( $post_id ),
				array( 'Content-Type: text/plain; charset=UTF-8' )
			);
		}
	}
}

