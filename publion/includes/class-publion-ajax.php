<?php
/**
 * AJAX handlers for Publion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Cache settings */
if ( ! defined( 'PUBLION_CACHE_GROUP' ) ) {
	define( 'PUBLION_CACHE_GROUP', 'publion' );
}
if ( ! defined( 'PUBLION_CACHE_TTL' ) ) {
	define( 'PUBLION_CACHE_TTL', 60 ); // seconds
}

/**
 * Ensure our custom table is registered on $wpdb so WPCS allows it in SQL.
 */
function publion_register_table_on_wpdb() {
	global $wpdb;
	if ( empty( $wpdb->publion_queue ) ) {
		$wpdb->publion_queue = $wpdb->prefix . 'publion_queue';
	}
}

/** Cache helpers */
function publion_cache_get( $key ) {
	return wp_cache_get( $key, PUBLION_CACHE_GROUP );
}
function publion_cache_set( $key, $value, $ttl = PUBLION_CACHE_TTL ) {
	return wp_cache_set( $key, $value, PUBLION_CACHE_GROUP, $ttl );
}
function publion_cache_delete( $key ) {
	return wp_cache_delete( $key, PUBLION_CACHE_GROUP );
}

function publion_db_get_col_cached( $query, array $args, $cache_key, $ttl = PUBLION_CACHE_TTL ) {
	global $wpdb;

	$cached = publion_cache_get( $cache_key );
	if ( false !== $cached ) { return $cached; }
	if ( empty( $args ) ) { _doing_it_wrong( __FUNCTION__, 'Empty $args for SQL.', '1.0.0' ); return array(); }

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$prepared = $wpdb->prepare( $query, $args );

	// Safety: if prepare failed, bail cleanly.
	if ( false === $prepared ) { return array(); }

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$result = $wpdb->get_col( $prepared );

	publion_cache_set( $cache_key, $result, $ttl );
	return $result;
}

function publion_db_get_var_cached( $query, array $args, $cache_key, $ttl = PUBLION_CACHE_TTL ) {
	global $wpdb;

	$cached = publion_cache_get( $cache_key );
	if ( false !== $cached ) { return $cached; }
	if ( empty( $args ) ) { _doing_it_wrong( __FUNCTION__, 'Empty $args for SQL.', '1.0.0' ); return null; }

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$prepared = $wpdb->prepare( $query, $args );
	if ( false === $prepared ) { return null; }

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$result = $wpdb->get_var( $prepared );

	publion_cache_set( $cache_key, $result, $ttl );
	return $result;
}

function publion_db_get_row_cached( $query, array $args, $cache_key, $ttl = PUBLION_CACHE_TTL ) {
	global $wpdb;

	$cached = publion_cache_get( $cache_key );
	if ( false !== $cached ) { return $cached; }
	if ( empty( $args ) ) { _doing_it_wrong( __FUNCTION__, 'Empty $args for SQL.', '1.0.0' ); return null; }

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$prepared = $wpdb->prepare( $query, $args );
	if ( false === $prepared ) { return null; }

	// Explicit OBJECT to avoid surprises.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$result = $wpdb->get_row( $prepared, OBJECT );

	publion_cache_set( $cache_key, $result, $ttl );
	return $result;
}

function publion_db_get_results_cached( $query, array $args, $cache_key, $ttl = PUBLION_CACHE_TTL ) {
	global $wpdb;

	$cached = publion_cache_get( $cache_key );
	if ( false !== $cached ) { return $cached; }
	if ( empty( $args ) ) { _doing_it_wrong( __FUNCTION__, 'Empty $args for SQL.', '1.0.0' ); return array(); }

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$prepared = $wpdb->prepare( $query, $args );
	if ( false === $prepared ) { return array(); }

	// Explicit OBJECT to match callers’ expectations.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$result = $wpdb->get_results( $prepared, OBJECT );

	publion_cache_set( $cache_key, $result, $ttl );
	return $result;
}

/**
 * Convert a MySQL local datetime (stored with current_time('mysql')) to a UNIX timestamp
 * honoring the site timezone (so wp_date() renders correctly).
 */
function publion_mysql_to_timestamp( $mysql_datetime ) {
	if ( empty( $mysql_datetime ) ) {
		return 0;
	}
	try {
		$tz = wp_timezone();
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime, $tz );
		if ( $dt instanceof DateTimeImmutable ) {
			return $dt->getTimestamp();
		}
	} catch ( Throwable $e ) {}
	return strtotime( $mysql_datetime );
}

