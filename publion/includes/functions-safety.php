<?php
/** Publication integrity is independent of optional SEO scores. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function publion_redact_error( $message ) {
    $message = preg_replace( '/(?:\bsk-[A-Za-z0-9_-]+\b|Bearer\s+\S+)/i', '[redacted]', (string) $message );
    $key = get_option( 'publion_api_key', '' );
    if ( is_string( $key ) && strlen( $key ) >= 8 ) { $message = str_replace( $key, '[redacted]', $message ); }
    return sanitize_text_field( $message );
}

function publion_effective_focus_keyword( $keyword, $topic ) {
    return trim( sanitize_text_field( is_scalar( $keyword ) ? (string) $keyword : '' ) ) ?: trim( sanitize_text_field( is_scalar( $topic ) ? (string) $topic : '' ) );
}

function publion_validate_generation_input( $topic, $brief = array() ) {
    if ( ! is_string( $topic ) || strlen( trim( $topic ) ) < 3 || strlen( $topic ) > 1000 || ! is_array( $brief ) ) {
        return new WP_Error( 'publion_invalid_input', __( 'Vul een concreet onderwerp en een geldige SEO-brief in voordat je opnieuw probeert.', 'publion' ) );
    }
    $keyword = publion_effective_focus_keyword( $brief['focus_keyword'] ?? '', $topic );
    if ( ! preg_match( '/\p{L}/u', $keyword ) || strlen( $keyword ) > 255 || publion_content_has_instruction_leak( $keyword ) ) {
        return new WP_Error( 'publion_invalid_input', __( 'De primaire zoekterm ontbreekt of is ongeldig. Controleer onderwerp en zoekterm.', 'publion' ) );
    }
    return true;
}

/** Specific generation failures, not broad words such as "foutmelding". */
function publion_content_has_instruction_leak( $html ) {
    $text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
    return (bool) preg_match( '/(?:de primaire zoekterm is leeg|de (?:primaire|exacte) zoekterm ontbreekt|controleer deze bronnen altijd voordat je publiceert|daardoor kan deze html niet voldoen|geef uitsluitend (?:valide |complete, geldige semantische |geldige )?html(?:-content| terug)|de eerste afbeelding moet het hoofdonderwerp zichtbaar tonen|===\s*(?:einde )?(?:html|webbronnen|actuele contentkaart)|SEO\/GEO-KWALITEITSCONTROLE:|\[\s*(?:insert (?:text|content)|voeg (?:tekst|inhoud) toe|placeholder|TODO)\s*\]|als (?:ai|taalmodel) kan ik|I (?:cannot|can\x27t) (?:fulfil|fulfill|assist with|provide this)|ik kan (?:deze opdracht|dit verzoek) niet (?:uitvoeren|verwerken))/iu', $text );
}

/** Reject incomplete API responses before any balancing or image requests. */
function publion_parse_article_response( $response ) {
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
        return new WP_Error( 'publion_invalid_response', __( 'OpenAI gaf ongeldige JSON terug; er is niets gepubliceerd.', 'publion' ) );
    }
    $choice = $data['choices'][0] ?? array();
    if ( ! empty( $choice['message']['refusal'] ) || 'stop' !== ( $choice['finish_reason'] ?? '' ) || ! is_string( $choice['message']['content'] ?? null ) ) {
        return new WP_Error( 'publion_incomplete_response', __( 'Het antwoord is geweigerd, onvolledig of afgebroken. Controleer het onderwerp en probeer opnieuw.', 'publion' ) );
    }
    return trim( $choice['message']['content'] );
}

