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

/** A shared lock prevents the same topic from running in parallel. */
function publion_generation_lock_option_name( $topic ) {
	return 'publion_gen_lock_' . substr( md5( publion_normalize_title( $topic ) ), 0, 28 );
}

function publion_acquire_generation_lock( $topic_id, $topic ) {
	$option_name = publion_generation_lock_option_name( $topic );
	$now         = time();
	$existing    = get_option( $option_name, false );
	if ( is_array( $existing ) && ! empty( $existing['started_at'] ) && ( $now - (int) $existing['started_at'] ) > 1800 ) {
		delete_option( $option_name );
		$existing = false;
	}
	if ( false !== $existing ) {
		return false;
	}

	return add_option(
		$option_name,
		array( 'topic_id' => absint( $topic_id ), 'started_at' => $now ),
		'',
		'no'
	);
}

function publion_release_generation_lock( $topic_id, $topic ) {
	$option_name = publion_generation_lock_option_name( $topic );
	$existing    = get_option( $option_name, false );
	if ( is_array( $existing ) && absint( $existing['topic_id'] ?? 0 ) === absint( $topic_id ) ) {
		delete_option( $option_name );
	}
}

/** Recover only genuinely abandoned work after thirty minutes. */
function publion_release_stale_processing_entries() {
	global $wpdb;
	publion_register_table_on_wpdb();
	$cutoff = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 30 * MINUTE_IN_SECONDS ) );
	$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"UPDATE {$wpdb->publion_queue} SET status = %s, processing_started_at = NULL WHERE status = %s AND (processing_started_at IS NULL OR processing_started_at < %s)",
			'pending',
			'processing',
			$cutoff
		)
	);
}

/** Atomically claim one pending queue entry before any AI request begins. */
function publion_claim_queue_entry( $topic_id, $topic ) {
	global $wpdb;
	publion_register_table_on_wpdb();
	publion_release_stale_processing_entries();
	$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"UPDATE {$wpdb->publion_queue} SET status = %s, processing_started_at = %s WHERE id = %d AND status = %s AND post_created_at IS NULL",
			'processing',
			current_time( 'mysql' ),
			absint( $topic_id ),
			'pending'
		)
	);
	if ( 1 !== (int) $claimed ) {
		return false;
	}
	if ( publion_acquire_generation_lock( $topic_id, $topic ) ) {
		return true;
	}

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->publion_queue,
		array( 'status' => 'pending', 'processing_started_at' => null ),
		array( 'id' => absint( $topic_id ), 'status' => 'processing' ),
		array( '%s', '%s' ),
		array( '%d', '%s' )
	);
	return false;
}

function publion_release_queue_claim( $topic_id, $topic, $status = 'pending' ) {
	global $wpdb;
	publion_register_table_on_wpdb();
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->publion_queue,
		array( 'status' => sanitize_key( $status ), 'processing_started_at' => null ),
		array( 'id' => absint( $topic_id ), 'status' => 'processing' ),
		array( '%s', '%s' ),
		array( '%d', '%s' )
	);
	publion_release_generation_lock( $topic_id, $topic );
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

/**
 * Build a safe, action-oriented AJAX error. Raw provider and database errors
 * stay in the server log; editors get the failing step and a useful next move.
 */
function publion_build_error_payload( $code, $message, $overrides = array() ) {
	$code    = sanitize_key( $code ?: 'operation_failed' );
	$message = wp_strip_all_tags( (string) $message );
	$message = preg_replace( '/\bsk-[A-Za-z0-9_-]+\b/', '[redacted API key]', $message );
	$message = trim( wp_html_excerpt( $message, 480, '…' ) );

	$defaults = array(
		'code'         => $code,
		'title'        => __( 'Deze actie kon niet worden voltooid.', 'publion' ),
		'message'      => $message ?: __( 'Er is geen aanvullende foutmelding beschikbaar.', 'publion' ),
		'next_step'    => __( 'Controleer de diagnose, ververs de pagina en probeer de actie daarna opnieuw.', 'publion' ),
		'action_label' => __( 'Open diagnose', 'publion' ),
		'action_tab'   => 'publion-help',
		'retryable'    => true,
		'reference'    => 'PUBLION-' . strtoupper( str_replace( '_', '-', $code ) ),
	);

	$guidance = array(
		'permission_denied' => array(
			'title'        => __( 'Je hebt hiervoor onvoldoende rechten.', 'publion' ),
			'next_step'    => __( 'Meld je aan met een WordPress-account dat berichten en Publion-instellingen mag beheren.', 'publion' ),
			'action_label' => __( 'Open diagnose', 'publion' ),
			'action_tab'   => 'publion-help',
			'retryable'    => false,
		),
		'api_key_missing' => array(
			'title'        => __( 'De OpenAI API-sleutel ontbreekt.', 'publion' ),
			'next_step'    => __( 'Voeg een geldige API-sleutel toe onder OpenAI/ChatGPT-instellingen en sla deze op.', 'publion' ),
			'action_label' => __( 'Open AI-instellingen', 'publion' ),
			'action_tab'   => 'publion-settings',
			'retryable'    => false,
		),
		'openai_auth' => array(
			'title'        => __( 'OpenAI heeft de aanvraag niet geautoriseerd.', 'publion' ),
			'next_step'    => __( 'Controleer de API-sleutel en het geselecteerde API-project. Maak zo nodig een nieuwe sleutel aan.', 'publion' ),
			'action_label' => __( 'Controleer API-sleutel', 'publion' ),
			'action_tab'   => 'publion-settings',
			'retryable'    => false,
		),
		'openai_model' => array(
			'title'        => __( 'Het gekozen OpenAI-model is niet beschikbaar.', 'publion' ),
			'next_step'    => __( 'Kies een beschikbaar tekst- of afbeeldingsmodel voor dit API-project en probeer daarna opnieuw.', 'publion' ),
			'action_label' => __( 'Controleer model', 'publion' ),
			'action_tab'   => 'publion-settings',
			'retryable'    => false,
		),
		'openai_limit' => array(
			'title'        => __( 'OpenAI heeft deze aanvraag tijdelijk beperkt.', 'publion' ),
			'next_step'    => __( 'Wacht kort, controleer tegoed en facturatie in OpenAI en probeer vervolgens één onderwerp opnieuw.', 'publion' ),
			'action_label' => __( 'Open AI-instellingen', 'publion' ),
			'action_tab'   => 'publion-settings',
			'retryable'    => true,
		),
		'network' => array(
			'title'        => __( 'De verbinding met een externe dienst is onderbroken.', 'publion' ),
			'next_step'    => __( 'Controleer de internetverbinding en firewall. Ververs daarna de wachtrij om te controleren of de post al is aangemaakt.', 'publion' ),
			'action_label' => __( 'Open wachtrij', 'publion' ),
			'action_tab'   => 'publion-queue',
			'retryable'    => true,
		),
		'content_generation' => array(
			'title'        => __( 'De artikelgeneratie is gestopt.', 'publion' ),
			'next_step'    => __( 'Lees de oorzaak hieronder. Controleer daarna de API-sleutel, het model en de Publion-prompt voordat je opnieuw start.', 'publion' ),
			'action_label' => __( 'Controleer AI-instellingen', 'publion' ),
			'action_tab'   => 'publion-settings',
			'retryable'    => true,
		),
		'duplicate_content' => array(
			'title'        => __( 'Dit onderwerp is overgeslagen.', 'publion' ),
			'next_step'    => __( 'De overige geselecteerde onderwerpen gaan gewoon door. Verwijder dit dubbele wachtrij-item of vervang het door een duidelijk andere zoekvraag.', 'publion' ),
			'action_label' => __( 'Open wachtrij', 'publion' ),
			'action_tab'   => 'publion-queue',
			'retryable'    => false,
		),
		'validation' => array(
			'title'        => __( 'De ingevoerde gegevens zijn niet volledig of ongeldig.', 'publion' ),
			'next_step'    => __( 'Controleer de gemarkeerde selectie of datum en voer de actie opnieuw uit.', 'publion' ),
			'action_label' => __( 'Open wachtrij', 'publion' ),
			'action_tab'   => 'publion-queue',
			'retryable'    => true,
		),
		'not_found' => array(
			'title'        => __( 'Dit wachtrij-item bestaat niet meer.', 'publion' ),
			'next_step'    => __( 'Ververs de wachtrij. Mogelijk is het item in een andere sessie verwijderd of al verwerkt.', 'publion' ),
			'action_label' => __( 'Ververs wachtrij', 'publion' ),
			'action_tab'   => 'publion-queue',
			'retryable'    => false,
		),
		'database' => array(
			'title'        => __( 'WordPress kon de wijziging niet opslaan.', 'publion' ),
			'next_step'    => __( 'Ververs de pagina en probeer opnieuw. Blijft dit gebeuren, controleer dan de databaseverbinding en serverlogs.', 'publion' ),
			'action_label' => __( 'Open diagnose', 'publion' ),
			'action_tab'   => 'publion-help',
			'retryable'    => true,
		),
	);

	$payload = array_merge( $defaults, $guidance[ $code ] ?? array(), is_array( $overrides ) ? $overrides : array() );
	$payload['message'] = $message ?: $payload['message'];
	// The reference shown to the editor also makes the matching server log easy
	// to find, while the message has already been stripped of API keys and HTML.
	error_log( '[Publion][' . $payload['reference'] . '] ' . $payload['message'] );
	return $payload;
}

function publion_send_error( $code, $message, $overrides = array() ) {
	wp_send_json_error( publion_build_error_payload( $code, $message, $overrides ) );
}