/**
 * Title normalization to tolerate slashes/quotes/whitespace.
 */
function publion_normalize_title( $t ) {
	$t = (string) $t;
	$t = wp_unslash( $t );
	$t = wp_specialchars_decode( $t, ENT_QUOTES );
	$t = preg_replace( '/\s+/u', ' ', $t );
	$t = trim( $t );
	return $t;
}

/**
 * Find a post ID by exact/normalized title using WP_Query (cached).
 * Uses sentence search, then filters to exact match (with normalization) and slug match.
 */
function publion_get_post_id_by_exact_title( $title ) {
	$title = publion_normalize_title( $title );
	if ( '' === $title ) {
		return 0;
	}

	$cache_key = 'postid_title_' . md5( $title );
	$cached    = publion_cache_get( $cache_key );
	if ( false !== $cached ) {
		return (int) $cached;
	}

	$q = new WP_Query( array(
		'post_type'              => 'post',
		'post_status'            => 'any',
		'posts_per_page'         => 20,
		's'                      => $title,
		'sentence'               => true,
		'fields'                 => 'ids',
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'suppress_filters'       => true,
	) );

	$needle_norm = $title;
	$needle_slug = sanitize_title( $needle_norm );
	$found_id    = 0;

	if ( $q->have_posts() ) {
		foreach ( $q->posts as $pid ) {
			$cand      = get_the_title( $pid );
			$cand_norm = publion_normalize_title( $cand );

			// Strict normalized compare.
			if ( 0 === strcasecmp( $cand_norm, $needle_norm ) ) {
				$found_id = (int) $pid;
				break;
			}

			// Slug compare (covers case/spacing/dashes).
			if ( sanitize_title( $cand_norm ) === $needle_slug ) {
				$found_id = (int) $pid;
				break;
			}
		}
	}
	wp_reset_postdata();

	publion_cache_set( $cache_key, $found_id, 300 );
	return $found_id;
}

/**
 * Resolve post ID for a queue entry using meta, with a normalized-title fallback.
 *
 * @param object $entry Row from {$wpdb->publion_queue}.
 * @return int Post ID or 0.
 */
function publion_get_post_id_for_queue_entry( $entry ) {
	$queue_id = isset( $entry->id ) ? (int) $entry->id : 0;

	// Prefer meta (reliable).
	if ( $queue_id ) {
		$ids = get_posts( array(
			'post_type'              => 'post',
			'post_status'            => 'any',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'meta_key'               => '_publion_queue_id',
			'meta_value'             => $queue_id,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
		) );
		if ( ! empty( $ids ) ) {
			return (int) $ids[0];
		}
	}

	// Fallback for older posts created before we added meta.
	$title = publion_normalize_title( $entry->topic ?? '' );
	if ( '' === $title ) {
		return 0;
	}
	return (int) publion_get_post_id_by_exact_title( $title );
}

