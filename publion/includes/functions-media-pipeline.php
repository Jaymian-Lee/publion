<?php
/** Durable article snapshots and stable media roles; successful slots are reused. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function publion_get_queue_artifact( $id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT draft_html, artifact_state, pipeline_hash FROM {$wpdb->publion_queue} WHERE id = %d", absint( $id ) ) );
    $state = $row ? json_decode( $row->artifact_state ?? '', true ) : array();
    return array( 'html' => $row->draft_html ?? '', 'state' => is_array( $state ) ? $state : array(), 'hash' => $row->pipeline_hash ?? '' );
}

function publion_attachment_is_ready( $id ) {
    $ready = get_post( $id ) && wp_attachment_is_image( $id ) && wp_get_attachment_url( $id ) && is_file( get_attached_file( $id ) );
    return (bool) apply_filters( 'publion/attachment_is_ready', $ready, $id );
}

function publion_save_queue_artifact( $id, $topic, $html, $state, $hash ) {
    global $wpdb;
    if ( ! publion_renew_queue_claim( $id, $topic ) ) { return false; }
    return false !== $wpdb->update( $wpdb->publion_queue, array( 'draft_html' => $html, 'artifact_state' => wp_json_encode( $state ), 'pipeline_hash' => $hash ), array( 'id' => absint( $id ), 'status' => 'processing', 'claim_token' => $GLOBALS['publion_claim_tokens'][absint( $id )] ?? '' ) );
}

function publion_generate_queue_article( $topic, $brief ) {
    $id = (int) $topic->id;
    $GLOBALS['publion_active_queue_id'] = $id;
    $hash = hash( 'sha256', wp_json_encode( array( 'topic' => $topic->topic, 'brief' => $brief, 'model' => publion_get_openai_model(), 'settings' => get_option( 'publion_post_settings', array() ), 'prompt' => get_option( 'publion_prompt', '' ) ) ) );
    $artifact = publion_get_queue_artifact( $id );
    if ( $hash === $artifact['hash'] && $artifact['html'] && true === publion_validate_article_content( $artifact['html'], $topic->topic, $brief['focus_keyword'] ?? '' ) ) {
        $GLOBALS['publion_article_research'] = $artifact['state']['research'] ?? array();
        return $artifact['html'];
    }
    $html = publion_generate_chatgpt_html( $topic->topic, $topic->category_label, $brief );
    if ( is_wp_error( $html ) ) { return $html; }
    $state = array( 'research' => $GLOBALS['publion_article_research'] ?? array(), 'images' => array(), 'stage' => 'article_validated', 'created_at' => gmdate( 'c' ) );
    if ( ! publion_save_queue_artifact( $id, $topic->topic, $html, $state, $hash ) ) { return new WP_Error( 'publion_claim_lost', 'Het geldige concept kon niet veilig worden vastgelegd; de generatieclaim is verlopen.' ); }
    return $html;
}

function publion_make_image_plan( $html, $topic, $category ) {
    preg_match_all( '/<h[2-3]\b[^>]*>(.*?)<\/h[2-3]>/is', publion_get_image_eligible_article_content( $html ), $matches );
    $headings = array_values( array_unique( array_filter( array_map( function ( $text ) { return sanitize_text_field( wp_strip_all_tags( $text ) ); }, $matches[1] ?? array() ) ) ) );
    $headings = array_values( array_filter( $headings, function ( $heading ) use ( $topic ) { return $heading !== $topic && ! preg_match( '/^(?:bronnen|sources|veelgestelde vragen|frequently asked questions)/iu', $heading ); } ) );
    $plan = array();
    $plan[] = array( 'role' => 'hero', 'subject' => $topic, 'alt' => 'Redactionele illustratie over ' . sanitize_text_field( $topic ), 'layout' => 'landscape', 'size' => publion_get_image_size_for_layout( 'landscape' ) );
    foreach ( array_slice( $headings, 0, 5 ) as $index => $heading ) {
        // No invented visual details or arbitrary forced alt uniqueness.
        $subject = $heading === $topic ? $topic : $topic . ': ' . $heading;
        $plan[] = array( 'role' => 'inline_' . ( $index + 1 ), 'subject' => $subject, 'alt' => 'Redactionele illustratie over ' . sanitize_text_field( $subject ), 'layout' => $index % 2 ? 'square' : 'landscape', 'size' => publion_get_image_size_for_layout( $index % 2 ? 'square' : 'landscape' ), 'heading' => $heading );
    }
    foreach ( $plan as &$slot ) {
        $slot['prompt'] = 'Maak een contextueel relevante redactionele foto-illustratie bij dit specifieke onderwerp: ' . $slot['subject'] . '. Categorie: ' . $category . '. Toon een concreet herkenbaar object of situatie passend bij deze beeldbrief, geen cijfers, labels, tekst, logos of watermerken. Vermijd ongefundeerde feitelijke claims of een misleidend diagram. Compositie: ' . $slot['layout'] . '. Geef geen andere artikelonderwerpen weer.';
    }
    unset( $slot );
    return $plan;
}

function publion_generate_queue_media( $topic, $html, $category, $api_key ) {
    $id = (int) $topic->id;
    $artifact = publion_get_queue_artifact( $id );
    $state = $artifact['state'];
    $limits = publion_get_pipeline_limits();
    $plan = publion_make_image_plan( $html, $topic->topic, $category );
    $state['image_plan'] = $plan;
    $state['images'] = is_array( $state['images'] ?? null ) ? $state['images'] : array();
    foreach ( $plan as $slot_index => $slot ) {
        if ( get_transient( publion_creation_cancellation_key( $id ) ) ) { return new WP_Error( 'publion_cancelled', 'Generatie geannuleerd. Concept en succesvolle assets zijn behouden; opnieuw proberen kan via Diagnose.' ); }
        $role = $slot['role'];
        $asset = $state['images'][$role] ?? array();
        $attachment = (int) ( $asset['attachment_id'] ?? 0 );
        if ( $attachment && publion_attachment_is_ready( $attachment ) ) { continue; }
        // Recover an uploaded asset after a process died before saving its queue slot.
        $recovered = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_publion_queue_id', 'value' => $id ), array( 'key' => '_publion_image_role', 'value' => $role ), array( 'key' => '_publion_brief_hash', 'value' => hash( 'sha256', $slot['subject'] ) ) ) ) );
        if ( $recovered && publion_attachment_is_ready( (int) $recovered[0] ) ) {
            $state['images'][$role] = array( 'status' => 'ready', 'attachment_id' => (int) $recovered[0], 'attempts' => (int) ( $asset['attempts'] ?? 1 ), 'brief' => $slot['subject'], 'alt' => $slot['alt'], 'alt_basis' => 'generation_brief_not_vision_verified' );
            continue;
        }
        $attempts = (int) ( $asset['attempts'] ?? 0 );
        while ( $attempts < $limits['image_attempts'] ) {
            if ( ! publion_renew_queue_claim( $id, $topic->topic ) ) { return new WP_Error( 'publion_claim_lost', 'Beeldgeneratieclaim verlopen; succesvolle assets blijven bewaard.' ); }
            $attempts++;
            publion_set_creation_progress( $id, 'running', 50 + (int) floor( 30 * $slot_index / max( 1, count( $plan ) ) ), 'Afbeelding: ' . $role, 'Poging ' . $attempts . ' van ' . $limits['image_attempts'] . '; geslaagde beeldslots worden hergebruikt.' );
            $state['images'][$role] = array( 'status' => 'requesting', 'attempts' => $attempts, 'brief' => $slot['subject'], 'alt_basis' => 'generation_brief_not_vision_verified' );
            if ( ! publion_save_queue_artifact( $id, $topic->topic, $artifact['html'], $state, $artifact['hash'] ) ) { return new WP_Error( 'publion_claim_lost', 'Beeldstatus kon niet veilig worden opgeslagen.' ); }
            // One API attempt per durable attempt; never multiply nested retries/costs.
            $one_attempt = function () { return 1; };
            $GLOBALS['publion_active_image_role'] = $role;
            add_filter( 'publion/openai_request_attempts', $one_attempt, PHP_INT_MAX );
            add_filter( 'publion/image_neutral_retry_enabled', '__return_false', PHP_INT_MAX );
            try { $result = publion_generate_and_upload_images( $slot['prompt'], 1, $slot['subject'], $api_key, $slot['size'] ); }
            finally { remove_filter( 'publion/openai_request_attempts', $one_attempt, PHP_INT_MAX ); remove_filter( 'publion/image_neutral_retry_enabled', '__return_false', PHP_INT_MAX ); unset( $GLOBALS['publion_active_image_role'] ); }
            $attachment = (int) ( $result['ids'][0] ?? 0 );
            $asset = array( 'status' => $attachment ? 'ready' : 'failed', 'attempts' => $attempts, 'attachment_id' => $attachment, 'brief' => $slot['subject'], 'alt' => $slot['alt'], 'alt_basis' => 'generation_brief_not_vision_verified', 'error' => $attachment ? '' : publion_redact_error( get_option( 'publion_last_image_error', 'Afbeelding niet ontvangen.' ) ), 'updated_at' => gmdate( 'c' ) );
            if ( $attachment ) { update_post_meta( $attachment, '_wp_attachment_image_alt', $slot['alt'] ); update_post_meta( $attachment, '_publion_queue_id', $id ); update_post_meta( $attachment, '_publion_image_role', $role ); }
            $state['images'][$role] = $asset;
            if ( ! publion_save_queue_artifact( $id, $topic->topic, $artifact['html'], $state, $artifact['hash'] ) ) { return new WP_Error( 'publion_claim_lost', 'Claim verlopen na beeldgeneratie; er wordt niet gepubliceerd.' ); }
            if ( $attachment ) { break; }
        }
    }
    $hero = (int) ( $state['images']['hero']['attachment_id'] ?? 0 );
    if ( ! $hero ) { foreach ( $state['images'] as $asset ) { if ( ! empty( $asset['attachment_id'] ) ) { $hero = (int) $asset['attachment_id']; $state['hero_promoted'] = true; break; } } }
    $all_ready = ! array_filter( $state['images'], function ( $asset ) { return 'ready' !== ( $asset['status'] ?? '' ); } );
    $requires_review = ( 'require_hero' === $limits['image_policy'] && ! $hero ) || ( 'require_all' === $limits['image_policy'] && ! $all_ready );
    $state['stage'] = $requires_review ? 'media_review_required' : 'media_ready';
    publion_save_queue_artifact( $id, $topic->topic, $artifact['html'], $state, $artifact['hash'] );
    $GLOBALS['publion_media_report'] = $state['images'];
    return array( 'html' => publion_render_queue_images( $html, $plan, $state['images'], $hero, $limits['hero_display'] ), 'hero_id' => $hero, 'requires_review' => $requires_review, 'images' => $state['images'] );
}

function publion_image_figure( $id, $layout, $hero = false ) {
    $attr = array( 'class' => 'publion-generated-image', 'loading' => $hero ? 'eager' : 'lazy', 'decoding' => 'async' );
    if ( $hero ) { $attr['fetchpriority'] = 'high'; }
    $image = wp_get_attachment_image( $id, 'large', false, $attr );
    return $image ? '<figure class="publion-article-media publion-article-media--' . esc_attr( $layout ) . ( $hero ? ' publion-leading-media' : '' ) . '">' . $image . '</figure>' : '';
}

function publion_render_queue_images( $html, $plan, $assets, $hero, $display ) {
    $used = array();
    if ( $hero ) { $used[$hero] = true; }
    // Add each ready inline asset once at its own heading, never at a byte percentage.
    foreach ( $plan as $slot ) {
        $id = (int) ( $assets[$slot['role']]['attachment_id'] ?? 0 );
        if ( ! $id || isset( $used[$id] ) || 'hero' === $slot['role'] ) { continue; }
        $inserted = false;
        $html = preg_replace_callback( '/<h[2-3]\b[^>]*>.*?<\/h[2-3]>/is', function ( $match ) use ( $slot, $id, &$inserted ) {
            if ( ! $inserted && sanitize_text_field( wp_strip_all_tags( $match[0] ) ) === $slot['heading'] ) { $inserted = true; return $match[0] . publion_image_figure( $id, $slot['layout'] ); }
            return $match[0];
        }, $html );
        if ( $inserted ) { $used[$id] = true; }
    }
    return ( 'inline' === $display && $hero ? publion_image_figure( $hero, 'landscape', true ) : '' ) . $html;
}

/** Inline-hero mode intentionally suppresses the duplicate theme thumbnail. */
add_filter( 'post_thumbnail_html', function ( $html, $post_id ) {
    return get_post_meta( $post_id, '_publion_queue_id', true ) && get_post_meta( $post_id, '_publion_hero_display', true ) === 'inline' ? '' : $html;
}, 99, 2 );