function publion_guess_error_code( $message, $fallback = 'operation_failed' ) {
	$haystack = strtolower( (string) $message );
	if ( false !== strpos( $haystack, 'api-sleutel' ) || false !== strpos( $haystack, 'unauthorized' ) || false !== strpos( $haystack, 'http 401' ) || false !== strpos( $haystack, 'http 403' ) ) {
		return 'openai_auth';
	}
	if ( false !== strpos( $haystack, 'model' ) ) {
		return 'openai_model';
	}
	if ( false !== strpos( $haystack, 'rate limit' ) || false !== strpos( $haystack, 'tijdelijk beperkt' ) || false !== strpos( $haystack, 'http 429' ) || false !== strpos( $haystack, 'tegoed' ) ) {
		return 'openai_limit';
	}
	if ( false !== strpos( $haystack, 'netwerk' ) || false !== strpos( $haystack, 'verbinding' ) || false !== strpos( $haystack, 'niet bereikbaar' ) || false !== strpos( $haystack, 'timeout' ) ) {
		return 'network';
	}
	return sanitize_key( $fallback );
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

function publion_datetime_from_mysql( $mysql_datetime, DateTimeZone $tz ) {
	$ts = publion_mysql_to_timestamp( $mysql_datetime );
	if ( ! $ts ) {
		return null;
	}
	return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
}

function publion_parse_time_string( $value, $default = '00:00' ) {
	$normalized = $default;
	$hour       = 0;
	$minute     = 0;

	if ( is_string( $value ) && preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $matches ) ) {
		$hour       = (int) $matches[1];
		$minute     = (int) $matches[2];
		$normalized = $matches[1] . ':' . $matches[2];
	} elseif ( is_string( $default ) && preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $default, $matches ) ) {
		$hour       = (int) $matches[1];
		$minute     = (int) $matches[2];
		$normalized = $matches[1] . ':' . $matches[2];
	}

	return array( $hour, $minute, $normalized );
}

function publion_get_post_creation_interval_days( $settings = null ) {
	if ( ! is_array( $settings ) ) {
		$settings = get_option( 'publion_post_settings', array() );
	}
	return max( 1, (int) ( $settings['time_frame_days'] ?? 3 ) );
}

function publion_get_post_creation_time( $settings = null ) {
	if ( ! is_array( $settings ) ) {
		$settings = get_option( 'publion_post_settings', array() );
	}
	$time = $settings['post_creation_time'] ?? '00:00';
	$parsed = publion_parse_time_string( $time, '00:00' );
	return $parsed[2];
}

function publion_get_daily_topic_interval_days( $settings = null ) {
	if ( ! is_array( $settings ) ) {
		$settings = get_option( 'publion_post_settings', array() );
	}
	return max( 1, (int) ( $settings['daily_topic_interval_days'] ?? 1 ) );
}

function publion_get_daily_topic_time( $settings = null ) {
	if ( ! is_array( $settings ) ) {
		$settings = get_option( 'publion_post_settings', array() );
	}
	$time = $settings['daily_topic_time'] ?? '00:00';
	$parsed = publion_parse_time_string( $time, '00:00' );
	return $parsed[2];
}

function publion_get_post_author_id( $settings = null, $fallback_mode = 'current' ) {
	if ( ! is_array( $settings ) ) {
		$settings = get_option( 'publion_post_settings', array() );
	}

	$author_id = isset( $settings['default_post_author'] ) ? (int) $settings['default_post_author'] : 0;
	if ( $author_id ) {
		$user = get_user_by( 'id', $author_id );
		if ( $user && user_can( $user, 'edit_posts' ) ) {
			return $author_id;
		}
	}

	if ( 'current' === $fallback_mode ) {
		$current_id = get_current_user_id();
		if ( $current_id ) {
			return $current_id;
		}
	}

	if ( 'first' === $fallback_mode ) {
		$users = get_users(
			array(
				'number'  => 20,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => array( 'ID' ),
			)
		);
		foreach ( $users as $user ) {
			if ( user_can( $user, 'edit_posts' ) ) {
				return (int) $user->ID;
			}
		}
	}

	return 0;
}

function publion_get_next_post_schedule_slot( $after_dt, $interval_days, $time_str, DateTimeZone $tz ) {
	$parsed = publion_parse_time_string( $time_str, '00:00' );
	$hour   = $parsed[0];
	$minute = $parsed[1];

	if ( ! $after_dt instanceof DateTimeImmutable ) {
		$now       = new DateTimeImmutable( 'now', $tz );
		$candidate = $now->setTime( $hour, $minute, 0 );
		if ( $candidate <= $now ) {
			$candidate = $candidate->modify( '+1 day' );
		}
		return $candidate;
	}

	$base_date = $after_dt->setTime( 0, 0, 0 );
	return $base_date->modify( '+' . (int) $interval_days . ' days' )->setTime( $hour, $minute, 0 );
}

function publion_invalidate_pending_cache() {
	publion_cache_delete( 'pending_ids' );
	publion_cache_delete( 'pending_total' );
	foreach ( array( 'pending_page_' ) as $prefix ) {
		for ( $i = 0; $i <= 50; $i += 10 ) {
			publion_cache_delete( $prefix . $i . '_10' );
		}
	}
}

function publion_schedule_pending_entries( $force_reschedule = false ) {
	global $wpdb;
	publion_register_table_on_wpdb();

	$settings      = get_option( 'publion_post_settings', array() );
	$interval_days = publion_get_post_creation_interval_days( $settings );
	$time_str      = publion_get_post_creation_time( $settings );
	$tz            = wp_timezone();

	$entries = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT id, scheduled_at, schedule_locked FROM {$wpdb->publion_queue} WHERE status = %s ORDER BY id ASC",
			'pending'
		)
	);

	if ( empty( $entries ) ) {
		return 0;
	}

	$last_created_at = get_option( 'publion_last_post_created_at' );
	$cursor_after    = $last_created_at ? publion_datetime_from_mysql( $last_created_at, $tz ) : null;
	$updated         = 0;

	foreach ( $entries as $entry ) {
		$locked       = ! empty( $entry->schedule_locked );
		$scheduled_dt = $entry->scheduled_at ? publion_datetime_from_mysql( $entry->scheduled_at, $tz ) : null;

		if ( $locked && $scheduled_dt ) {
			if ( ! $cursor_after || $scheduled_dt->getTimestamp() > $cursor_after->getTimestamp() ) {
				$cursor_after = $scheduled_dt;
			}
			continue;
		}

		if ( ! $force_reschedule && $scheduled_dt ) {
			if ( ! $cursor_after || $scheduled_dt->getTimestamp() > $cursor_after->getTimestamp() ) {
				$cursor_after = $scheduled_dt;
			}
			continue;
		}

		$next_slot = publion_get_next_post_schedule_slot( $cursor_after, $interval_days, $time_str, $tz );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->publion_queue,
			array(
				'scheduled_at'   => $next_slot->format( 'Y-m-d H:i:s' ),
				'schedule_locked' => 0,
			),
			array( 'id' => (int) $entry->id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		$updated++;
		$cursor_after = $next_slot;
	}

	if ( $updated ) {
		publion_invalidate_pending_cache();
	}

	return $updated;
}

function publion_calculate_initial_daily_topic_timestamp( $settings, DateTimeImmutable $now = null ) {
	$tz            = wp_timezone();
	$now           = $now ?: new DateTimeImmutable( 'now', $tz );
	$interval_days = publion_get_daily_topic_interval_days( $settings );
	$time_str      = publion_get_daily_topic_time( $settings );
	$parsed        = publion_parse_time_string( $time_str, '00:00' );

	$candidate = $now->setTime( $parsed[0], $parsed[1], 0 );
	if ( $candidate <= $now ) {
		$candidate = $candidate->modify( '+' . $interval_days . ' days' );
	}

	return $candidate->getTimestamp();
}

function publion_calculate_next_daily_topic_timestamp( DateTimeImmutable $from_dt, $settings ) {
	$interval_days = publion_get_daily_topic_interval_days( $settings );
	$time_str      = publion_get_daily_topic_time( $settings );
	$parsed        = publion_parse_time_string( $time_str, '00:00' );
	$base_date     = $from_dt->setTime( 0, 0, 0 );
	$next          = $base_date->modify( '+' . $interval_days . ' days' )->setTime( $parsed[0], $parsed[1], 0 );
	return $next->getTimestamp();
}

function publion_reschedule_daily_topic_event( $settings = null ) {
	if ( ! is_array( $settings ) ) {
		$settings = get_option( 'publion_post_settings', array() );
	}

	if ( ( $settings['auto_daily_topic'] ?? 'no' ) !== 'yes' ) {
		wp_clear_scheduled_hook( 'publion_daily_topic_hook' );
		return 0;
	}

	wp_clear_scheduled_hook( 'publion_daily_topic_hook' );
	$next_ts = publion_calculate_initial_daily_topic_timestamp( $settings );
	wp_schedule_single_event( $next_ts, 'publion_daily_topic_hook' );
	return $next_ts;
}