/* ===== Get Topic Suggestions ===== */
add_action( 'wp_ajax_publion_get_topics', 'publion_get_topics_callback' );
function publion_get_topics_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Niet geautoriseerd' );
	}

	$category_id   = intval( $_POST['category'] ?? 0 );
	$category_term = get_term( $category_id, 'category' );
	$category      = ( $category_term && ! is_wp_error( $category_term ) ) ? $category_term->name : 'Onbekende categorie';

	if ( ! $category ) {
		wp_send_json_error( 'Geen categorie opgegeven' );
	}

	$api_key = get_option( 'publion_api_key' );
	if ( ! $api_key ) {
		wp_send_json_error( 'API-sleutel ontbreekt' );
	}

	$default_prompt = "Je bent een expert in het schrijven van blogs en maakt hoogwaardige, SEO-geoptimaliseerde content voor [JOUW BEDRIJFSNAAM (INDIEN VAN TOEPASSING) EN WEBSITE-URL], [WAT JOUW BEDRIJF/WEBSITE BIEDT]. Het doel is [JOUW BEDRIJFS/WEBSITE-DOELEN]. Stem de toon af op het merk: [DE TOON DIE JE WILT UITSTRALEN - voorbeeld: professioneel maar benaderbaar, deskundig maar eenvoudig uit te leggen]. Elk onderwerp moet de missie van [JOUW BEDRIJFS/WEBSITE-NAAM] weerspiegelen om [BEDRIJVEN of MENSEN] te helpen met [HOE JE BEDRIJVEN of MENSEN HELPT]. (Vervang deze prompt door je eigen tekst om je doelen beter te weerspiegelen.)";
	$pre_prompt     = get_option( 'publion_prompt', $default_prompt );

	global $wpdb;
	publion_register_table_on_wpdb();

	// Cached previous topics for this category.
	$used_key    = 'used_topics_' . md5( $category );
	$used_topics = publion_db_get_col_cached(
		"SELECT topic FROM {$wpdb->publion_queue} WHERE category_label = %s",
		array( $category ),
		$used_key
	);

	$avoid_section = '';
	if ( ! empty( $used_topics ) ) {
		$list          = '- ' . implode( "\n- ", array_map( 'trim', $used_topics ) );
		$avoid_section = "\n\nVermijd herhaling of suggesties die te veel lijken op deze eerder gebruikte onderwerpen:\n" . $list;
	}

	$full_prompt  = $pre_prompt . "\n\nOp basis van de categorie \"" . $category . "\", stel 5 hoogwaardige blogonderwerpen voor die relevant, boeiend en nuttig zijn." . $avoid_section;
	$full_prompt .= "\n\nGenereer alleen onderwerpen die duidelijk gerelateerd zijn aan de gekozen categorie. Vermijd ongerelateerde gebieden zoals design, development of andere branches. Focus op onderwerpen die interessant zijn voor een publiek dat zoekt naar content over \"" . $category . "\".";
	$full_prompt .= "\n\nZorg dat elk onderwerp direct gekoppeld is aan \"" . $category . "\" en vermijd brede of zijdelingse onderwerpen.";
	$full_prompt .= "\n\nFormaat: geef exact 5 onderwerpen, elk op een eigen regel. Geen inleiding, geen bullets, geen nummers, geen Markdown, geen extra uitleg. Alleen de onderwerpregel zelf.";

	$model = publion_get_openai_model();
	$response = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		[
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode(
				[
					'model'       => $model,
					'messages'    => [
						[ 'role' => 'system', 'content' => 'Je bent een behulpzame assistent.' ],
						[ 'role' => 'user', 'content' => $full_prompt ],
					],
					'temperature' => 0.7,
					'max_tokens'  => 500,
				]
			),
			'timeout' => 20,
		]
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( 'API-aanvraag mislukt.' );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$text = $body['choices'][0]['message']['content'] ?? '';

	if ( ! $text ) {
		wp_send_json_error( 'Geen antwoord van ChatGPT.' );
	}

	$lines = preg_split( '/\r\n|\n|\r/', trim( $text ) );
	$topics = [];
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}

		// Strip markdown/bullets/numbering.
		$line = preg_replace( '/^\s*[-*•]+\s*/', '', $line );
		$line = preg_replace( '/^\s*\d+[\.\)]\s*/', '', $line );
		$line = str_replace( '**', '', $line );
		$line = trim( $line );

		// Filter out meta lines or explanations.
		$lower = mb_strtolower( $line );
		if ( str_starts_with( $lower, 'natuurlijk' ) || str_starts_with( $lower, 'hier' ) ) {
			continue;
		}
		if ( str_contains( $lower, 'onderwerpen' ) || str_contains( $lower, 'categorie' ) ) {
			continue;
		}
		if ( str_starts_with( $line, '-' ) ) {
			continue;
		}
		if ( '' === $line ) {
			continue;
		}

		$topics[] = $line;
		if ( count( $topics ) >= 5 ) {
			break;
		}
	}

	$topics = array_slice( array_values( $topics ), 0, 5 );

	wp_send_json_success( $topics );
}