function publion_validate_article_content( $html, $topic = '', $keyword = '' ) {
    if ( ! is_string( $html ) || '' === trim( $html ) || strlen( $html ) > 1000000 ) {
        return new WP_Error( 'publion_invalid_content', __( 'Er is geen bruikbaar artikel ontvangen.', 'publion' ) );
    }
    if ( publion_content_has_instruction_leak( $html ) || preg_match( '/(?:```|<\s*(?:script|iframe|object|embed|form|input|meta|head|body)\b|<[^>]+\son\w+\s*=|(?:href|src)\s*=\s*["\']\s*(?:javascript|data)\s*:)/i', $html ) ) {
        return new WP_Error( 'publion_instruction_leak', __( 'Het concept bevat een generatie-instructie, foutantwoord of onveilige markup. Review en herstel de tekst voordat je publiceert.', 'publion' ) );
    }
    // A fragment must have balanced explicit tags. DOM alone repairs truncated output.
    $stack = array();
    preg_match_all( '/<\s*(\/?)\s*([a-z][a-z0-9]*)\b[^>]*>/i', $html, $tags, PREG_SET_ORDER );
    foreach ( $tags as $tag ) {
        $name = strtolower( $tag[2] );
        if ( in_array( $name, array( 'img', 'br', 'hr', 'wbr', 'col' ), true ) ) { continue; }
        if ( $tag[1] ) {
            if ( array_pop( $stack ) !== $name ) {
                return new WP_Error( 'publion_malformed_html', __( 'HTML-tags zijn onvolledig of verkeerd genest. Herstel het concept.', 'publion' ) );
            }
        } else {
            $stack[] = $name;
        }
    }
    if ( $stack || ! class_exists( 'DOMDocument' ) ) {
        return new WP_Error( 'publion_malformed_html', __( 'HTML is onvolledig of de PHP DOM-extensie ontbreekt.', 'publion' ) );
    }
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors( true );
    $document->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );
    $xpath = new DOMXPath( $document );
    if ( $xpath->query( '//ul/*[not(self::li)] | //ol/*[not(self::li)] | //p/h2 | //p/h3 | //a/a' )->length ) {
        return new WP_Error( 'publion_malformed_html', __( 'De artikelstructuur bevat ongeldige lijsten of geneste blokken.', 'publion' ) );
    }
    // Sources, captions and navigation are not evidence of an authored article.
    foreach ( iterator_to_array( $xpath->query( '//div[contains(@class,"publion-research-sources")] | //p[contains(@class,"publion-external-source")] | //nav | //figure' ) ) as $node ) {
        $node->parentNode->removeChild( $node );
    }
    $words = preg_match_all( '/[\p{L}\p{N}]+/u', $document->textContent, $matches );
    if ( $words < 150 || $document->getElementsByTagName( 'p' )->length < 2 || $document->getElementsByTagName( 'h2' )->length < 1 ) {
        return new WP_Error( 'publion_insufficient_article', __( 'Het antwoord mist voldoende artikeltekst, alinea\'s of inhoudelijke koppen.', 'publion' ) );
    }
    // Lexical relevance is a modest structural guard, never a factual verification claim.
    $terms = publion_get_content_word_set( $topic . ' ' . $keyword );
    $article_terms = publion_get_content_word_set( $document->textContent );
    if ( $terms && ! array_intersect_key( $terms, $article_terms ) ) {
        return new WP_Error( 'publion_topic_mismatch', __( 'Het concept sluit niet aantoonbaar aan bij het onderwerp. Redactionele controle is nodig.', 'publion' ) );
    }
    return true;
}

function publion_record_queue_failure( $id, $topic, $error ) {
    global $wpdb;
    if ( ! publion_renew_queue_claim( $id, $topic ) ) { return; }
    $error = is_wp_error( $error ) ? $error : new WP_Error( 'publion_generation_failed', 'Generatie mislukt. Controleer de diagnose en probeer opnieuw.' );
    $entry = $wpdb->get_row( $wpdb->prepare( "SELECT attempts FROM {$wpdb->publion_queue} WHERE id = %d", absint( $id ) ) );
    $attempts = (int) ( $entry->attempts ?? 1 );
    $code = sanitize_key( $error->get_error_code() );
    $transient = in_array( $code, array( 'publion_openai_request_failed', 'publion_invalid_response', 'publion_incomplete_response', 'publion_post_write_failed', 'network', 'openai_limit' ), true );
    $retry = $transient && $attempts < 3;
    $error_data = $error->get_error_data();
    $delay = max( 300 * max( 1, $attempts ), min( DAY_IN_SECONDS, (int) ( is_array( $error_data ) ? ( $error_data['retry_after'] ?? 0 ) : 0 ) ) );
    $payload = publion_build_error_payload( $code, $error->get_error_message() );
    $wpdb->update( $wpdb->publion_queue, array( 'last_error' => wp_json_encode( $payload ), 'retry_after' => $retry ? wp_date( 'Y-m-d H:i:s', time() + $delay ) : null ), array( 'id' => absint( $id ), 'claim_token' => $GLOBALS['publion_claim_tokens'][absint( $id )] ?? '' ) );
    publion_release_queue_claim( $id, $topic, $retry ? 'pending' : 'blocked' );
    publion_cache_delete( 'queue_row_' . absint( $id ) );
    wp_cache_delete( 'next_pending_topic_v1', 'publion' );
}