function publion_retry_draft_images( $post_id ) {
    global $wpdb;
    $post = get_post( $post_id );
    if ( ! $post || 'draft' !== $post->post_status || ! current_user_can( 'edit_post', $post_id ) ) { return new WP_Error( 'publion_media_retry_denied', 'Beeldherstel is alleen beschikbaar voor een bestaand bewerkbaar concept; live artikelen blijven ongemoeid.' ); }
    $id = (int) get_post_meta( $post_id, '_publion_queue_id', true );
    $topic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->publion_queue} WHERE id = %d", $id ) );
    if ( ! $topic || ! publion_acquire_generation_lock( $id, $topic->topic ) ) { return new WP_Error( 'publion_media_retry_busy', 'Deze job wordt al verwerkt.' ); }
    $token = $GLOBALS['publion_claim_tokens'][$id];
    $claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->publion_queue} SET status = 'processing', claim_token = %s, processing_started_at = %s WHERE id = %d AND status IN ('created','blocked','pending')", $token, current_time( 'mysql' ), $id ) );
    if ( 1 !== (int) $claimed ) { publion_release_generation_lock( $id, $topic->topic ); return new WP_Error( 'publion_media_retry_busy', 'Deze job is actief of afgerond.' ); }
    try {
        $GLOBALS['publion_active_queue_id'] = $id;
        $artifact = publion_get_queue_artifact( $id );
        $html = preg_replace( '/<figure\b[^>]*class=["\'][^"\']*publion-article-media[^"\']*["\'][^>]*>.*?<\/figure>/is', '', $post->post_content );
        $valid = publion_validate_article_content( $html, $post->post_title );
        if ( is_wp_error( $valid ) ) { return $valid; }
        foreach ( $artifact['state']['images'] ?? array() as $role => $asset ) { if ( empty( $asset['attachment_id'] ) ) { $artifact['state']['images'][$role]['attempts'] = 0; } }
        if ( ! publion_save_queue_artifact( $id, $topic->topic, $html, $artifact['state'], $artifact['hash'] ) ) { return new WP_Error( 'publion_claim_lost', 'Claim verlopen.' ); }
        $result = publion_generate_queue_media( $topic, $html, $topic->category_label, get_option( 'publion_api_key', '' ) );
        if ( is_wp_error( $result ) ) { return $result; }
        if ( ! publion_renew_queue_claim( $id, $topic->topic ) ) { return new WP_Error( 'publion_claim_lost', 'Claim verlopen; concept en assets blijven bewaard.' ); }
        $updated = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $result['html'] ), 'post_status' => 'draft' ), true );
        if ( is_wp_error( $updated ) ) { return $updated; }
        if ( $result['hero_id'] ) { set_post_thumbnail( $post_id, $result['hero_id'] ); }
        update_post_meta( $post_id, '_publion_hero_display', publion_get_pipeline_limits()['hero_display'] );
        update_post_meta( $post_id, '_publion_image_slots', $result['images'] );
        return true;
    } finally {
        publion_release_queue_claim( $id, $topic->topic, 'created' );
        unset( $GLOBALS['publion_active_queue_id'] );
    }
}

/** FAQ data must follow the current authored text after an editor changes it. */
add_action( 'save_post_post', function ( $id, $post, $update ) {
    if ( wp_is_post_revision( $id ) || ! get_post_meta( $id, '_publion_queue_id', true ) ) { return; }
    update_post_meta( $id, '_publion_faq_pairs', publion_extract_faq_pairs( $post->post_content ) );
}, 20, 3 );