/* ===== Save Queue ===== */
add_action( 'wp_ajax_publion_save_queue', 'publion_save_queue' );
function publion_save_queue() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Geen toestemming' );
	}

	global $wpdb;
	publion_register_table_on_wpdb();

	$queue_raw = filter_input( INPUT_POST, 'queue', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
	if ( ! is_array( $queue_raw ) ) {
		$queue_raw = [];
	}

	$queue = [];
	$seen  = [];
	foreach ( $queue_raw as $item_raw ) {
		$topic_raw          = isset( $item_raw['topic'] ) ? (string) $item_raw['topic'] : '';
		$topic_raw          = str_replace( '\"', '', $topic_raw );
		$category_id_raw    = isset( $item_raw['category'] ) ? $item_raw['category'] : 0;
		$category_label_raw = isset( $item_raw['categoryLabel'] ) ? (string) $item_raw['categoryLabel'] : '';

		$topic_clean = sanitize_text_field( $topic_raw );
		$category_id = intval( $category_id_raw );
		$key = mb_strtolower( $topic_clean ) . '|' . $category_id;
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;

		$queue[] = [
			'topic'          => $topic_clean,
			'category_id'    => $category_id,
			'category_label' => sanitize_text_field( $category_label_raw ),
		];
	}

	foreach ( $queue as $item ) {
		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->publion_queue} WHERE topic = %s AND category_id = %d",
				$item['topic'],
				$item['category_id']
			)
		);
		if ( $exists ) {
			continue;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->publion_queue,
			[
				'topic'          => $item['topic'],
				'category_id'    => $item['category_id'],
				'category_label' => $item['category_label'],
				'status'         => 'pending',
				'created_at'     => current_time( 'mysql' ),
			]
		);

		// Invalidate caches affected by this write.
		publion_cache_delete( 'used_topics_' . md5( $item['category_label'] ) );
		publion_cache_delete( 'pending_ids' );
		publion_cache_delete( 'pending_total' );
	}
	wp_send_json_success( [ 'count' => count( $queue ) ] );
}

/* ===== Save Post Settings ===== */
add_action( 'wp_ajax_publion_save_post_settings', 'publion_save_post_settings_callback' );
function publion_save_post_settings_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Niet geautoriseerd' );
	}

	$time_frame_days    = isset( $_POST['time_frame_days'] ) ? intval( wp_unslash( $_POST['time_frame_days'] ) ) : 7;
	$post_status        = sanitize_text_field( wp_unslash( $_POST['post_status'] ?? 'draft' ) );
	$cta_enabled_raw    = sanitize_text_field( wp_unslash( $_POST['cta_enabled'] ?? 'no' ) );
	$cta_text           = sanitize_text_field( wp_unslash( $_POST['cta_text'] ?? '' ) );
	$cta_link           = esc_url_raw( wp_unslash( $_POST['cta_link'] ?? '' ) );
	$notification_email = sanitize_email( wp_unslash( $_POST['notification_email'] ?? '' ) );
	$hide_title         = ( isset( $_POST['hide_title'] ) && 'yes' === $_POST['hide_title'] ) ? 'yes' : 'no';
	$auto_daily_topic   = ( isset( $_POST['auto_daily_topic'] ) && 'yes' === $_POST['auto_daily_topic'] ) ? 'yes' : 'no';
	$rank_math_integration = ( isset( $_POST['rank_math_integration'] ) && 'yes' === $_POST['rank_math_integration'] ) ? 'yes' : 'no';
	$preferred_external_domain = sanitize_text_field( wp_unslash( $_POST['preferred_external_domain'] ?? '' ) );
	$preferred_external_urls   = wp_kses_post( wp_unslash( $_POST['preferred_external_urls'] ?? '' ) );

	$settings = [
		'time_frame_days'    => $time_frame_days,
		'post_status'        => $post_status,
		'cta_enabled'        => ( 'yes' === $cta_enabled_raw ? 'yes' : 'no' ),
		'cta_text'           => $cta_text,
		'cta_link'           => $cta_link,
		'notification_email' => $notification_email,
		'hide_title'         => $hide_title,
		'auto_daily_topic'   => $auto_daily_topic,
		'rank_math_integration' => $rank_math_integration,
		'preferred_external_domain' => $preferred_external_domain,
		'preferred_external_urls'   => $preferred_external_urls,
	];

	update_option( 'publion_post_settings', $settings );

	wp_send_json_success( [ 'message' => 'Instellingen succesvol opgeslagen.' ] );
}

/* ===== Save API Key ===== */
add_action( 'wp_ajax_publion_save_api_key', 'publion_save_api_key_callback' );
function publion_save_api_key_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Niet geautoriseerd' );
	}

	$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
	update_option( 'publion_api_key', $api_key );

	wp_send_json_success( [ 'message' => 'API-sleutel opgeslagen.' ] );
}

/* ===== Save Model ===== */
add_action( 'wp_ajax_publion_save_model', 'publion_save_model_callback' );
function publion_save_model_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Niet geautoriseerd' );
	}

	$model  = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
	$models = publion_get_allowed_openai_models();

	if ( empty( $model ) || ! isset( $models[ $model ] ) ) {
		wp_send_json_error( 'Ongeldige modelkeuze.' );
	}

	update_option( 'publion_openai_model', $model );

	wp_send_json_success( [ 'message' => 'Model opgeslagen.' ] );
}