function publion_update_existing_posts_author( $author_id ) {
	$author_id = (int) $author_id;
	if ( ! $author_id ) {
		return 0;
	}

	$user = get_user_by( 'id', $author_id );
	if ( ! $user || ! user_can( $user, 'edit_posts' ) ) {
		return 0;
	}

	$posts = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'any',
			'posts_per_page'         => 200,
			'fields'                 => 'ids',
			'meta_key'               => '_publion_queue_id',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$updated = 0;
	foreach ( $posts as $post_id ) {
		$result = wp_update_post(
			array(
				'ID'          => (int) $post_id,
				'post_author' => $author_id,
			),
			true
		);
		if ( ! is_wp_error( $result ) ) {
			$updated++;
		}
	}

	return $updated;
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
/**
 * Convert one complete structured AI response into safe suggestion cards.
 *
 * A line-by-line fallback is deliberately not used. A cut-off JSON response
 * must never turn its JSON keys into queueable article titles.
 *
 * @param string $text          Raw model output.
 * @param bool   $is_valid_json Receives whether a complete expected JSON payload was received.
 * @return array<int, array<string, mixed>>
 */
function publion_normalize_seo_suggestions( $text, &$is_valid_json = false, $required_count = 5, $excluded_titles = array() ) {
	$is_valid_json = false;
	$text        = trim( (string) $text );
	$text        = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
	$text        = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
	$decoded     = json_decode( $text, true );
	// Some compatible custom models return a JSON payload as an escaped string.
	// Accept that only after a second complete JSON decode, never line-by-line.
	if ( is_string( $decoded ) ) {
		$decoded = json_decode( $decoded, true );
	}
	$suggestions = array();
	$items       = array();

	if ( is_array( $decoded ) && isset( $decoded['suggestions'] ) && is_array( $decoded['suggestions'] ) ) {
		$items         = $decoded['suggestions'];
	} elseif ( is_array( $decoded ) && ( empty( $decoded ) || array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) ) {
		// Keep legacy custom models compatible when they still return a top-level array.
		$items         = $decoded;
	}

	if ( ! is_array( $items ) || empty( $items ) ) {
		return array();
	}

	$required_count = max( 1, min( 5, (int) $required_count ) );
	$seen_titles    = array();
	$seen_focuses   = array();
	foreach ( (array) $excluded_titles as $excluded_title ) {
		$excluded_title = publion_normalize_title( $excluded_title );
		if ( '' !== $excluded_title ) {
			$seen_titles[ mb_strtolower( $excluded_title ) ] = true;
		}
	}
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$title = isset( $item['title'] ) ? publion_normalize_title( $item['title'] ) : '';
		if ( ! publion_is_safe_suggestion_title( $title ) ) {
			continue;
		}
		$title_key = mb_strtolower( $title );
		if ( isset( $seen_titles[ $title_key ] ) || ( function_exists( 'publion_find_existing_content_conflict' ) && publion_find_existing_content_conflict( $title ) ) ) {
			continue;
		}
		$seen_titles[ $title_key ] = true;

		$intent    = sanitize_key( $item['search_intent'] ?? '' );
		$intents   = array( 'informatief', 'commercieel', 'transactioneel', 'navigerend' );
		$focus     = isset( $item['focus_keyword'] ) ? publion_normalize_title( $item['focus_keyword'] ) : '';
		$angle     = isset( $item['angle'] ) ? sanitize_text_field( $item['angle'] ) : '';
		$questions = isset( $item['faq_questions'] ) && is_array( $item['faq_questions'] ) ? $item['faq_questions'] : array();
		$questions = array_values(
			array_filter(
				array_map(
					static function ( $question ) {
						$question = publion_normalize_title( $question );
						return mb_strlen( $question ) >= 12 && mb_strlen( $question ) <= 180 ? $question : '';
					},
					$questions
				)
			)
		);

		if ( '' === $focus || ! publion_is_safe_suggestion_title( $focus, 2 ) || ! in_array( $intent, $intents, true ) || mb_strlen( $angle ) < 20 || count( $questions ) < 3 ) {
			unset( $seen_titles[ $title_key ] );
			continue;
		}
		$focus_key = mb_strtolower( $focus );
		if ( isset( $seen_focuses[ $focus_key ] ) || ( function_exists( 'publion_rank_math_focus_keyword_in_use' ) && publion_rank_math_focus_keyword_in_use( $focus ) ) ) {
			unset( $seen_titles[ $title_key ] );
			continue;
		}
		$seen_focuses[ $focus_key ] = true;

		$suggestions[] = array(
			'title'         => $title,
			'focus_keyword' => $focus,
			'search_intent' => $intent,
			'angle'         => $angle,
			'faq_questions' => array_slice( $questions, 0, 4 ),
		);
		if ( count( $suggestions ) >= $required_count ) {
			break;
		}
	}

	// The caller receives valid partial cards so it can ask for replacements for
	// only the rejected subjects. It must still require the requested total
	// before rendering anything to the editor.
	$is_valid_json = ( $required_count === count( $suggestions ) );
	return $suggestions;
}

/**
 * Reject JSON fragments and unsafe title values before they can reach the UI,
 * queue table, or a future article creation request.
 */
function publion_is_safe_suggestion_title( $value, $minimum_length = 16 ) {
	$value = publion_normalize_title( $value );
	if ( mb_strlen( $value ) < max( 2, (int) $minimum_length ) || mb_strlen( $value ) > 140 || preg_match( '/[\r\n\[\]{}]/', $value ) ) {
		return false;
	}
	if ( preg_match( '/(?:^|\s|["\'])\b(?:title|focus_keyword|search_intent|angle|faq_questions)\b\s*:/i', $value ) ) {
		return false;
	}
	return ! preg_match( '/^\s*["\']?(?:title|focus_keyword|search_intent|angle|faq_questions)["\']?\s*[:;,]?\s*$/i', $value );
}

/**
 * JSON Schema used for topic proposals in Chat Completions Structured Outputs.
 *
 * @return array<string, mixed>
 */
function publion_get_topic_suggestions_response_format() {
	$item_schema = array(
		'type'                 => 'object',
		'properties'           => array(
			'title'         => array( 'type' => 'string' ),
			'focus_keyword' => array( 'type' => 'string' ),
			'search_intent' => array( 'type' => 'string', 'enum' => array( 'informatief', 'commercieel', 'transactioneel', 'navigerend' ) ),
			'angle'         => array( 'type' => 'string' ),
			'faq_questions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'minItems' => 3, 'maxItems' => 4 ),
		),
		'required'             => array( 'title', 'focus_keyword', 'search_intent', 'angle', 'faq_questions' ),
		'additionalProperties' => false,
	);

	return array(
		'type'        => 'json_schema',
		'json_schema' => array(
			'name'   => 'publion_topic_suggestions',
			'strict' => true,
			'schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'suggestions' => array( 'type' => 'array', 'items' => $item_schema, 'minItems' => 5, 'maxItems' => 5 ),
				),
				'required'             => array( 'suggestions' ),
				'additionalProperties' => false,
			),
		),
	);
}