/** Source policy never equates URL discovery or availability with claim support. */
function publion_source_review_required( $id = 0, $candidate_html = null ) {
    $policy = get_option( 'publion_source_policy', 'draft_on_warning' );
    $report = $id ? get_post_meta( $id, '_publion_safety_report', true ) : array();
    $research = $id ? ( $report['research'] ?? array() ) : ( $GLOBALS['publion_article_research'] ?? array() );
    // Hash approval binds the editor's review to this content, never a future rewrite.
    $post = $id ? get_post( $id ) : null;
    $current_html = null !== $candidate_html ? $candidate_html : ( $post ? $post->post_content : '' );
    if ( $post && get_post_meta( $id, '_publion_source_review_hash', true ) === hash( 'sha256', $current_html ) ) { return false; }
    if ( ! empty( $report['research']['enabled'] ) && ! empty( $report['content_hash'] ) && $report['content_hash'] !== hash( 'sha256', $current_html ) ) { return true; }
    return ( 'require_review' === $policy && ( ! $id || $report ) ) || ( 'draft_on_warning' === $policy && ! empty( $research['warning'] ) );
}

/** Runs for Publion drafts/manual publish, REST and future scheduling only. */
function publion_guard_post_publication( $data, $postarr, $unsanitized = array(), $update = false ) {
    $id = (int) ( $postarr['ID'] ?? 0 );
    $queue_id = $postarr['meta_input']['_publion_queue_id'] ?? ( $id ? get_post_meta( $id, '_publion_queue_id', true ) : 0 );
    if ( ! $queue_id || 'post' !== ( $data['post_type'] ?? 'post' ) || ! in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ) { return $data; }
    $old = $id ? get_post( $id ) : null;
    if ( $old && 'publish' === $old->post_status && wp_unslash( $data['post_content'] ) === $old->post_content ) { return $data; }
    $error = publion_validate_article_content( wp_unslash( $data['post_content'] ), wp_unslash( $data['post_title'] ), $id ? get_post_meta( $id, '_publion_focus_keyword', true ) : '' );
    if ( is_wp_error( $error ) ) {
        $data['post_status'] = 'draft';
        if ( $id ) { update_post_meta( $id, '_publion_publication_error', $error->get_error_message() ); }
    } elseif ( $id ) {
        if ( publion_source_review_required( $id, wp_unslash( $data['post_content'] ) ) ) {
            $data['post_status'] = 'draft';
            update_post_meta( $id, '_publion_publication_error', __( 'Broncontrole is nog nodig. Beoordeel de claims en bevestig de review in Publion Diagnose.', 'publion' ) );
        } else { delete_post_meta( $id, '_publion_publication_error' ); }
    }
    return $data;
}
add_filter( 'wp_insert_post_data', 'publion_guard_post_publication', PHP_INT_MAX, 4 );

function publion_guard_future_publication( $id ) {
    $post = get_post( $id );
    if ( ! $post || 'future' !== $post->post_status || ! get_post_meta( $id, '_publion_queue_id', true ) ) { return; }
    $valid = publion_validate_article_content( $post->post_content, $post->post_title, get_post_meta( $id, '_publion_focus_keyword', true ) );
    if ( is_wp_error( $valid ) ) {
        update_post_meta( $id, '_publion_publication_error', $valid->get_error_message() );
        wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
    } elseif ( publion_source_review_required( $id ) ) {
        update_post_meta( $id, '_publion_publication_error', __( 'Geplande publicatie wacht op bronreview.', 'publion' ) );
        wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
    }
}
add_action( 'publish_future_post', 'publion_guard_future_publication', 1 );

add_action( 'transition_post_status', function ( $new, $old, $post ) {
    if ( 'post' !== $post->post_type || $new === $old ) { return; }
    $id = (int) get_post_meta( $post->ID, '_publion_queue_id', true );
    if ( ! $id ) { return; }
    global $wpdb;
    publion_register_table_on_wpdb();
    $status = 'publish' === $new ? 'published' : 'created';
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->publion_queue} SET status = %s, published_at = CASE WHEN %s = 'publish' THEN %s ELSE NULL END WHERE id = %d AND status != 'processing'", $status, $new, current_time( 'mysql' ), $id ) );
    publion_cache_delete( 'queue_row_' . $id );
}, 20, 3 );