/* ===== Save Prompt ===== */
add_action( 'wp_ajax_publion_save_prompt', 'publion_save_prompt_callback' );
function publion_save_prompt_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Niet geautoriseerd' );
	}

	$prompt = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
	update_option( 'publion_prompt', $prompt );

	wp_send_json_success( [ 'message' => 'Prompt opgeslagen.' ] );
}

/* ===== Load Pending Queue Entries ===== */
add_action( 'wp_ajax_publion_load_queue_entries', 'publion_load_queue_entries_callback' );
function publion_load_queue_entries_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}

	global $wpdb;
	publion_register_table_on_wpdb();

	$offset = max( 0, intval( $_POST['offset'] ?? 0 ) );
	$limit  = max( 1, intval( $_POST['limit'] ?? 10 ) );

	// Pending entries page (cached).
	$page_key = 'pending_page_' . $offset . '_' . $limit;
	$entries  = publion_db_get_results_cached(
		"SELECT * FROM {$wpdb->publion_queue} WHERE status = %s ORDER BY id ASC LIMIT %d OFFSET %d",
		array( 'pending', $limit, $offset ),
		$page_key
	);

	// Global ordered list of pending IDs for stable position across pages.
	$ids_key = 'pending_ids';
	$all_ids = publion_db_get_col_cached(
		"SELECT id FROM {$wpdb->publion_queue} WHERE status = %s ORDER BY id ASC",
		array( 'pending' ),
		$ids_key
	);

	$row_html = '';

	$settings           = get_option( 'publion_post_settings', [] );
	$post_creation_days = max( 1, (int) ( $settings['time_frame_days'] ?? 3 ) );

	$tz       = wp_timezone();
	$now_dt   = new DateTimeImmutable( 'now', $tz );
	$today_dt = $now_dt->setTime( 0, 0, 0 );

	$last_created_at = get_option( 'publion_last_post_created_at' );
	$last_created_ts = $last_created_at ? publion_mysql_to_timestamp( $last_created_at ) : false;

	// Determine the first scheduled *date* (midnight) using site timezone.
	if ( false !== $last_created_ts ) {
		$last_dt          = ( new DateTimeImmutable( '@' . $last_created_ts ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
		$candidate_first  = $last_dt->modify( '+' . $post_creation_days . ' days' );

		if ( $candidate_first < $today_dt ) {
			// Last + frame is in the past → first is today (0 days).
			$first_scheduled_date = $today_dt;
		} elseif ( $candidate_first == $today_dt ) {
			// Last + frame is today → push to today + frame (e.g., shows 1 when frame=1).
			$first_scheduled_date = $today_dt->modify( '+' . $post_creation_days . ' days' );
		} else {
			// Future date already.
			$first_scheduled_date = $candidate_first;
		}
	} else {
		// No last-created yet → start today.
		$first_scheduled_date = $today_dt;
	}

	foreach ( $entries as $entry ) {
		$row_html .= '<tr>';

		// Actions column (create/delete for pending).
		$row_html .= '<td style="text-align: center; white-space: nowrap; padding: 0;">';
		$row_html .= '<button class="publion-create-now button button-primary" data-id="' . esc_attr( $entry->id ) . '" style="font-size:12px; padding:0 4px 2px 4px; margin:2px; line-height:1em; height:auto; vertical-align:middle; border-width:0px;"><span class="button-text">Nu maken</span></button>';
		$row_html .= '<span class="publion-create-spinner spinner" style="float:none; margin-left: 6px; display:none;"></span>';
		$row_html .= '<button class="publion-delete button" data-id="' . esc_attr( $entry->id ) . '" style="background-color:#cc0000; color:#fff; font-size:12px; padding:0 4px 2px 4px; margin:2px; line-height:1em; height:auto; vertical-align:middle; border-width:0px;">Verwijderen</button>';
		$row_html .= '</td>';

		// Topic and category.
		$row_html .= '<td>' . stripslashes( esc_html( $entry->topic ) ) . '</td>';
		$row_html .= '<td style="text-align: center;">' . esc_html( $entry->category_label ) . '</td>';

		// Position within the full pending list.
		$entry_position = array_search( $entry->id, $all_ids, true );
		$entry_position = ( false === $entry_position ) ? 0 : (int) $entry_position;

		// Schedule date = first_scheduled_date + (position * frame_days).
		$scheduled_date = $first_scheduled_date->modify( '+' . ( $entry_position * $post_creation_days ) . ' days' );

		// Days until = date-only difference in site timezone.
		$days_until = (int) $today_dt->diff( $scheduled_date )->days;

		$row_html .= '<td style="text-align: center;">' . esc_html( (string) $days_until ) . '</td>';

		// Created at (display only).
		$created_ts           = publion_mysql_to_timestamp( $entry->created_at );
		$formatted_created_at = ( $created_ts ) ? wp_date( 'M d, Y g:ia', $created_ts ) : '';
		$row_html .= '<td style="text-align: center;">' . esc_html( $formatted_created_at ) . '</td>';

		$row_html .= '</tr>';
	}

	// Total pending (cached).
	$total = (int) publion_db_get_var_cached(
		"SELECT COUNT(*) FROM {$wpdb->publion_queue} WHERE status = %s",
		array( 'pending' ),
		'pending_total'
	);
	$has_more = ( $offset + $limit ) < $total;

	wp_send_json_success(
		array(
			'rows'     => $row_html,
			'has_more' => $has_more,
		)
	);
}

/* ===== Load Created/Published Posts ===== */
add_action( 'wp_ajax_publion_load_created_posts', 'publion_load_created_posts_callback' );
function publion_load_created_posts_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}

	global $wpdb;
	publion_register_table_on_wpdb();

	$offset = max( 0, intval( $_POST['offset'] ?? 0 ) );
	$limit  = max( 1, intval( $_POST['limit'] ?? 10 ) );

	$page_key = 'created_page_' . $offset . '_' . $limit;
	$entries  = publion_db_get_results_cached(
		"SELECT * FROM {$wpdb->publion_queue} WHERE status IN (%s,%s) ORDER BY post_created_at DESC, id DESC LIMIT %d OFFSET %d",
		array( 'created', 'published', $limit, $offset ),
		$page_key
	);

	$row_html = '';

	foreach ( $entries as $entry ) {
		$row_html .= '<tr>';

		// ACTIONS COLUMN.
		$row_html .= '<td style="text-align:center; white-space:nowrap; padding:0;">';

		// Use reliable meta-based resolver with normalized-title fallback.
		$post_id = publion_get_post_id_for_queue_entry( $entry );

		// If post has been published manually, update status if needed.
		if ( $post_id ) {
			$actual_status = get_post_status( $post_id );
			if ( 'publish' === $actual_status && 'published' !== $entry->status ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->publion_queue,
					[
						'status'       => 'published',
						'published_at' => get_post_field( 'post_date', $post_id ),
					],
					[ 'id' => $entry->id ]
				);
				$entry->status       = 'published';
				$entry->published_at = get_post_field( 'post_date', $post_id );

				// Invalidate caches affected by this write.
				publion_cache_delete( $page_key );
				publion_cache_delete( 'created_total' );
			}
		}

		if ( $post_id ) {
			if ( 'published' === $entry->status ) {
				$row_html .= '<a href="' . esc_url( get_permalink( $post_id ) ) . '" target="_blank" class="button button-primary" style="font-size:12px; padding:2px 4px 0 4px; margin:2px; height:auto;">Bekijk post</a>';
			}
			$row_html .= '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '" target="_blank" class="button" style="background:#fff; font-size:12px; padding:2px 4px 0 4px; margin:2px; height:auto;">Post bewerken</a>';
		} else {
			$row_html .= '<span style="font-size:12px; color:#888;">Post niet gevonden</span>';
			$row_html .= '<br><button class="button publion-delete" data-id="' . esc_attr( $entry->id ) . '" style="margin-bottom:4px;background-color:#cc0000;color:#fff;border-width:0px;" onclick="try{localStorage.setItem(\'publion_active_tab\',\'publion-queue\'); setTimeout(function(){location.reload();}, 1000);}catch(e){}">Verwijderen</button>';
		}

		$row_html .= '</td>';

		// OTHER COLUMNS.
		$row_html .= '<td>' . stripslashes( esc_html( $entry->topic ) ) . '</td>';
		$row_html .= '<td style="text-align:center;">' . esc_html( $entry->category_label ) . '</td>';

		$created_ts      = $entry->created_at ? publion_mysql_to_timestamp( $entry->created_at ) : 0;
		$post_created_ts = $entry->post_created_at ? publion_mysql_to_timestamp( $entry->post_created_at ) : 0;
		$published_ts    = $entry->published_at ? publion_mysql_to_timestamp( $entry->published_at ) : 0;

		$row_html .= '<td style="text-align:center;">' . esc_html( $created_ts ? wp_date( 'M d, Y g:ia', $created_ts ) : '' ) . '</td>';
		$row_html .= '<td style="text-align:center;">' . esc_html( $post_created_ts ? wp_date( 'M d, Y g:ia', $post_created_ts ) : '-' ) . '</td>';
		$row_html .= '<td style="text-align:center;">' . ( $published_ts ? esc_html( wp_date( 'M d, Y g:ia', $published_ts ) ) : 'Niet gepubliceerd' ) . '</td>';

		$row_html .= '</tr>';
	}

	// Total created/published (cached).
	$total = (int) publion_db_get_var_cached(
		"SELECT COUNT(*) FROM {$wpdb->publion_queue} WHERE status IN (%s,%s)",
		array( 'created', 'published' ),
		'created_total'
	);
	$has_more = ( $offset + $limit ) < $total;

	wp_send_json_success(
		[
			'rows'     => $row_html,
			'has_more' => $has_more,
		]
	);
}