add_action( 'wp_ajax_publion_get_topics', 'publion_get_topics_callback' );
function publion_get_topics_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		publion_send_error( 'permission_denied', __( 'Je account mag geen AI-onderwerpvoorstellen maken.', 'publion' ) );
	}

	$category_id   = intval( $_POST['category'] ?? 0 );
	$category_term = get_term( $category_id, 'category' );
	$category      = ( $category_term && ! is_wp_error( $category_term ) ) ? $category_term->name : 'Onbekende categorie';

	if ( ! $category ) {
		publion_send_error( 'validation', __( 'Kies eerst een geldige categorie voordat je onderwerpvoorstellen aanvraagt.', 'publion' ) );
	}

	$api_key = get_option( 'publion_api_key' );
	if ( ! $api_key ) {
		publion_send_error( 'api_key_missing', __( 'Er is geen OpenAI API-sleutel opgeslagen.', 'publion' ) );
	}

	$content_language = function_exists( 'publion_get_site_content_language' ) ? publion_get_site_content_language() : get_locale();

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

	$content_map = publion_get_existing_content_map();

	$full_prompt  = $pre_prompt . "\n\nOntwikkel 5 onderscheidende, behulpzame artikelkansen voor de categorie \"" . $category . "\"." . $avoid_section;
	$full_prompt .= " Alle zichtbare velden (title, focus_keyword, angle en faq_questions) moeten uitsluitend in $content_language staan. De technische waarde search_intent moet altijd één van deze exacte waarden blijven: informatief, commercieel, transactioneel of navigerend.";
	$full_prompt .= "\nKies onderwerpen met een duidelijke zoekvraag, niet alleen brede thema's. Vermijd keyword stuffing, vage titels, ongefundeerde claims en onderwerpen die niet echt bij de categorie passen. Denk vanuit organisch zoeken én citeerbaarheid in AI-antwoorden.";
	$full_prompt .= "\n\nLees eerst de onderstaande actuele contentkaart van alle " . (int) $content_map['count'] . " bestaande WordPress-berichten. Maak uitsluitend onderwerpen die een nieuwe zoekvraag of aantoonbaar andere invalshoek behandelen. Hergebruik geen bestaande titel, koppenstructuur, FAQ of centrale uitleg. Zet in angle expliciet wat dit onderwerp uniek maakt ten opzichte van de kaart.\n\n=== ACTUELE CONTENTKAART ===\n" . $content_map['context'] . "\n=== EINDE CONTENTKAART ===";
	$full_prompt .= "\n\nGeef exact één geldige JSON-array terug, zonder Markdown of toelichting. Elk object heeft uitsluitend: title (concrete titel), focus_keyword (één natuurlijke primaire zoekterm), search_intent (informatief, commercieel, transactioneel of navigerend), angle (de unieke praktische invalshoek) en faq_questions (array met 3 concrete vragen die lezers stellen).";

	$full_prompt .= "\n\nFORMAATCORRECTIE: negeer alle eerdere vermelding van een JSON-array. Geef exact één volledig JSON-object terug met uitsluitend de sleutel suggestions. Die sleutel bevat exact 5 volledige onderwerpobjecten. Geen Markdown en geen toelichting.";

	// Remove legacy contradictory format instructions before the model sees them.
	// The final contract below is the single source of truth for every model.
	$full_prompt = preg_replace( '/\n\nGeef exact.*$/us', '', $full_prompt );
	$full_prompt .= "\n\nGeef exact één volledig JSON-object terug, zonder Markdown, toelichting of andere tekst. Het object heeft uitsluitend de sleutel suggestions. suggestions bevat exact 5 volledige objecten met uitsluitend: title (concrete titel van 16-140 tekens), focus_keyword (één natuurlijke primaire zoekterm), search_intent (informatief, commercieel, transactioneel of navigerend), angle (minimaal één zin die de unieke praktische invalshoek uitlegt) en faq_questions (3 of 4 concrete lezersvragen).";

	$model = publion_get_openai_model();
	$request_body = publion_build_openai_chat_body(
		$model,
		array(
			array( 'role' => 'system', 'content' => 'Je bent een behulpzame assistent die altijd een volledig, strikt JSON-object teruggeeft.' ),
			array( 'role' => 'user', 'content' => $full_prompt ),
		),
		3200
	);
	$request_body['response_format'] = publion_get_topic_suggestions_response_format();
	$request_args = array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		),
		'body'    => wp_json_encode( $request_body ),
		'timeout' => 90,
	);
	$response = publion_openai_post( 'https://api.openai.com/v1/chat/completions', $request_args, 'topic_suggestions' );

	// A manually entered legacy model might not support Structured Outputs.
	// Keep JSON mode on in the compatibility retry: unstructured text must never
	// reach the proposal renderer.
	if ( ! is_wp_error( $response ) && 400 === (int) wp_remote_retrieve_response_code( $response ) ) {
		$request_body['response_format'] = array( 'type' => 'json_object' );
		$request_args['body'] = wp_json_encode( $request_body );
		$response = publion_openai_post( 'https://api.openai.com/v1/chat/completions', $request_args, 'topic_suggestions' );
	}

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		$error = publion_get_openai_request_error( $response, $model );
		update_option( 'publion_last_openai_error', $error );
		publion_send_error( publion_guess_error_code( $error, 'content_generation' ), $error );
	}

	$body   = json_decode( wp_remote_retrieve_body( $response ), true );
	$choice = $body['choices'][0] ?? array();
	$text   = $choice['message']['content'] ?? '';

	if ( 'length' === ( $choice['finish_reason'] ?? '' ) ) {
		$error = 'OpenAI bereikte de uitvoerlimiet voordat de onderwerpvoorstellen compleet waren. Er is niets toegevoegd; probeer opnieuw of kies een model met meer uitvoercapaciteit.';
		update_option( 'publion_last_openai_error', $error );
		publion_send_error( 'openai_limit', $error );
	}

	if ( ! $text ) {
		$error = 'OpenAI gaf geen onderwerpvoorstellen terug. Probeer een ander model of verkort de Publion-prompt.';
		update_option( 'publion_last_openai_error', $error );
		publion_send_error( 'content_generation', $error );
	}

	$is_valid_json = false;
	$suggestions   = publion_normalize_seo_suggestions( $text, $is_valid_json );
	if ( ! $is_valid_json && ! empty( $suggestions ) ) {
		$missing_count   = 5 - count( $suggestions );
		$accepted_titles = wp_list_pluck( $suggestions, 'title' );
		$retry_prompt    = $full_prompt . "\n\nHERSTELACTIE: " . count( $suggestions ) . " voorstel(len) zijn lokaal goedgekeurd. Geef nu 5 vervangende voorstellen die niet lijken op de bestaande contentkaart en ook niet op deze al goedgekeurde titels:\n- " . implode( "\n- ", $accepted_titles ) . "\nMinimaal " . $missing_count . " van je voorstellen moeten volledig nieuw en bruikbaar zijn.";
		$retry_body      = $request_body;
		$retry_body['messages'][1]['content'] = $retry_prompt;
		$retry_args      = $request_args;
		$retry_args['body'] = wp_json_encode( $retry_body );
		$retry_response  = publion_openai_post( 'https://api.openai.com/v1/chat/completions', $retry_args, 'topic_suggestions_repair' );

		if ( ! is_wp_error( $retry_response ) && 200 === (int) wp_remote_retrieve_response_code( $retry_response ) ) {
			$retry_data     = json_decode( wp_remote_retrieve_body( $retry_response ), true );
			$retry_choice   = $retry_data['choices'][0] ?? array();
			$retry_text     = $retry_choice['message']['content'] ?? '';
			$retry_valid    = false;
			$replacements   = publion_normalize_seo_suggestions( $retry_text, $retry_valid, $missing_count, $accepted_titles );
			if ( $retry_valid ) {
				$suggestions   = array_merge( $suggestions, $replacements );
				$is_valid_json = ( 5 === count( $suggestions ) );
			}
		}
	}
	if ( ! $is_valid_json ) {
		$error = 'OpenAI gaf geen volledig geldig JSON-antwoord voor de onderwerpvoorstellen. Er is niets toegevoegd; klik op “Voorstellen vernieuwen” om veilig opnieuw te proberen.';
		update_option( 'publion_last_openai_error', $error );
		publion_send_error( 'content_generation', $error );
	}

	delete_option( 'publion_last_openai_error' );
	wp_send_json_success( $suggestions );
	return;

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
		publion_send_error( 'permission_denied', __( 'Je account mag geen onderwerpen aan de wachtrij toevoegen.', 'publion' ) );
	}

	global $wpdb;
	publion_register_table_on_wpdb();

	// Prefer an explicit JSON payload. filter_input() is unreliable for nested
	// arrays in several PHP/FastCGI configurations and could turn a valid UI
	// selection into an empty queue. Keep the normal POST-array as a fallback
	// for existing browser sessions.
	$queue_raw  = array();
	$queue_json = isset( $_POST['queue_json'] ) ? (string) wp_unslash( $_POST['queue_json'] ) : '';
	if ( '' !== $queue_json ) {
		$decoded = json_decode( $queue_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			publion_send_error( 'validation', __( 'De geselecteerde onderwerpen konden niet veilig worden gelezen. Ververs de voorstellen en probeer het opnieuw.', 'publion' ) );
		}
		$queue_raw = $decoded;
	} elseif ( isset( $_POST['queue'] ) && is_array( $_POST['queue'] ) ) {
		$queue_raw = wp_unslash( $_POST['queue'] );
	}

	if ( empty( $queue_raw ) ) {
		publion_send_error( 'validation', __( 'Er zijn geen onderwerpen ontvangen. Selecteer minimaal één voorstel en probeer het opnieuw.', 'publion' ) );
	}
	if ( count( $queue_raw ) > 25 ) {
		publion_send_error( 'validation', __( 'Je kunt maximaal 25 onderwerpen tegelijk aan de wachtrij toevoegen.', 'publion' ) );
	}

	$queue = [];
	$seen  = [];
	$rejected = array();
	foreach ( $queue_raw as $item_raw ) {
		if ( ! is_array( $item_raw ) ) {
			$rejected[] = __( 'Een onderwerp had een onleesbaar formaat.', 'publion' );
			continue;
		}
		$topic_raw          = isset( $item_raw['topic'] ) ? (string) $item_raw['topic'] : '';
		$topic_raw          = str_replace( '\"', '', $topic_raw );
		$category_id_raw    = isset( $item_raw['category'] ) ? $item_raw['category'] : 0;
		$category_label_raw = isset( $item_raw['categoryLabel'] ) ? (string) $item_raw['categoryLabel'] : '';
		$focus_keyword_raw  = isset( $item_raw['focusKeyword'] ) ? (string) $item_raw['focusKeyword'] : '';
		$seo_brief_raw      = isset( $item_raw['seoBrief'] ) && is_array( $item_raw['seoBrief'] ) ? $item_raw['seoBrief'] : array();

		$topic_clean = publion_normalize_title( $topic_raw );
		// Never persist a malformed response from an old browser cache or a
		// manipulated request, even when the client-side guard was bypassed.
		if ( ! publion_is_safe_suggestion_title( $topic_clean ) ) {
			$rejected[] = sprintf( __( '“%s” heeft geen geldige, volledige onderwerptitel.', 'publion' ), $topic_clean ?: __( 'Dit onderwerp', 'publion' ) );
			continue;
		}
		$category_id = intval( $category_id_raw );
		if ( ! $category_id || ! term_exists( $category_id, 'category' ) ) {
			$rejected[] = sprintf( __( '“%s” heeft geen geldige categorie meer. Kies opnieuw een categorie.', 'publion' ), $topic_clean );
			continue;
		}
		$key = mb_strtolower( $topic_clean ) . '|' . $category_id;
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;

		$queue[] = [
			'topic'          => $topic_clean,
			'focus_keyword'  => sanitize_text_field( $focus_keyword_raw ?: $topic_clean ),
			'seo_brief'      => wp_json_encode( array(
				'search_intent' => sanitize_text_field( $seo_brief_raw['search_intent'] ?? 'informatief' ),
				'angle'         => sanitize_text_field( $seo_brief_raw['angle'] ?? '' ),
				'faq_questions' => array_slice( array_filter( array_map( 'sanitize_text_field', (array) ( $seo_brief_raw['faq_questions'] ?? array() ) ) ), 0, 4 ),
			) ),
			'category_id'    => $category_id,
			'category_label' => sanitize_text_field( $category_label_raw ),
		];
	}
	if ( empty( $queue ) ) {
		publion_send_error(
			'validation',
			__( 'Geen van de geselecteerde onderwerpen kon worden gevalideerd.', 'publion' ),
			array(
				'next_step' => __( 'Ververs de voorstellen. Kies daarna opnieuw een categorie en voeg alleen complete onderwerpkaarten toe.', 'publion' ),
				'invalid_items' => array_slice( $rejected, 0, 5 ),
			)
		);
	}

	$inserted = 0;
	$skipped  = 0;
	$failed   = 0;
	foreach ( $queue as $item ) {
		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->publion_queue} WHERE topic = %s AND category_id = %d",
				$item['topic'],
				$item['category_id']
			)
		);
		if ( $exists ) {
			$skipped++;
			continue;
		}

		$inserted_row = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->publion_queue,
			[
				'topic'          => $item['topic'],
				'focus_keyword'  => $item['focus_keyword'],
				'seo_brief'      => $item['seo_brief'],
				'category_id'    => $item['category_id'],
				'category_label' => $item['category_label'],
				'status'         => 'pending',
				'created_at'     => current_time( 'mysql' ),
			]
		);
		if ( false === $inserted_row ) {
			$failed++;
			continue;
		}
		$inserted++;

		// Invalidate caches affected by this write.
		publion_cache_delete( 'used_topics_' . md5( $item['category_label'] ) );
		publion_cache_delete( 'pending_ids' );
		publion_cache_delete( 'pending_total' );
	}

	publion_schedule_pending_entries( false );
	if ( 0 === $inserted && $failed > 0 ) {
		publion_send_error( 'database', __( 'Geen enkel onderwerp kon worden opgeslagen. De wachtrij is niet gewijzigd.', 'publion' ) );
	}
	wp_send_json_success(
		array(
			'count'   => $inserted,
			'skipped' => $skipped,
			'failed'  => $failed,
			'rejected' => count( $rejected ),
			'rejected_items' => array_slice( $rejected, 0, 5 ),
			'message' => $failed > 0
				? __( 'Een deel van de onderwerpen kon niet worden opgeslagen. Controleer de wachtrij voordat je verdergaat.', 'publion' )
				: __( 'De geldige onderwerpen zijn aan de wachtrij toegevoegd.', 'publion' ),
		)
	);
}