/** Persist and expose provenance without implying that a source verifies a claim. */
function publion_store_safety_report( $id, $html ) {
    $post = get_post( $id );
    $stored_html = $post ? $post->post_content : $html;
    update_post_meta( $id, '_publion_safety_report', wp_slash( array( 'version' => 1, 'checked_at' => gmdate( 'c' ), 'content_hash' => hash( 'sha256', $stored_html ), 'research' => $GLOBALS['publion_article_research'] ?? array(), 'claim_verification' => 'not_verified' ) ) );
}

add_action( 'add_meta_boxes_post', function ( $post ) {
    if ( get_post_meta( $post->ID, '_publion_queue_id', true ) ) {
        add_meta_box( 'publion-safety', __( 'Publion: publicatiecontrole', 'publion' ), 'publion_render_safety_report', 'post', 'normal', 'high' );
    }
} );
function publion_render_safety_report( $post ) {
    echo '<p>' . esc_html( get_post_meta( $post->ID, '_publion_publication_error', true ) ?: __( 'Publicatie controleert artikelstructuur en bekende generatielekken. Feiten en bronondersteuning vragen redactionele beoordeling.', 'publion' ) ) . '</p>';
    $report = get_post_meta( $post->ID, '_publion_safety_report', true );
    echo '<p>' . esc_html__( 'Bronstatus: gevonden of ingesteld; individuele claims zijn niet automatisch geverifieerd.', 'publion' ) . '</p>';
    foreach ( (array) ( $report['research']['sources'] ?? array() ) as $source ) {
        echo '<p><a href="' . esc_url( $source['url'] ?? '' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $source['title'] ?? $source['url'] ?? '' ) . '</a></p>';
    }
    if ( ! empty( $report['research']['warning'] ) ) { echo '<p>' . esc_html( $report['research']['warning'] ) . '</p>'; }
    if ( ! empty( $report['research']['evidence'] ) ) {
        echo '<details><summary>' . esc_html__( 'Opgehaald bronbewijs en claimmap', 'publion' ) . '</summary><pre style="white-space:pre-wrap">' . esc_html( wp_json_encode( array( 'evidence' => $report['research']['evidence'], 'claims' => $report['research']['claim_map'] ?? array(), 'review' => $report['research']['article_review'] ?? array(), 'failures' => $report['research']['fetch_failures'] ?? array() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>';
    }
    foreach ( (array) get_post_meta( $post->ID, '_publion_image_slots', true ) as $role => $slot ) {
        echo '<p>' . esc_html( $role . ': ' . ( $slot['status'] ?? 'unknown' ) . ' — ' . ( $slot['brief'] ?? '' ) . ( empty( $slot['error'] ) ? '' : ' — ' . $slot['error'] ) ) . '</p>';
    }
    echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=publion-diagnostics' ) ) . '">' . esc_html__( 'Open diagnose, dry-run en review', 'publion' ) . '</a></p>';
}


function publion_render_diagnostics() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Geen toegang.', 'publion' ), 403 ); }
    global $wpdb;
    publion_register_table_on_wpdb();
    echo '<div class="wrap"><h1>' . esc_html__( 'Publion: diagnose en review', 'publion' ) . '</h1><p>' . esc_html__( 'Deze controle wijzigt geen bestaande artikelen. Corrigeer een mislukt onderwerp en probeer het daarna opnieuw; bestaande posts worden hergebruikt.', 'publion' ) . '</p>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publion_safety_action"><input type="hidden" name="operation" value="policy">';
    wp_nonce_field( 'publion_safety_action' );
    echo '<label>' . esc_html__( 'Bronbeleid voor nieuwe artikelen', 'publion' ) . ' <select name="policy">';
    foreach ( array( 'draft_on_warning' => 'Concept bij mislukt brononderzoek (standaard)', 'require_review' => 'Nieuwe artikelen wachten altijd op bronreview', 'provenance_only' => 'Toon bronstatus; redactie beheert publicatie' ) as $value => $label ) {
        echo '<option value="' . esc_attr( $value ) . '" ' . selected( get_option( 'publion_source_policy', 'draft_on_warning' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select></label> <button class="button">' . esc_html__( 'Opslaan', 'publion' ) . '</button></form><h2>' . esc_html__( 'Mislukte jobs (maximaal 100)', 'publion' ) . '</h2>';
    $limits = publion_get_pipeline_limits();
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publion_safety_action"><input type="hidden" name="operation" value="limits">';
    wp_nonce_field( 'publion_safety_action' );
    foreach ( array( 'crawl_count' => array( 'Maximaal opgehaalde bronpagina\'s', 1, 12 ), 'crawl_timeout' => array( 'Seconden per bronpagina', 5, 30 ), 'image_attempts' => array( 'API-pogingen per beeldslot (betaald per aanvraag)', 1, 3 ) ) as $key => $field ) {
        echo '<p><label>' . esc_html( $field[0] ) . ' <input type="number" name="' . esc_attr( $key ) . '" min="' . (int) $field[1] . '" max="' . (int) $field[2] . '" value="' . (int) $limits[$key] . '"></label></p>';
    }
    echo '<p><label>' . esc_html__( 'Beeldbeleid', 'publion' ) . ' <select name="image_policy">';
    foreach ( array( 'optional' => 'Tekst mag publiceren als een beeld ontbreekt', 'require_hero' => 'Zonder hoofdbeeld bewaren als concept', 'require_all' => 'Zonder alle geplande beelden bewaren als concept' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $limits['image_policy'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
    echo '</select></label></p><p><label>' . esc_html__( 'Hoofdbeeld weergeven', 'publion' ) . ' <select name="hero_display">';
    foreach ( array( 'featured' => 'Uitgelicht beeld via thema (standaard)', 'inline' => 'Eerste beeld in artikel; dubbele themathumbnail onderdrukken' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $limits['hero_display'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
    echo '</select></label></p><p>' . esc_html__( 'Provider: ingestelde OpenAI-model. Bronophaling valideert toegang, relevantie en exacte quotes; claimondersteuning blijft een modelbeoordeling. Geen prijs- of rankinggaranties.', 'publion' ) . '</p><button class="button">' . esc_html__( 'Pipelinebudget opslaan', 'publion' ) . '</button></form>';
    $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->publion_queue} WHERE status = 'blocked' OR (status = 'pending' AND last_error IS NOT NULL) ORDER BY id DESC LIMIT 100" );
    foreach ( (array) $rows as $row ) {
        $error = json_decode( $row->last_error ?? '', true );
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publion_safety_action"><input type="hidden" name="operation" value="retry"><input type="hidden" name="id" value="' . absint( $row->id ) . '">';
        wp_nonce_field( 'publion_safety_action' );
        echo '<p>#' . absint( $row->id ) . ' ' . esc_html( $row->status ) . ' (' . absint( $row->attempts ?? 0 ) . ') — ' . esc_html( $error['message'] ?? 'Controleer onderwerp en zoekterm.' ) . '</p>';
        echo '<p><input aria-label="Onderwerp" name="topic" required maxlength="255" value="' . esc_attr( $row->topic ) . '"> <input aria-label="Zoekterm" name="keyword" maxlength="255" value="' . esc_attr( $row->focus_keyword ) . '"> <button class="button">' . esc_html__( 'Corrigeren en opnieuw inplannen', 'publion' ) . '</button></p></form>';
    }
    echo '<h2>' . esc_html__( 'Bestaande artikelen: dry-run (laatste 100)', 'publion' ) . '</h2><p>' . esc_html__( 'Bevindingen zijn voorstellen voor review; geen automatische wijziging, verwijdering of depublicatie. Bronreview bevestigt alleen je beoordeling van deze exacte tekst en publiceert niets.', 'publion' ) . '</p>';
    $posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => 100, 'meta_key' => '_publion_queue_id' ) );
    foreach ( $posts as $post ) {
        $valid = publion_validate_article_content( $post->post_content, $post->post_title, get_post_meta( $post->ID, '_publion_focus_keyword', true ) );
        echo '<p><a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( $post->post_title ) . '</a>: ' . esc_html( is_wp_error( $valid ) ? $valid->get_error_message() : 'Structurele controle geslaagd; feiten en beelden handmatig beoordelen.' ) . '</p>';
        if ( ! is_wp_error( $valid ) && current_user_can( 'edit_post', $post->ID ) ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publion_safety_action"><input type="hidden" name="operation" value="review"><input type="hidden" name="id" value="' . absint( $post->ID ) . '"><input type="hidden" name="content_hash" value="' . esc_attr( hash( 'sha256', $post->post_content ) ) . '">';
            wp_nonce_field( 'publion_safety_action' );
            echo '<button class="button">' . esc_html__( 'Claims en bronnen beoordeeld', 'publion' ) . '</button></form>';
            if ( 'draft' === $post->post_status ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publion_safety_action"><input type="hidden" name="operation" value="retry_images"><input type="hidden" name="id" value="' . absint( $post->ID ) . '">';
                wp_nonce_field( 'publion_safety_action' );
                echo '<button class="button">' . esc_html__( 'Ontbrekende beelden opnieuw proberen (concept)', 'publion' ) . '</button></form>';
            }
        }
    }
    echo '</div>';
}

add_action( 'admin_post_publion_safety_action', 'publion_handle_safety_action' );
function publion_handle_safety_action() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Onvoldoende rechten.' ); }
    check_admin_referer( 'publion_safety_action' );
    $operation = sanitize_key( $_POST['operation'] ?? '' );
    $id = absint( $_POST['id'] ?? 0 );
    if ( 'policy' === $operation ) {
        $policy = sanitize_key( $_POST['policy'] ?? '' );
        if ( in_array( $policy, array( 'draft_on_warning', 'require_review', 'provenance_only' ), true ) ) { update_option( 'publion_source_policy', $policy ); }
    } elseif ( 'limits' === $operation ) {
        $saved = array();
        foreach ( array( 'crawl_count', 'crawl_timeout', 'image_attempts' ) as $key ) { $saved[$key] = absint( $_POST[$key] ?? 0 ); }
        $saved['image_policy'] = sanitize_key( $_POST['image_policy'] ?? '' );
        $saved['hero_display'] = sanitize_key( $_POST['hero_display'] ?? '' );
        update_option( 'publion_pipeline_limits', $saved );
        update_option( 'publion_pipeline_limits', publion_get_pipeline_limits() );
    } elseif ( 'retry_images' === $operation ) {
        $result = publion_retry_draft_images( $id );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
    } elseif ( 'review' === $operation ) {
        $post = get_post( $id );
        if ( ! $post || ! get_post_meta( $id, '_publion_queue_id', true ) || ! current_user_can( 'edit_post', $id ) || ! hash_equals( hash( 'sha256', $post->post_content ), sanitize_text_field( wp_unslash( $_POST['content_hash'] ?? '' ) ) ) ) { wp_die( 'Het artikel is gewijzigd of ontbreekt. Beoordeel de huidige tekst opnieuw.' ); }
        update_post_meta( $id, '_publion_source_review_hash', hash( 'sha256', $post->post_content ) );
        delete_post_meta( $id, '_publion_publication_error' );
    } elseif ( 'retry' === $operation ) {
        global $wpdb;
        publion_register_table_on_wpdb();
        $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->publion_queue} WHERE id = %d", $id ) );
        if ( ! $entry || ! in_array( $entry->status, array( 'blocked', 'pending' ), true ) || publion_get_post_id_for_queue_entry( $entry ) ) { wp_die( 'Deze job is actief of heeft al een artikel. Open het bestaande artikel voor review.' ); }
        $topic = sanitize_text_field( wp_unslash( $_POST['topic'] ?? '' ) );
        $keyword = publion_effective_focus_keyword( wp_unslash( $_POST['keyword'] ?? '' ), $topic );
        $valid = publion_validate_generation_input( $topic, array( 'focus_keyword' => $keyword ) );
        if ( is_wp_error( $valid ) ) { wp_die( esc_html( $valid->get_error_message() ) ); }
        $wpdb->update( $wpdb->publion_queue, array( 'topic' => $topic, 'focus_keyword' => $keyword, 'status' => 'pending', 'attempts' => 0, 'last_error' => null, 'retry_after' => null, 'scheduled_at' => current_time( 'mysql' ) ), array( 'id' => $id, 'status' => $entry->status ) );
        publion_invalidate_pending_cache();
        publion_cache_delete( 'queue_row_' . $id );
        wp_cache_delete( 'next_pending_topic_v1', 'publion' );
    }
    wp_safe_redirect( admin_url( 'admin.php?page=publion-diagnostics' ) );
    exit;
}