/* ===== Create Post Now ===== */
add_action( 'wp_ajax_publion_create_post_now', 'publion_create_post_now' );
function publion_create_post_now() {
	// Back-compat nonce handling.
	if ( isset( $_POST['nonce'] ) ) {
		check_ajax_referer( 'publion_nonce', 'nonce' );
	} elseif ( isset( $_POST['_ajax_nonce'] ) ) {
		check_ajax_referer( 'publion_nonce' );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Niet geautoriseerd' );
	}

	global $wpdb;
	publion_register_table_on_wpdb();

	$topic_id = intval( $_POST['id'] ?? 0 );
	if ( ! $topic_id ) {
		wp_send_json_error( 'Ongeldige onderwerp-ID' );
	}

	// Topic row (cached by id).
	$topic = publion_db_get_row_cached(
		"SELECT * FROM {$wpdb->publion_queue} WHERE id = %d",
		array( $topic_id ),
		'queue_row_' . $topic_id
	);
	if ( ! $topic ) {
		wp_send_json_error( 'Onderwerp niet gevonden' );
	}

	$settings    = get_option( 'publion_post_settings', [] );
	$post_status = sanitize_key( $settings['post_status'] ?? 'draft' );

	$add_cta  = ( isset( $settings['cta_enabled'] ) && 'yes' === $settings['cta_enabled'] );
	$cta_text = sanitize_text_field( $settings['cta_text'] ?? '' );
	$cta_link = esc_url_raw( $settings['cta_link'] ?? '' );

	$post_html = publion_generate_chatgpt_html( $topic->topic, $topic->category_label );
	if ( is_wp_error( $post_html ) || ! $post_html ) {
		wp_send_json_error( 'Genereren van content mislukt.' );
	}

	// Generate 6 context-aware AI images based on nearby text.
	$category      = get_term( (int) $topic->category_id, 'category' );
	$category_name = ( $category && ! is_wp_error( $category ) ) ? (string) $category->name : '';
	$api_key       = get_option( 'publion_api_key', '' );

	// Placeholder in /includes/images/ (plugin root).
	$plugin_root_url = plugin_dir_url( dirname( __FILE__ ) );
	$placeholder     = trailingslashit( $plugin_root_url ) . 'includes/images/image-placeholder.jpg';

	$prompts = publion_generate_contextual_image_prompts( $post_html, $topic->topic, $category_name );
	$image_ids        = [];
	$final_image_urls = [];

	foreach ( $prompts as $item ) {
		$prompt_text = $item['prompt'] ?? '';
		$context     = $item['context'] ?? $topic->topic;
		$image_result = publion_generate_and_upload_images( $prompt_text, 1, $context, $api_key );
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
	}

	// Insert 5 images into content.
	$post_html = publion_insert_images_into_content( $post_html, array_slice( $final_image_urls, 0, 5 ) );

	// Optional CTA (safe quoting).
	if ( $add_cta && $cta_text && $cta_link ) {
		$post_html .= '<div style="clear:both; padding-top:20px; margin-top:30px; border-top:1px solid #ccc;">'
			. '<p>Hulp nodig bij <strong>' . esc_html( $topic->topic ) . '</strong>? '
			. '<a href="' . esc_url( $cta_link ) . '" target="_blank">' . esc_html( $cta_text ) . '</a></p>'
			. '</div>';
	}

	// Create post.
	$post_id = wp_insert_post(
		[
			'post_title'    => wp_strip_all_tags( $topic->topic ),
			'post_content'  => $post_html,
			'post_status'   => $post_status,
			'post_category' => [ (int) $topic->category_id ],
			'post_type'     => 'post',
		],
		true
	);

	if ( is_wp_error( $post_id ) ) {
		wp_send_json_error( 'Post aanmaken mislukt: ' . $post_id->get_error_message() );
	}

	// Link the post back to the queue row for reliable lookups later.
	add_post_meta( $post_id, '_publion_queue_id', (int) $topic_id, true );

	$rank_math_enabled = ( ( $settings['rank_math_integration'] ?? 'no' ) === 'yes' );
	if ( $rank_math_enabled ) {
		update_post_meta( (int) $post_id, 'rank_math_focus_keyword', $topic->topic );
		update_post_meta( (int) $post_id, 'rank_math_title', $topic->topic );
		$excerpt = wp_strip_all_tags( $post_html );
		$excerpt = preg_replace( '/\s+/', ' ', $excerpt );
		$excerpt = trim( $excerpt );
		$excerpt = mb_substr( $excerpt, 0, 160 );
		update_post_meta( (int) $post_id, 'rank_math_description', $excerpt );
	}

	// Featured image: prefer 6th slot; use core helper to resolve attachment ID.
	if ( isset( $final_image_urls[5] ) && $final_image_urls[5] && $final_image_urls[5] !== $placeholder ) {
		$attachment_id = 0;
		if ( isset( $image_ids[5] ) ) {
			$attachment_id = (int) $image_ids[5];
		} else {
			$maybe_id = attachment_url_to_postid( $final_image_urls[5] );
			if ( $maybe_id ) {
				$attachment_id = (int) $maybe_id;
			}
		}
		if ( $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	$now_mysql = current_time( 'mysql' );

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->publion_queue,
		[
			'status'          => ( 'publish' === $post_status ? 'published' : 'created' ),
			'post_created_at' => $now_mysql,
			'published_at'    => ( 'publish' === $post_status ? $now_mysql : null ),
		],
		[ 'id' => $topic_id ]
	);

	// Invalidate caches impacted by this write.
	publion_cache_delete( 'pending_ids' );
	publion_cache_delete( 'pending_total' );
	publion_cache_delete( 'created_total' );
	publion_cache_delete( 'queue_row_' . $topic_id );
	foreach ( [ 'pending_page_', 'created_page_' ] as $prefix ) {
		for ( $i = 0; $i <= 50; $i += 10 ) {
			publion_cache_delete( $prefix . $i . '_10' );
		}
	}

	update_option( 'publion_last_post_created_at', $now_mysql );

	wp_send_json_success( [ 'message' => 'Post succesvol aangemaakt' ] );
}

/* ===== Utilities ===== */
function publion_get_root_category_name( $category_id ) {
	$cat = get_category( (int) $category_id );
	while ( $cat && $cat->parent ) {
		$cat = get_category( (int) $cat->parent );
	}
	return $cat ? (string) $cat->name : '';
}

/* ===== Delete Topic ===== */
add_action( 'wp_ajax_publion_delete_topic', 'publion_delete_topic_callback' );
function publion_delete_topic_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Niet geautoriseerd' );
	}

	$topic_id = intval( $_POST['id'] ?? 0 );
	if ( ! $topic_id ) {
		wp_send_json_error( 'Ongeldige onderwerp-ID' );
	}

	global $wpdb;
	publion_register_table_on_wpdb();

	// Fetch row (cached) to invalidate correct caches before delete.
	$entry = publion_db_get_row_cached(
		"SELECT * FROM {$wpdb->publion_queue} WHERE id = %d",
		array( $topic_id ),
		'queue_row_' . $topic_id
	);

	$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->publion_queue,
		[ 'id' => $topic_id ],
		[ '%d' ]
	);

	if ( false === $deleted ) {
		wp_send_json_error( 'Verwijderen van onderwerp mislukt' );
	}

	// Invalidate caches impacted by this write.
	publion_cache_delete( 'pending_ids' );
	publion_cache_delete( 'pending_total' );
	publion_cache_delete( 'created_total' );
	publion_cache_delete( 'queue_row_' . $topic_id );
	if ( $entry && ! empty( $entry->category_label ) ) {
		publion_cache_delete( 'used_topics_' . md5( (string) $entry->category_label ) );
	}
	foreach ( [ 'pending_page_', 'created_page_' ] as $prefix ) {
		for ( $i = 0; $i <= 50; $i += 10 ) {
			publion_cache_delete( $prefix . $i . '_10' );
		}
	}

	wp_send_json_success( [ 'message' => 'Onderwerp verwijderd' ] );
}