/* ===== Save Post Settings ===== */
add_action( 'wp_ajax_publion_save_post_settings', 'publion_save_post_settings_callback' );
function publion_save_post_settings_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		publion_send_error( 'permission_denied', __( 'Je account mag de postinstellingen niet wijzigen.', 'publion' ) );
	}

	$existing_settings  = get_option( 'publion_post_settings', array() );
	$time_frame_days    = isset( $_POST['time_frame_days'] ) ? intval( wp_unslash( $_POST['time_frame_days'] ) ) : 7;
	$post_creation_time = isset( $_POST['post_creation_time'] )
		? sanitize_text_field( wp_unslash( $_POST['post_creation_time'] ) )
		: ( $existing_settings['post_creation_time'] ?? '00:00' );
	$post_status        = sanitize_text_field( wp_unslash( $_POST['post_status'] ?? 'draft' ) );
	$default_post_author = isset( $_POST['default_post_author'] )
		? absint( wp_unslash( $_POST['default_post_author'] ) )
		: (int) ( $existing_settings['default_post_author'] ?? 0 );
	$cta_enabled_raw    = sanitize_text_field( wp_unslash( $_POST['cta_enabled'] ?? 'no' ) );
	$cta_text           = sanitize_text_field( wp_unslash( $_POST['cta_text'] ?? '' ) );
	$cta_link           = esc_url_raw( wp_unslash( $_POST['cta_link'] ?? '' ) );
	$notification_email = sanitize_email( wp_unslash( $_POST['notification_email'] ?? '' ) );
	$hide_title         = ( isset( $_POST['hide_title'] ) && 'yes' === $_POST['hide_title'] ) ? 'yes' : 'no';
	$auto_daily_topic   = ( isset( $_POST['auto_daily_topic'] ) && 'yes' === $_POST['auto_daily_topic'] ) ? 'yes' : 'no';
	$daily_topic_time   = isset( $_POST['daily_topic_time'] )
		? sanitize_text_field( wp_unslash( $_POST['daily_topic_time'] ) )
		: ( $existing_settings['daily_topic_time'] ?? '00:00' );
	$daily_topic_interval_days = isset( $_POST['daily_topic_interval_days'] )
		? intval( wp_unslash( $_POST['daily_topic_interval_days'] ) )
		: (int) ( $existing_settings['daily_topic_interval_days'] ?? 1 );
	$rank_math_integration = ( isset( $_POST['rank_math_integration'] ) && 'yes' === $_POST['rank_math_integration'] ) ? 'yes' : 'no';
	$structured_data       = ( isset( $_POST['structured_data'] ) && 'yes' === $_POST['structured_data'] ) ? 'yes' : 'no';
	$image_border_radius  = isset( $_POST['image_border_radius'] ) ? absint( wp_unslash( $_POST['image_border_radius'] ) ) : 8;
	$article_style_mode   = sanitize_key( wp_unslash( $_POST['article_style_mode'] ?? 'inherit' ) );
	$article_style_mode   = in_array( $article_style_mode, array( 'inherit', 'refined' ), true ) ? $article_style_mode : 'inherit';
	$content_accent_color = sanitize_hex_color( wp_unslash( $_POST['content_accent_color'] ?? '' ) );
	$content_accent_color = $content_accent_color ? $content_accent_color : '#4f46e5';
	$content_max_width    = isset( $_POST['content_max_width'] ) ? absint( wp_unslash( $_POST['content_max_width'] ) ) : 760;
	$content_max_width    = max( 560, min( 1200, $content_max_width ) );
	$custom_article_css_raw = wp_unslash( $_POST['custom_article_css'] ?? '' );
	$custom_article_css   = current_user_can( 'unfiltered_html' )
		? Publion_Admin::sanitize_custom_article_css( $custom_article_css_raw )
		: (string) ( $existing_settings['custom_article_css'] ?? '' );
	$search_console_url   = esc_url_raw( wp_unslash( $_POST['search_console_url'] ?? '' ) );
	$ga4_url              = esc_url_raw( wp_unslash( $_POST['ga4_url'] ?? '' ) );
	$preferred_external_domain = sanitize_text_field( wp_unslash( $_POST['preferred_external_domain'] ?? '' ) );
	$preferred_external_urls   = wp_kses_post( wp_unslash( $_POST['preferred_external_urls'] ?? '' ) );
	$web_research_enabled      = ( isset( $_POST['web_research_enabled'] ) && 'yes' === $_POST['web_research_enabled'] ) ? 'yes' : 'no';
	$web_research_model        = publion_normalize_openai_model_id( wp_unslash( $_POST['web_research_model'] ?? 'gpt-5.6' ) );
	$web_research_model        = $web_research_model ?: 'gpt-5.6';
	$web_research_source_count = max( 1, min( 5, absint( wp_unslash( $_POST['web_research_source_count'] ?? 3 ) ) ) );
	$web_research_context_size = sanitize_key( wp_unslash( $_POST['web_research_context_size'] ?? 'medium' ) );
	$web_research_context_size = in_array( $web_research_context_size, array( 'low', 'medium', 'high' ), true ) ? $web_research_context_size : 'medium';
	$web_research_live_access  = ( isset( $_POST['web_research_live_access'] ) && 'yes' === $_POST['web_research_live_access'] ) ? 'yes' : 'no';
	$web_research_allowed_domains = sanitize_textarea_field( wp_unslash( $_POST['web_research_allowed_domains'] ?? '' ) );
	$web_research_blocked_domains = sanitize_textarea_field( wp_unslash( $_POST['web_research_blocked_domains'] ?? '' ) );
	$web_research_display_sources = ( isset( $_POST['web_research_display_sources'] ) && 'yes' === $_POST['web_research_display_sources'] ) ? 'yes' : 'no';
	$web_research_failure_mode    = sanitize_key( wp_unslash( $_POST['web_research_failure_mode'] ?? 'stop' ) );
	$web_research_failure_mode    = in_array( $web_research_failure_mode, array( 'stop', 'continue' ), true ) ? $web_research_failure_mode : 'stop';

	if ( $default_post_author ) {
		$user = get_user_by( 'id', $default_post_author );
		if ( ! $user || ! user_can( $user, 'edit_posts' ) ) {
			$default_post_author = 0;
		}
	}

	$settings = [
		'time_frame_days'    => $time_frame_days,
		'post_creation_time' => $post_creation_time,
		'post_status'        => $post_status,
		'default_post_author' => $default_post_author,
		'cta_enabled'        => ( 'yes' === $cta_enabled_raw ? 'yes' : 'no' ),
		'cta_text'           => $cta_text,
		'cta_link'           => $cta_link,
		'notification_email' => $notification_email,
		'hide_title'         => $hide_title,
		'auto_daily_topic'   => $auto_daily_topic,
		'daily_topic_time'   => $daily_topic_time,
		'daily_topic_interval_days' => max( 1, (int) $daily_topic_interval_days ),
		'rank_math_integration' => $rank_math_integration,
		'structured_data'       => $structured_data,
		'image_border_radius'   => min( 48, $image_border_radius ),
		'article_style_mode'    => $article_style_mode,
		'content_accent_color' => $content_accent_color,
		'content_max_width'    => $content_max_width,
		'custom_article_css'   => $custom_article_css,
		'search_console_url'    => $search_console_url,
		'ga4_url'               => $ga4_url,
		'preferred_external_domain' => $preferred_external_domain,
		'preferred_external_urls'   => $preferred_external_urls,
		'web_research_enabled'      => $web_research_enabled,
		'web_research_model'        => $web_research_model,
		'web_research_source_count' => $web_research_source_count,
		'web_research_context_size' => $web_research_context_size,
		'web_research_live_access'  => $web_research_live_access,
		'web_research_allowed_domains' => $web_research_allowed_domains,
		'web_research_blocked_domains' => $web_research_blocked_domains,
		'web_research_display_sources' => $web_research_display_sources,
		'web_research_failure_mode'    => $web_research_failure_mode,
	];

	if ( false === update_option( 'publion_post_settings', $settings ) && $settings !== $existing_settings ) {
		publion_send_error( 'database', __( 'De postinstellingen konden niet worden opgeslagen. Controleer de gegevens en probeer opnieuw.', 'publion' ) );
	}

	$next_daily_ts = 0;
	$time_frame_changed = (int) ( $existing_settings['time_frame_days'] ?? 3 ) !== (int) $time_frame_days;
	$post_time_changed  = ( $existing_settings['post_creation_time'] ?? '00:00' ) !== $post_creation_time;
	if ( $time_frame_changed || $post_time_changed ) {
		publion_schedule_pending_entries( true );
	}

	$daily_time_changed = ( $existing_settings['daily_topic_time'] ?? '00:00' ) !== $daily_topic_time;
	$daily_interval_changed = (int) ( $existing_settings['daily_topic_interval_days'] ?? 1 ) !== (int) $daily_topic_interval_days;
	$daily_toggle_changed = ( $existing_settings['auto_daily_topic'] ?? 'no' ) !== $auto_daily_topic;
	if ( $daily_time_changed || $daily_interval_changed || $daily_toggle_changed ) {
		$next_daily_ts = publion_reschedule_daily_topic_event( $settings );
	} else {
		$next_daily_ts = (int) wp_next_scheduled( 'publion_daily_topic_hook' );
	}

	$author_changed = (int) ( $existing_settings['default_post_author'] ?? 0 ) !== (int) $default_post_author;
	if ( $author_changed && $default_post_author ) {
		publion_update_existing_posts_author( $default_post_author );
	}

	wp_send_json_success(
		[
			'message'          => __( 'Instellingen succesvol opgeslagen.', 'publion' ),
			'next_daily_topic' => $next_daily_ts ? wp_date( 'M d, Y H:i', $next_daily_ts ) : '',
		]
	);
}

/* ===== Update Queue Schedule ===== */
add_action( 'wp_ajax_publion_update_schedule', 'publion_update_schedule_callback' );
function publion_update_schedule_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		publion_send_error( 'permission_denied', __( 'Je account mag de planning niet wijzigen.', 'publion' ) );
	}

	$topic_id      = intval( $_POST['id'] ?? 0 );
	$scheduled_raw = sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ?? '' ) );

	if ( ! $topic_id || empty( $scheduled_raw ) ) {
		publion_send_error( 'validation', __( 'Kies een geldig datum en tijdstip voordat je de planning opslaat.', 'publion' ) );
	}

	$tz = wp_timezone();
	$dt = DateTimeImmutable::createFromFormat( 'Y-m-d\\TH:i', $scheduled_raw, $tz );
	if ( ! $dt ) {
		publion_send_error( 'validation', __( 'De gekozen datum of tijd is ongeldig. Kies een datum en tijd uit de datumkiezer.', 'publion' ) );
	}

	global $wpdb;
	publion_register_table_on_wpdb();

	$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->publion_queue,
		array(
			'scheduled_at'   => $dt->format( 'Y-m-d H:i:s' ),
			'schedule_locked' => 1,
		),
		array( 'id' => $topic_id ),
		array( '%s', '%d' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		publion_send_error( 'database', __( 'De planning kon niet worden opgeslagen. Er is niets gewijzigd.', 'publion' ) );
	}

	publion_invalidate_pending_cache();

	$today_dt     = ( new DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0, 0 );
	$scheduled_dt = $dt->setTime( 0, 0, 0 );
	if ( $scheduled_dt < $today_dt ) {
		$days_until = 0;
	} else {
		$days_until = (int) $today_dt->diff( $scheduled_dt )->days;
	}

	wp_send_json_success(
		array(
			'scheduled_input' => $dt->format( 'Y-m-d\\TH:i' ),
			'scheduled_label' => wp_date( 'M d, Y H:i', $dt->getTimestamp() ),
			'days_until'      => $days_until,
		)
	);
}

