<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register a 15-minute cron schedule (so "every_15_minutes" exists).
 */
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( empty( $schedules['every_15_minutes'] ) ) {
		$schedules['every_15_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Elke 15 minuten', 'publion' ),
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
if ( ! function_exists( 'publion_db_get_row_cached_cron' ) ) {
	function publion_db_get_row_cached_cron( $query_template, array $args, $key, $group, $ttl = 60 ) {
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

class Publion_Cron {

	/** @var string */
	protected $cache_group = 'publion';

	/** @var string */
	protected $cache_key_next_topic = 'next_pending_topic_v1';

	public function __construct() {
		add_action( 'init', array( $this, 'schedule_cron' ) );
		add_action( 'publion_cron_hook', array( $this, 'maybe_create_queued_post' ) );
		add_action( 'publion_daily_topic_hook', array( $this, 'maybe_create_daily_topic' ) );
	}

	public function schedule_cron() {
		if ( ! wp_next_scheduled( 'publion_cron_hook' ) ) {
			// Keep original behavior that used time().
			wp_schedule_event( time(), 'every_15_minutes', 'publion_cron_hook' );
		}

		$settings = get_option( 'publion_post_settings', array() );
		if ( ( $settings['auto_daily_topic'] ?? 'no' ) !== 'yes' ) {
			wp_clear_scheduled_hook( 'publion_daily_topic_hook' );
			return;
		}

		if ( ! wp_next_scheduled( 'publion_daily_topic_hook' ) ) {
			publion_reschedule_daily_topic_event( $settings );
		}
	}

	protected function schedule_next_daily_topic( $settings ) {
		$tz      = wp_timezone();
		$now     = new DateTimeImmutable( 'now', $tz );
		$next_ts = publion_calculate_next_daily_topic_timestamp( $now, $settings );
		wp_clear_scheduled_hook( 'publion_daily_topic_hook' );
		wp_schedule_single_event( $next_ts, 'publion_daily_topic_hook' );
	}

	public function maybe_create_daily_topic() {
		$settings = get_option( 'publion_post_settings', array() );
		if ( ( $settings['auto_daily_topic'] ?? 'no' ) !== 'yes' ) {
			return;
		}

		$api_key = get_option( 'publion_api_key', '' );
		if ( empty( $api_key ) ) {
			$this->schedule_next_daily_topic( $settings );
			return;
		}

		$categories = get_categories(
			array(
				'hide_empty' => false,
				'exclude'    => array( (int) get_option( 'default_category' ) ),
			)
		);
		if ( empty( $categories ) ) {
			$this->schedule_next_daily_topic( $settings );
			return;
		}

		shuffle( $categories );

		$default_prompt = "Je bent een expert in het schrijven van blogs en maakt hoogwaardige, SEO-geoptimaliseerde content voor [JOUW BEDRIJFSNAAM (INDIEN VAN TOEPASSING) EN WEBSITE-URL], [WAT JOUW BEDRIJF/WEBSITE BIEDT]. Het doel is [JOUW BEDRIJFS/WEBSITE-DOELEN]. Stem de toon af op het merk: [DE TOON DIE JE WILT UITSTRALEN - voorbeeld: professioneel maar benaderbaar, deskundig maar eenvoudig uit te leggen]. Elk onderwerp moet de missie van [JOUW BEDRIJFS/WEBSITE-NAAM] weerspiegelen om [BEDRIJVEN of MENSEN] te helpen met [HOE JE BEDRIJVEN of MENSEN HELPT]. (Vervang deze prompt door je eigen tekst om je doelen beter te weerspiegelen.)";
		$pre_prompt     = get_option( 'publion_prompt', $default_prompt );

		global $wpdb;
		if ( empty( $wpdb->publion_queue ) ) {
			$wpdb->publion_queue = $wpdb->prefix . 'publion_queue';
		}
		$content_map = publion_get_existing_content_map();

		foreach ( $categories as $category ) {
			$category_name = $category->name;

			$prompt  = $pre_prompt . "\n\nOp basis van de categorie \"" . $category_name . "\", stel 5 unieke blogonderwerpen voor.";
			$prompt .= "\n\nLees eerst de onderstaande actuele contentkaart van alle " . (int) $content_map['count'] . " bestaande WordPress-berichten. Geef alleen een onderwerp met een nieuwe zoekvraag en een duidelijk andere invalshoek; hergebruik geen bestaande titel, koppenstructuur of centrale uitleg.\n\n=== ACTUELE CONTENTKAART ===\n" . $content_map['context'] . "\n=== EINDE CONTENTKAART ===";
			$prompt .= "\n\nFormaat: geef exact 5 onderwerpen, elk op een eigen regel. Geen inleiding, geen bullets, geen nummers, geen Markdown, geen extra uitleg.";

			$response = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						publion_build_openai_chat_body(
							publion_get_openai_model(),
							array(
								array( 'role' => 'system', 'content' => 'Je bent een behulpzame assistent.' ),
								array( 'role' => 'user', 'content' => $prompt ),
							),
							200
						)
					),
					'timeout' => 60,
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				update_option( 'publion_last_openai_error', publion_get_openai_request_error( $response, publion_get_openai_model() ) );
				continue;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$text = $body['choices'][0]['message']['content'] ?? '';
			if ( empty( $text ) ) {
				update_option( 'publion_last_openai_error', 'OpenAI gaf geen onderwerpvoorstellen terug tijdens de geplande taak.' );
				continue;
			}

			delete_option( 'publion_last_openai_error' );

			$lines = preg_split( '/\r\n|\n|\r/', trim( $text ) );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}

				$line = preg_replace( '/^\s*[-*•]+\s*/', '', $line );
				$line = preg_replace( '/^\s*\d+[\.\)]\s*/', '', $line );
				$line = str_replace( '**', '', $line );
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}

				// Check uniqueness against posts and queue.
				if ( function_exists( 'publion_get_post_id_by_exact_title' ) ) {
					if ( publion_get_post_id_by_exact_title( $line ) ) {
						continue;
					}
				}
				if ( publion_find_existing_content_conflict( $line ) ) {
					continue;
				}

				$exists_in_queue = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT COUNT(1) FROM {$wpdb->publion_queue} WHERE LOWER(topic) = LOWER(%s)",
						$line
					)
				);
				if ( $exists_in_queue ) {
					continue;
				}

				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->publion_queue,
					array(
						'topic'          => $line,
						'category_id'    => (int) $category->term_id,
						'category_label' => $category_name,
						'status'         => 'pending',
						'created_at'     => current_time( 'mysql' ),
					)
				);

				// Invalidate caches affected by this write.
				wp_cache_delete( 'pending_ids', $this->cache_group );
				wp_cache_delete( 'pending_total', $this->cache_group );
				publion_schedule_pending_entries( false );
				$this->schedule_next_daily_topic( $settings );

				return;
			}
		}

		$this->schedule_next_daily_topic( $settings );
	}

	public function maybe_create_queued_post() {
		global $wpdb;

		// Register custom table on $wpdb so PHPCS allows it in prepared SQL.
		if ( empty( $wpdb->publion_queue ) ) {
			$wpdb->publion_queue = $wpdb->prefix . 'publion_queue';
		}

		// Read settings.
		$settings           = get_option( 'publion_post_settings', array() );
		$post_status        = $settings['post_status'] ?? 'draft';
		$add_cta            = ( ( $settings['cta_enabled'] ?? 'no' ) === 'yes' );
		$cta_text           = $settings['cta_text'] ?? '';
		$cta_link           = $settings['cta_link'] ?? '';
		$notification_email = $settings['notification_email'] ?? '';
		$rank_math_enabled  = ( ( $settings['rank_math_integration'] ?? 'no' ) === 'yes' );
		$author_id          = publion_get_post_author_id( $settings, 'first' );

		// Ensure schedule exists for pending entries.
		publion_schedule_pending_entries( false );

		// Normalize stragglers: pending + has post_created_at -> created.
		// Writes are not cacheable. We invalidate the read cache below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->publion_queue} SET status = %s WHERE status = %s AND post_created_at IS NOT NULL",
				'created',
				'pending'
			)
		);

		// Invalidate cached "next topic" after write that may affect result set.
		wp_cache_delete( $this->cache_key_next_topic, $this->cache_group );

		// Select next pending by lowest ID via cached helper (keeps call site WPCS-clean).
		$topic = publion_db_get_row_cached_cron(
			"SELECT * FROM {$wpdb->publion_queue} WHERE status = %s AND post_created_at IS NULL ORDER BY (scheduled_at IS NULL) ASC, scheduled_at ASC, id ASC LIMIT 1",
			array( 'pending' ),
			$this->cache_key_next_topic,
			$this->cache_group,
			60
		);

		if ( ! $topic ) {
			return;
		}

		$scheduled_ts = $topic->scheduled_at ? publion_mysql_to_timestamp( $topic->scheduled_at ) : 0;
		$now_ts       = current_time( 'timestamp' );
		if ( $scheduled_ts && $now_ts < $scheduled_ts ) {
			return;
		}

		// Generate HTML content.
		$seo_brief = ! empty( $topic->seo_brief ) ? json_decode( $topic->seo_brief, true ) : array();
		$seo_brief = is_array( $seo_brief ) ? $seo_brief : array();
		$seo_brief['focus_keyword'] = sanitize_text_field( $topic->focus_keyword ?? $topic->topic );
		$post_html = publion_generate_chatgpt_html( $topic->topic, $topic->category_label, $seo_brief );
		if ( is_wp_error( $post_html ) || ! $post_html ) {
			return;
		}

		// Image setup — generate 6 context-aware AI images based on nearby text.
		$category      = get_term( $topic->category_id, 'category' );
		$category_name = ( $category && ! is_wp_error( $category ) ) ? $category->name : '';
		$api_key       = get_option( 'publion_api_key', '' );

		$plugin_dir_url = plugin_dir_url( __FILE__ );
		$placeholder    = $plugin_dir_url . 'images/image-placeholder.jpg';

		$prompts = publion_generate_contextual_image_prompts( $post_html, $topic->topic, $category_name );
		$image_ids        = array();
		$final_image_urls = array();
		$image_layouts    = array();

		foreach ( $prompts as $item ) {
			$prompt_text = $item['prompt'] ?? '';
			$context     = $item['context'] ?? $topic->topic;
			$image_layout = ( isset( $item['layout'] ) && 'square' === $item['layout'] ) ? 'square' : 'landscape';
			$image_size   = ( isset( $item['size'] ) && in_array( $item['size'], array( '1024x1024', '1536x1024', '1024x1536', '1536x864' ), true ) ) ? $item['size'] : '1024x1024';
			$image_result = publion_generate_and_upload_images( $prompt_text, 1, $context, $api_key, $image_size );
			$image_layouts[] = $image_layout;
			if ( ! empty( $image_result['urls'][0] ) && ! empty( $image_result['ids'][0] ) ) {
				$final_image_urls[] = $image_result['urls'][0];
				$image_ids[]        = (int) $image_result['ids'][0];
			} else {
				$final_image_urls[] = $placeholder;
				$image_ids[]        = 0;
			}
		}

		while ( count( $final_image_urls ) < 6 ) {
			$final_image_urls[] = $placeholder;
			$image_ids[]        = 0;
			$image_layouts[]    = 'landscape';
		}

		// Insert images into content (first 5).
		$post_html = publion_insert_images_into_content( $post_html, array_slice( $final_image_urls, 0, 5 ), array_slice( $image_layouts, 0, 5 ) );


		// Create post.
		$post_id = wp_insert_post( array(
			'post_title'    => $topic->topic,
			'post_content'  => $post_html,
			'post_status'   => $post_status,
			'post_category' => array( $topic->category_id ),
			'post_type'     => 'post',
			'post_author'   => $author_id,
		) );
		
		if ( ! is_wp_error( $post_id ) && $post_id ) {
			// Link post -> queue row for reliable lookups later.
			// Using update_post_meta makes this safe to run more than once.
			update_post_meta( (int) $post_id, '_publion_queue_id', (int) $topic->id );
			publion_store_article_seo_data( $post_id, $post_html, $seo_brief['focus_keyword'] );

			if ( $rank_math_enabled ) {
				update_post_meta( (int) $post_id, 'rank_math_focus_keyword', $seo_brief['focus_keyword'] );
				update_post_meta( (int) $post_id, 'rank_math_title', $topic->topic );
				update_post_meta( (int) $post_id, 'rank_math_description', publion_build_meta_description( $post_html ) );
			}
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
			$wpdb->publion_queue,
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

		update_option( 'publion_last_post_created_at', $now );

		// Email Notification.
		if ( ! empty( $notification_email ) && is_email( $notification_email ) && get_permalink( $post_id ) ) {
			wp_mail(
				$notification_email,
				'Nieuwe post aangemaakt door Publion',
				"Er is zojuist een nieuwe post aangemaakt met de titel \"{$topic->topic}\".\n\nBekijk hem hier:\n" . get_permalink( $post_id ),
				array( 'Content-Type: text/plain; charset=UTF-8' )
			);
		}
	}
}