/* ===== Save API Key ===== */
add_action( 'wp_ajax_publion_save_api_key', 'publion_save_api_key_callback' );
function publion_save_api_key_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		publion_send_error( 'permission_denied', __( 'Je account mag de API-sleutel niet wijzigen.', 'publion' ) );
	}

	$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
	if ( empty( $api_key ) ) {
		publion_send_error( 'validation', __( 'Vul een API-sleutel in voordat je deze opslaat.', 'publion' ) );
	}
	if ( false === update_option( 'publion_api_key', $api_key ) && get_option( 'publion_api_key' ) !== $api_key ) {
		publion_send_error( 'database', __( 'De API-sleutel kon niet worden opgeslagen. Probeer het opnieuw.', 'publion' ) );
	}

	wp_send_json_success( [ 'message' => __( 'API-sleutel opgeslagen.', 'publion' ) ] );
}

/* ===== Save Model ===== */
add_action( 'wp_ajax_publion_save_model', 'publion_save_model_callback' );
function publion_save_model_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		publion_send_error( 'permission_denied', __( 'Je account mag het tekstmodel niet wijzigen.', 'publion' ) );
	}

	$model_choice = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
	$custom_model = publion_normalize_openai_model_id( wp_unslash( $_POST['custom_model'] ?? '' ) );
	$models       = publion_get_allowed_openai_models();
	$model        = '__custom__' === $model_choice ? $custom_model : publion_normalize_openai_model_id( $model_choice );

	if ( empty( $model ) ) {
		publion_send_error( 'validation', __( 'Vul een geldige OpenAI model-ID in. Gebruik alleen letters, cijfers, punten, underscores, dubbele punten en koppeltekens.', 'publion' ), array( 'action_label' => __( 'Controleer model', 'publion' ), 'action_tab' => 'publion-settings' ) );
	}

	if ( '__custom__' !== $model_choice && ! isset( $models[ $model ] ) ) {
		publion_send_error( 'openai_model', __( 'Kies een model uit de lijst of gebruik de optie voor een eigen model-ID.', 'publion' ) );
	}

	if ( false === update_option( 'publion_openai_model', $model ) && get_option( 'publion_openai_model' ) !== $model ) {
		publion_send_error( 'database', __( 'Het tekstmodel kon niet worden opgeslagen. Probeer het opnieuw.', 'publion' ) );
	}

	wp_send_json_success( [ 'message' => sprintf( __( 'Model %s opgeslagen. OpenAI controleert de beschikbaarheid bij de eerstvolgende aanvraag.', 'publion' ), $model ) ] );
}

/* ===== Save Image Model ===== */
add_action( 'wp_ajax_publion_save_image_model', 'publion_save_image_model_callback' );
function publion_save_image_model_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		publion_send_error( 'permission_denied', __( 'Je account mag het afbeeldingsmodel niet wijzigen.', 'publion' ) );
	}

	$model_choice = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
	$custom_model = publion_normalize_openai_model_id( wp_unslash( $_POST['custom_model'] ?? '' ) );
	$models       = publion_get_allowed_openai_image_models();
	$model        = '__custom__' === $model_choice ? $custom_model : publion_normalize_openai_model_id( $model_choice );

	if ( empty( $model ) ) {
		publion_send_error( 'validation', __( 'Vul een geldige afbeeldingsmodel-ID in. Gebruik alleen letters, cijfers, punten, underscores, dubbele punten en koppeltekens.', 'publion' ), array( 'action_label' => __( 'Controleer model', 'publion' ), 'action_tab' => 'publion-settings' ) );
	}

	if ( '__custom__' !== $model_choice && ! isset( $models[ $model ] ) ) {
		publion_send_error( 'openai_model', __( 'Kies een afbeeldingsmodel uit de lijst of gebruik de optie voor een eigen model-ID.', 'publion' ) );
	}

	if ( false === update_option( 'publion_openai_image_model', $model ) && get_option( 'publion_openai_image_model' ) !== $model ) {
		publion_send_error( 'database', __( 'Het afbeeldingsmodel kon niet worden opgeslagen. Probeer het opnieuw.', 'publion' ) );
	}
	wp_send_json_success( [ 'message' => sprintf( __( 'Afbeeldingsmodel %s opgeslagen. OpenAI controleert de beschikbaarheid bij de eerstvolgende afbeeldingsopdracht.', 'publion' ), $model ) ] );
}

/* ===== Save Prompt ===== */
add_action( 'wp_ajax_publion_save_prompt', 'publion_save_prompt_callback' );
function publion_save_prompt_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		publion_send_error( 'permission_denied', __( 'Je account mag de Publion-prompt niet wijzigen.', 'publion' ) );
	}

	$prompt = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
	if ( false === update_option( 'publion_prompt', $prompt ) && get_option( 'publion_prompt' ) !== $prompt ) {
		publion_send_error( 'database', __( 'De Publion-prompt kon niet worden opgeslagen. Probeer het opnieuw.', 'publion' ) );
	}

	wp_send_json_success( [ 'message' => __( 'Publion-prompt opgeslagen.', 'publion' ) ] );
}

/* ===== Load Pending Queue Entries ===== */
add_action( 'wp_ajax_publion_load_queue_entries', 'publion_load_queue_entries_callback' );
function publion_load_queue_entries_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		publion_send_error( 'permission_denied', __( 'Je account mag de Publion-wachtrij niet bekijken.', 'publion' ) );
	}

	global $wpdb;
	publion_register_table_on_wpdb();

	publion_schedule_pending_entries( false );

	$offset = max( 0, intval( $_POST['offset'] ?? 0 ) );
	$limit  = max( 1, intval( $_POST['limit'] ?? 10 ) );

	// Pending entries page (cached).
	$page_key = 'pending_page_' . $offset . '_' . $limit;
	$entries  = publion_db_get_results_cached(
		"SELECT * FROM {$wpdb->publion_queue} WHERE status = %s ORDER BY id ASC LIMIT %d OFFSET %d",
		array( 'pending', $limit, $offset ),
		$page_key
	);

	$row_html = '';

	$tz       = wp_timezone();
	$now_dt   = new DateTimeImmutable( 'now', $tz );
	$today_dt = $now_dt->setTime( 0, 0, 0 );

	foreach ( $entries as $entry ) {
		$row_html .= '<tr>';

		$row_html .= '<td style="text-align:center;">';
		$row_html .= '<input type="checkbox" class="publion-row-select" data-id="' . esc_attr( $entry->id ) . '">';
		$row_html .= '</td>';

		// Actions column (create/delete for pending).
		$row_html .= '<td class="publion-queue-actions" style="text-align: center; white-space: nowrap; padding: 0;">';
		$row_html .= '<button class="publion-create-now button button-primary" data-id="' . esc_attr( $entry->id ) . '"><span class="button-text">' . esc_html__( 'Nu maken', 'publion' ) . '</span></button>';
		$row_html .= '<button type="button" class="publion-cancel-creation button" data-id="' . esc_attr( $entry->id ) . '" aria-label="' . esc_attr__( 'Artikelgeneratie annuleren', 'publion' ) . '" title="' . esc_attr__( 'Artikelgeneratie annuleren', 'publion' ) . '" hidden><span aria-hidden="true">&times;</span><span class="screen-reader-text">' . esc_html__( 'Artikelgeneratie annuleren', 'publion' ) . '</span></button>';
		$row_html .= '<span class="publion-create-spinner spinner" aria-hidden="true"></span>';
		$row_html .= '<small class="publion-create-estimate" aria-live="polite" hidden></small>';
		$row_html .= '<button class="publion-delete button" data-id="' . esc_attr( $entry->id ) . '" style="background-color:#cc0000; color:#fff; font-size:12px; padding:0 4px 2px 4px; margin:2px; line-height:1em; height:auto; vertical-align:middle; border-width:0px;">' . esc_html__( 'Verwijderen', 'publion' ) . '</button>';
		$row_html .= '</td>';

		// Topic and category.
		$row_html .= '<td>' . stripslashes( esc_html( $entry->topic ) ) . '</td>';
		$row_html .= '<td style="text-align: center;">' . esc_html( $entry->category_label ) . '</td>';

		$scheduled_ts = $entry->scheduled_at ? publion_mysql_to_timestamp( $entry->scheduled_at ) : 0;
		$scheduled_dt = $scheduled_ts ? ( new DateTimeImmutable( '@' . $scheduled_ts ) )->setTimezone( $tz ) : null;

		$scheduled_input = $scheduled_ts ? wp_date( 'Y-m-d\\TH:i', $scheduled_ts ) : '';
		$row_html .= '<td class="publion-schedule-cell" style="text-align: center;">';
		$row_html .= '<input type="datetime-local" class="publion-schedule-input" value="' . esc_attr( $scheduled_input ) . '">';
		$row_html .= '<button class="button publion-schedule-save" data-id="' . esc_attr( $entry->id ) . '" style="margin-left:6px;">' . esc_html__( 'Opslaan', 'publion' ) . '</button>';
		$row_html .= '<span class="publion-schedule-status" style="margin-left:6px;"></span>';
		$row_html .= '</td>';

		if ( $scheduled_dt ) {
			$scheduled_date = $scheduled_dt->setTime( 0, 0, 0 );
			$days_until = ( $scheduled_date < $today_dt ) ? 0 : (int) $today_dt->diff( $scheduled_date )->days;
		} else {
			$days_until = 0;
		}

		$row_html .= '<td class="publion-days-until" style="text-align: center;">' . esc_html( (string) $days_until ) . '</td>';

		// Created at (display only).
		$created_ts           = publion_mysql_to_timestamp( $entry->created_at );
		$formatted_created_at = ( $created_ts ) ? wp_date( 'M d, Y H:i', $created_ts ) : '';
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
		publion_send_error( 'permission_denied', __( 'Je account mag de aangemaakte Publion-posts niet bekijken.', 'publion' ) );
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

		$row_html .= '<td style="text-align:center;">' . esc_html( $created_ts ? wp_date( 'M d, Y H:i', $created_ts ) : '' ) . '</td>';
		$row_html .= '<td style="text-align:center;">' . esc_html( $post_created_ts ? wp_date( 'M d, Y H:i', $post_created_ts ) : '-' ) . '</td>';
		$row_html .= '<td style="text-align:center;">' . ( $published_ts ? esc_html( wp_date( 'M d, Y H:i', $published_ts ) ) : 'Niet gepubliceerd' ) . '</td>';

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

/* ===== Clear Created Posts History ===== */
add_action( 'wp_ajax_publion_clear_created_history', 'publion_clear_created_history_callback' );
function publion_clear_created_history_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		publion_send_error( 'permission_denied', __( 'Je account mag de Publion-geschiedenis niet wissen.', 'publion' ) );
	}

	global $wpdb;
	publion_register_table_on_wpdb();

	// This intentionally deletes only Publion's bookkeeping rows. The linked
	// WordPress posts and their media are never selected or deleted here.
	$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->publion_queue} WHERE status IN (%s, %s)",
			'created',
			'published'
		)
	);
	if ( false === $deleted ) {
		publion_send_error( 'database', __( 'De Publion-geschiedenis kon niet worden gewist. Er is niets verwijderd.', 'publion' ) );
	}

	publion_cache_delete( 'created_total' );
	foreach ( array( 'created_page_', 'pending_page_' ) as $prefix ) {
		for ( $i = 0; $i <= 50; $i += 10 ) {
			publion_cache_delete( $prefix . $i . '_10' );
		}
	}
	delete_option( 'publion_last_post_created_at' );

	wp_send_json_success(
		array(
			'deleted' => (int) $deleted,
			'message' => __( 'De Publion-geschiedenis is gewist. Je WordPress-posts zijn niet verwijderd.', 'publion' ),
		)
	);
}

/* ===== Live creation progress ===== */
function publion_creation_progress_key( $topic_id ) {
	return 'publion_creation_progress_' . absint( $topic_id );
}

function publion_creation_cancellation_key( $topic_id ) {
	return 'publion_creation_cancelled_' . absint( $topic_id );
}

/** Return a realistic total-duration range for this site's article workflow. */
function publion_get_creation_time_estimate() {
	$samples = array_values( array_filter( array_map( 'absint', (array) get_option( 'publion_creation_duration_samples', array() ) ), static function ( $seconds ) {
		return $seconds >= 30 && $seconds <= 1800;
	} ) );
	$samples = array_slice( $samples, -12 );

	// Six sequential image requests make a two-to-five minute first estimate
	// more honest than a single fixed number. It is replaced after real runs.
	if ( count( $samples ) < 3 ) {
		return array(
			'lower_seconds' => 120,
			'upper_seconds' => 300,
			'sample_count'  => count( $samples ),
			'source'        => 'baseline',
		);
	}

	sort( $samples, SORT_NUMERIC );
	$middle = (int) floor( count( $samples ) / 2 );
	$median = ( count( $samples ) % 2 ) ? $samples[ $middle ] : (int) round( ( $samples[ $middle - 1 ] + $samples[ $middle ] ) / 2 );
	$lower  = max( 60, min( 900, (int) ( round( ( $median * 0.7 ) / 30 ) * 30 ) ) );
	$upper  = max( $lower + 60, min( 1200, (int) ( round( ( $median * 1.35 ) / 30 ) * 30 ) ) );

	return array(
		'lower_seconds' => $lower,
		'upper_seconds' => $upper,
		'sample_count'  => count( $samples ),
		'source'        => 'history',
	);
}

/** Store a small rolling sample of completed generation durations. */
function publion_record_creation_duration( $started_at ) {
	$duration = time() - absint( $started_at );
	if ( $duration < 30 || $duration > 1800 ) {
		return;
	}

	$samples   = array_values( array_filter( array_map( 'absint', (array) get_option( 'publion_creation_duration_samples', array() ) ) ) );
	$samples[] = $duration;
	update_option( 'publion_creation_duration_samples', array_slice( $samples, -12 ), false );
}

/**
 * Persist the latest completed workflow checkpoint for a queue entry.
 * The percentage is stage-based, not a time estimate: it only moves when the
 * corresponding server-side action has been reached or completed.
 */
function publion_set_creation_progress( $topic_id, $state, $percent, $stage, $detail = '', $extra = array() ) {
	$topic_id = absint( $topic_id );
	if ( ! $topic_id ) {
		return;
	}

	$existing = get_transient( publion_creation_progress_key( $topic_id ) );
	$carry    = array();
	if ( is_array( $existing ) ) {
		foreach ( array( 'started_at', 'estimate' ) as $key ) {
			if ( isset( $existing[ $key ] ) ) {
				$carry[ $key ] = $existing[ $key ];
			}
		}
	}

	$progress = array_merge(
		array(
			'topic_id'   => $topic_id,
			'state'      => in_array( $state, array( 'running', 'completed', 'failed', 'cancelled' ), true ) ? $state : 'running',
			'percent'    => max( 0, min( 100, absint( $percent ) ) ),
			'stage'      => sanitize_text_field( $stage ),
			'detail'     => sanitize_text_field( $detail ),
			'updated_at' => time(),
		),
		$carry,
		is_array( $extra ) ? $extra : array()
	);

	set_transient( publion_creation_progress_key( $topic_id ), $progress, HOUR_IN_SECONDS );
}

/** Stop at a safe checkpoint instead of leaving a partial article in the queue. */
function publion_abort_if_creation_cancelled( $topic_id ) {
	if ( ! get_transient( publion_creation_cancellation_key( $topic_id ) ) ) {
		return;
	}

	delete_transient( publion_creation_cancellation_key( $topic_id ) );
	global $wpdb;
	publion_register_table_on_wpdb();
	$entry = $wpdb->get_row( $wpdb->prepare( "SELECT topic FROM {$wpdb->publion_queue} WHERE id = %d", absint( $topic_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $entry ) {
		publion_release_queue_claim( $topic_id, $entry->topic, 'pending' );
	}
	publion_set_creation_progress(
		$topic_id,
		'cancelled',
		0,
		__( 'Geannuleerd', 'publion' ),
		__( 'De artikelgeneratie is veilig gestopt. Het onderwerp blijft in de wachtrij staan.', 'publion' )
	);
	publion_send_error(
		'operation_cancelled',
		__( 'De artikelgeneratie is geannuleerd. Er is geen nieuw artikel opgeslagen.', 'publion' ),
		array(
			'title'        => __( 'Artikelgeneratie geannuleerd', 'publion' ),
			'next_step'    => __( 'Het onderwerp staat nog in de wachtrij. Start opnieuw wanneer je klaar bent.', 'publion' ),
			'action_label' => __( 'Open wachtrij', 'publion' ),
			'action_tab'   => 'publion-queue',
			'retryable'    => false,
		)
	);
}

add_action( 'wp_ajax_publion_cancel_post_creation', 'publion_cancel_post_creation' );
function publion_cancel_post_creation() {
	check_ajax_referer( 'publion_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		publion_send_error( 'permission_denied', __( 'Je account mag deze artikelgeneratie niet annuleren.', 'publion' ) );
	}

	$topic_id = absint( $_POST['id'] ?? 0 );
	if ( ! $topic_id ) {
		publion_send_error( 'validation', __( 'Er ontbreekt een geldig wachtrij-item om te annuleren.', 'publion' ) );
	}

	set_transient( publion_creation_cancellation_key( $topic_id ), 1, HOUR_IN_SECONDS );
	wp_send_json_success(
		array(
			'message' => __( 'Annulering is aangevraagd. Publion stopt bij het eerstvolgende veilige servermoment.', 'publion' ),
		)
	);
}

function publion_fail_post_creation( $topic_id, $message, $code = 'content_generation' ) {
	$code    = publion_guess_error_code( $message, $code );
	$payload = publion_build_error_payload( $code, $message );
	global $wpdb;
	publion_register_table_on_wpdb();
	$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->publion_queue} WHERE id = %d", absint( $topic_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $entry ) {
		$linked_post_id = publion_get_post_id_for_queue_entry( $entry );
		if ( $linked_post_id ) {
			$final_status = ( 'publish' === get_post_status( $linked_post_id ) ) ? 'published' : 'created';
			$wpdb->update( $wpdb->publion_queue, array( 'status' => $final_status, 'processing_started_at' => null ), array( 'id' => absint( $topic_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			publion_release_generation_lock( $topic_id, $entry->topic );
		} else {
			publion_release_queue_claim( $topic_id, $entry->topic, ( 'duplicate_content' === $code ) ? 'blocked' : 'pending' );
		}
	}
	publion_set_creation_progress(
		$topic_id,
		'failed',
		0,
		$payload['title'],
		$payload['message'],
		array( 'error' => $payload )
	);
	wp_send_json_error( $payload );
}

add_action( 'wp_ajax_publion_get_creation_progress', 'publion_get_creation_progress_callback' );
function publion_get_creation_progress_callback() {
	check_ajax_referer( 'publion_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		publion_send_error( 'permission_denied', __( 'Je account heeft geen toegang tot de actuele voortgang.', 'publion' ) );
	}

	$topic_id = absint( $_POST['id'] ?? 0 );
	$progress = $topic_id ? get_transient( publion_creation_progress_key( $topic_id ) ) : false;
	if ( ! is_array( $progress ) ) {
		publion_send_error( 'not_found', __( 'Er is nog geen actuele voortgang voor dit wachtrij-item.', 'publion' ) );
	}

	wp_send_json_success( $progress );
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
		publion_send_error( 'permission_denied', __( 'Je account mag geen artikelen maken vanuit Publion.', 'publion' ) );
	}

	global $wpdb;
	publion_register_table_on_wpdb();

	$topic_id = intval( $_POST['id'] ?? 0 );
	if ( ! $topic_id ) {
		publion_send_error( 'validation', __( 'Er ontbreekt een geldig wachtrij-item.', 'publion' ) );
	}

	// Topic row (cached by id).
	$topic = publion_db_get_row_cached(
		"SELECT * FROM {$wpdb->publion_queue} WHERE id = %d",
		array( $topic_id ),
		'queue_row_' . $topic_id
	);
	if ( ! $topic ) {
		publion_send_error( 'not_found', __( 'Het gekozen wachtrij-item is niet gevonden.', 'publion' ) );
	}
	$existing_post_id = publion_get_post_id_for_queue_entry( $topic );
	if ( $existing_post_id ) {
		publion_send_error( 'duplicate_content', __( 'Dit wachtrij-item heeft al een WordPress-post. Er is geen tweede post aangemaakt.', 'publion' ) );
	}
	if ( ! publion_claim_queue_entry( $topic_id, $topic->topic ) ) {
		publion_send_error( 'content_generation', __( 'Dit onderwerp wordt al verwerkt of is al afgerond. Wacht op de huidige generatie en ververs daarna de wachtrij.', 'publion' ) );
	}

	$topic_validation = publion_validate_topic_originality( $topic->topic );
	if ( is_wp_error( $topic_validation ) ) {
		publion_fail_post_creation( $topic_id, $topic_validation->get_error_message(), 'duplicate_content' );
	}

	$creation_started_at = time();
	publion_set_creation_progress(
		$topic_id,
		'running',
		5,
		__( 'Voorbereiden', 'publion' ),
		__( 'Onderwerp, instellingen en SEO-brief worden gecontroleerd.', 'publion' ),
		array(
			'started_at' => $creation_started_at,
			'estimate'   => publion_get_creation_time_estimate(),
		)
	);
	delete_transient( publion_creation_cancellation_key( $topic_id ) );

	$settings    = get_option( 'publion_post_settings', [] );
	$post_status = sanitize_key( $settings['post_status'] ?? 'draft' );
	$author_id   = publion_get_post_author_id( $settings, 'current' );

	$add_cta  = ( isset( $settings['cta_enabled'] ) && 'yes' === $settings['cta_enabled'] );
	$cta_text = sanitize_text_field( $settings['cta_text'] ?? '' );
	$cta_link = esc_url_raw( $settings['cta_link'] ?? '' );

	$seo_brief = ! empty( $topic->seo_brief ) ? json_decode( $topic->seo_brief, true ) : array();
	$seo_brief = is_array( $seo_brief ) ? $seo_brief : array();
	$seo_brief['focus_keyword'] = sanitize_text_field( $topic->focus_keyword ?? $topic->topic );
	if ( ( $settings['rank_math_integration'] ?? 'no' ) === 'yes' ) {
		$focus_validation = publion_validate_rank_math_focus_keyword( $seo_brief['focus_keyword'] );
		if ( is_wp_error( $focus_validation ) ) {
			publion_fail_post_creation( $topic_id, $focus_validation->get_error_message(), 'duplicate_content' );
		}
	}
	$research_status = ( $settings['web_research_enabled'] ?? 'no' ) === 'yes'
		? __( 'Actueel webonderzoek zoekt en controleert externe bronnen.', 'publion' )
		: __( 'De bestaande contentkaart en zoekintentie worden meegenomen.', 'publion' );
	publion_set_creation_progress( $topic_id, 'running', 15, __( 'Onderzoek', 'publion' ), $research_status );
	$post_html = publion_generate_chatgpt_html( $topic->topic, $topic->category_label, $seo_brief );
	if ( is_wp_error( $post_html ) || ! $post_html ) {
		publion_fail_post_creation( $topic_id, is_wp_error( $post_html ) ? $post_html->get_error_message() : __( 'De artikeltekst is niet teruggekomen van OpenAI.', 'publion' ) );
	}
	publion_abort_if_creation_cancelled( $topic_id );
	publion_set_creation_progress( $topic_id, 'running', 45, __( 'Tekst nakijken', 'publion' ), __( 'Artikeltekst is gegenereerd en wordt voorbereid voor afbeeldingen.', 'publion' ) );

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
	$image_layouts    = [];
	$total_images     = max( 1, count( $prompts ) );
	$image_index      = 0;
	publion_set_creation_progress( $topic_id, 'running', 50, __( 'Afbeeldingen voorbereiden', 'publion' ), sprintf( __( '%d beeldopdrachten worden samengesteld.', 'publion' ), count( $prompts ) ) );

	foreach ( $prompts as $item ) {
		publion_abort_if_creation_cancelled( $topic_id );
		$image_index++;
		$image_percent = 50 + (int) floor( ( ( $image_index - 1 ) / $total_images ) * 30 );
		publion_set_creation_progress( $topic_id, 'running', $image_percent, __( 'Afbeelding genereren', 'publion' ), sprintf( __( 'Afbeelding %1$d van %2$d wordt gegenereerd en geüpload.', 'publion' ), $image_index, $total_images ) );
		$prompt_text = $item['prompt'] ?? '';
		$context     = $item['context'] ?? $topic->topic;
		$image_layout = ( isset( $item['layout'] ) && 'square' === $item['layout'] ) ? 'square' : 'landscape';
		$image_size   = ( isset( $item['size'] ) && in_array( $item['size'], array( '1024x1024', '1536x1024', '1024x1536', '1536x864' ), true ) ) ? $item['size'] : '1024x1024';
		$image_result = publion_generate_and_upload_images( $prompt_text, 1, $context, $api_key, $image_size );
		publion_abort_if_creation_cancelled( $topic_id );
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
	publion_set_creation_progress( $topic_id, 'running', 82, __( 'Artikel samenstellen', 'publion' ), __( 'Afbeeldingen en alt-teksten worden in het concept verwerkt.', 'publion' ) );
	publion_abort_if_creation_cancelled( $topic_id );

	// Insert 5 images into content.
	$post_html = publion_insert_images_into_content( $post_html, array_slice( $final_image_urls, 0, 5 ), array_slice( $image_layouts, 0, 5 ), $seo_brief['focus_keyword'] );
	$final_conflict = publion_find_existing_content_conflict( $topic->topic, $post_html );
	if ( $final_conflict ) {
		publion_fail_post_creation( $topic_id, __( 'Tijdens de generatie is al een vergelijkbaar artikel aangemaakt. Er is geen tweede post opgeslagen.', 'publion' ), 'duplicate_content' );
	}

	// Create post.
	publion_set_creation_progress( $topic_id, 'running', 88, __( 'Concept opslaan', 'publion' ), __( 'Het artikelconcept wordt in WordPress aangemaakt.', 'publion' ) );
	$post_id = wp_insert_post(
		[
			'post_title'    => wp_strip_all_tags( $topic->topic ),
			'post_name'     => publion_build_rank_math_slug( $seo_brief['focus_keyword'], $topic->topic ),
			'post_content'  => $post_html,
			'post_status'   => $post_status,
			'post_category' => [ (int) $topic->category_id ],
			'post_type'     => 'post',
			'post_author'   => $author_id,
		],
		true
	);

	if ( is_wp_error( $post_id ) ) {
		publion_fail_post_creation( $topic_id, __( 'WordPress kon het artikelconcept niet aanmaken.', 'publion' ), 'database' );
	}

	// Link the post back to the queue row for reliable lookups later.
	publion_set_creation_progress( $topic_id, 'running', 93, __( 'SEO en metadata', 'publion' ), __( 'Focus-keyword, structured data en artikelkoppeling worden opgeslagen.', 'publion' ) );
	add_post_meta( $post_id, '_publion_queue_id', (int) $topic_id, true );
	publion_store_article_seo_data( $post_id, $post_html, $seo_brief['focus_keyword'] );

	$rank_math_enabled = ( ( $settings['rank_math_integration'] ?? 'no' ) === 'yes' );
	if ( $rank_math_enabled ) {
		update_post_meta( (int) $post_id, 'rank_math_focus_keyword', $seo_brief['focus_keyword'] );
		update_post_meta( (int) $post_id, 'rank_math_title', publion_build_rank_math_seo_title( $topic->topic, $seo_brief['focus_keyword'] ) );
		update_post_meta( (int) $post_id, 'rank_math_description', publion_build_rank_math_meta_description( $post_html, $seo_brief['focus_keyword'] ) );
	}

	// Featured image: prefer 6th slot; use core helper to resolve attachment ID.
	publion_set_creation_progress( $topic_id, 'running', 96, __( 'Afronden', 'publion' ), __( 'Uitgelichte afbeelding, status en wachtrij worden bijgewerkt.', 'publion' ) );
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

	$queue_update = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->publion_queue,
		[
			'status'          => ( 'publish' === $post_status ? 'published' : 'created' ),
			'processing_started_at' => null,
			'post_created_at' => $now_mysql,
			'published_at'    => ( 'publish' === $post_status ? $now_mysql : null ),
		],
		[ 'id' => $topic_id ]
	);
	if ( false === $queue_update ) {
		publion_fail_post_creation( $topic_id, __( 'Het artikelconcept is aangemaakt, maar de Publion-wachtrij kon niet worden bijgewerkt. Controleer de wachtrij na het verversen.', 'publion' ), 'database' );
	}

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
	publion_record_creation_duration( $creation_started_at );
	publion_release_generation_lock( $topic_id, $topic->topic );

	publion_set_creation_progress(
		$topic_id,
		'completed',
		100,
		__( 'Klaar', 'publion' ),
		__( 'Het artikelconcept is opgeslagen en staat klaar voor redactionele controle.', 'publion' ),
		array( 'post_id' => (int) $post_id, 'post_status' => $post_status )
	);

	wp_send_json_success( [ 'message' => __( 'Post succesvol aangemaakt.', 'publion' ), 'post_id' => (int) $post_id ] );
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
		publion_send_error( 'permission_denied', __( 'Je account mag geen wachtrij-items verwijderen.', 'publion' ) );
	}

	$topic_id = intval( $_POST['id'] ?? 0 );
	if ( ! $topic_id ) {
		publion_send_error( 'validation', __( 'Er ontbreekt een geldig wachtrij-item om te verwijderen.', 'publion' ) );
	}

	global $wpdb;
	publion_register_table_on_wpdb();

	// Fetch row (cached) to invalidate correct caches before delete.
	$entry = publion_db_get_row_cached(
		"SELECT * FROM {$wpdb->publion_queue} WHERE id = %d",
		array( $topic_id ),
		'queue_row_' . $topic_id
	);
	if ( ! $entry ) {
		publion_send_error( 'not_found', __( 'Dit wachtrij-item bestaat niet meer. De wachtrij is mogelijk al gewijzigd.', 'publion' ) );
	}

	$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->publion_queue,
		[ 'id' => $topic_id ],
		[ '%d' ]
	);

	if ( false === $deleted ) {
		publion_send_error( 'database', __( 'Het wachtrij-item kon niet worden verwijderd. Er is niets gewijzigd.', 'publion' ) );
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

	publion_schedule_pending_entries( true );
	wp_send_json_success( [ 'message' => __( 'Onderwerp verwijderd.', 'publion' ) ] );
}
