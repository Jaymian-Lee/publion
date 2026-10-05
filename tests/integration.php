<?php
/** Run with WP-CLI eval-file against a disposable WordPress installation only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'publion.test' !== wp_parse_url( home_url(), PHP_URL_HOST ) || 'Publion isolated tests' !== get_option( 'blogname' ) ) { throw new RuntimeException( 'Disposable publion.test WordPress required.' ); }
wp_set_current_user( get_user_by( 'login', 'publion_test_admin' )->ID );
global $results;
$results = array();
publion_register_table_on_wpdb();
$GLOBALS['test_http'] = array();
$GLOBALS['test_mode'] = 'good';
$GLOBALS['test_article'] = '';
$GLOBALS['test_mail'] = 0;
add_filter( 'pre_wp_mail', function () { $GLOBALS['test_mail']++; return true; } );
add_filter( 'publion/openai_retry_delay_microseconds', '__return_zero' );
add_filter( 'wp_doing_ajax', '__return_true' );
class Publion_Test_Die extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', function () { return function ( $message ) { throw new Publion_Test_Die( is_scalar( $message ) ? (string) $message : 'wp_die' ); }; } );

function response( $content, $finish = 'stop', $refusal = null ) {
    return array( 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'choices' => array( array( 'finish_reason' => $finish, 'message' => array( 'content' => $content, 'refusal' => $refusal ) ) ) ) ) );
}
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    $GLOBALS['test_http'][] = $url;
    if ( false !== strpos( $url, '/images/generations' ) ) {
        if ( 'image_first_fail' === $GLOBALS['test_mode'] && 1 === image_calls() ) { return new WP_Error( 'http_request_failed', 'timeout' ); }
        if ( 'image_fail' === $GLOBALS['test_mode'] ) { return new WP_Error( 'http_request_failed', 'timeout' ); }
        return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => array( array( 'b64_json' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=' ) ) ) ) );
    }
    if ( false !== strpos( $url, '/responses' ) ) {
        if ( 0 === strpos( $GLOBALS['test_mode'], 'evidence_' ) ) {
            return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'status' => 'completed', 'output' => array( array( 'type' => 'web_search_call', 'action' => array( 'sources' => array( array( 'url' => 'https://example.org/batteries', 'title' => 'Thuisbatterij' ), array( 'url' => 'https://example.org/traffic', 'title' => 'Achterzetraam verkeerslawaai' ) ) ) ), array( 'content' => array( array( 'type' => 'output_text', 'text' => 'Candidate research summary is not evidence.', 'annotations' => array( array( 'type' => 'url_citation', 'url' => 'https://example.org/traffic', 'title' => 'Achterzetraam verkeerslawaai' ) ) ) ) ) ) ) ) );
        }
        return new WP_Error( 'http_request_failed', 'timeout' );
    }
    if ( 0 === strpos( $url, 'https://example.org/' ) ) {
        if ( 'evidence_broken' === $GLOBALS['test_mode'] ) { return array( 'headers' => array(), 'response' => array( 'code' => 403 ), 'body' => 'Blocked' ); }
        $text = false !== strpos( $url, 'batteries' ) || 'evidence_unrelated' === $GLOBALS['test_mode'] ? str_repeat( 'Een thuisbatterij bewaart energie voor later gebruik. ', 5 ) : 'Een achterzetraam kan verkeerslawaai verminderen wanneer de kierdichting en ventilatie zorgvuldig worden uitgevoerd. ' . str_repeat( 'Een zorgvuldige uitvoering van het raam is van belang. ', 5 );
        $date = 'evidence_stale' === $GLOBALS['test_mode'] ? '<meta property="article:published_time" content="2010-01-01T00:00:00Z">' : '';
        return array( 'headers' => array( 'content-type' => 'text/html' ), 'response' => array( 'code' => 200 ), 'body' => '<html><head>' . $date . '<title>Bronpagina</title></head><body><p>' . $text . '</p></body></html>' );
    }
    if ( false !== strpos( $url, '/chat/completions' ) ) {
        $body = json_decode( $args['body'], true );
        $prompt = $body['messages'][1]['content'] ?? '';
        $system = $body['messages'][0]['content'] ?? '';
        if ( false !== strpos( $system, 'Je beoordeelt bronbewijs' ) ) {
            $quote = 'evidence_fake_quote' === $GLOBALS['test_mode'] ? 'Een achterzetraam verlaagt verkeerslawaai altijd met 99 procent.' : 'Een achterzetraam kan verkeerslawaai verminderen wanneer de kierdichting en ventilatie zorgvuldig worden uitgevoerd.';
            return response( wp_json_encode( array( 'claims' => array( array( 'claim' => 'Kierdichting en ventilatie bepalen het effect op verkeerslawaai.', 'url' => 'https://example.org/traffic', 'quote' => $quote, 'confidence' => 'high', 'source_type' => 'primary' ) ), 'contradictions' => 'evidence_contradiction' === $GLOBALS['test_mode'] ? array( 'Bronnen spreken elkaar tegen.' ) : array(), 'limitations' => 'evidence_stale' === $GLOBALS['test_mode'] ? array( 'Oude publicatiedatum; actualiteit onzeker.' ) : array() ) ) );
        }
        if ( false !== strpos( $system, 'Je reviewt feitelijke ondersteuning' ) ) {
            return response( wp_json_encode( array( 'supported' => 'evidence_unsupported' !== $GLOBALS['test_mode'], 'unsupported_claims' => 'evidence_unsupported' === $GLOBALS['test_mode'] ? array( 'Een ongefundeerde claim.' ) : array(), 'contradictions' => array() ) ) );
        }
        if ( false !== strpos( $prompt, 'Extraheer 10' ) ) { return response( '[]' ); }
        if ( false !== strpos( $prompt, 'unieke blogonderwerpen voor' ) ) { return response( "Achterzetraam tegen verkeerslawaai\nSlimme thermostaat instellen\nVentilatierooster reinigen\nThuisbatterij in huurwoning\nKierdichting bij kozijnen" ); }
        if ( false !== strpos( $prompt, 'Herschrijf uitsluitend' ) && 'bad_repair' === $GLOBALS['test_mode'] ) { return response( '<p>De primaire zoekterm is leeg.</p>' ); }
        if ( 'rate_limit' === $GLOBALS['test_mode'] ) { return array( 'headers' => array(), 'response' => array( 'code' => 429 ), 'body' => '{"error":{"message":"Rate limit"}}' ); }
        if ( 'auth_fail' === $GLOBALS['test_mode'] ) { return array( 'headers' => array(), 'response' => array( 'code' => 401 ), 'body' => '{}' ); }
        if ( 'timeout' === $GLOBALS['test_mode'] ) { return new WP_Error( 'http_request_failed', 'timeout' ); }
        if ( 'malformed_json' === $GLOBALS['test_mode'] ) { return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => '{' ); }
        if ( 'refusal' === $GLOBALS['test_mode'] ) { return response( $GLOBALS['test_article'], 'stop', 'No' ); }
        if ( 'truncated' === $GLOBALS['test_mode'] ) { return response( $GLOBALS['test_article'], 'length' ); }
        if ( 'steal_claim' === $GLOBALS['test_mode'] ) {
            global $wpdb;
            $wpdb->query( "UPDATE {$wpdb->publion_queue} SET claim_token = 'replacement' WHERE status = 'processing'" );
        }
        return response( $GLOBALS['test_article'] );
    }
    // No real network request may leave this isolated test suite.
    return new WP_Error( 'test_network_blocked', 'Unexpected URL blocked by integration harness.' );
}, PHP_INT_MAX, 3 );

function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function test( $name, $callback ) {
    global $results;
    try { $callback(); $results[] = array( $name, 'PASS' ); echo "PASS $name\n"; }
    catch ( Throwable $error ) { $results[] = array( $name, 'FAIL', $error->getMessage() ); echo "FAIL $name: {$error->getMessage()}\n"; }
}
function reset_case() {
    global $wpdb;
    foreach ( get_posts( array( 'post_type' => array( 'post', 'attachment' ), 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
    $wpdb->query( "DELETE FROM {$wpdb->publion_queue}" );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'publion_gen_lock_%'" );
    wp_cache_flush();
    $GLOBALS['publion_claim_tokens'] = array();
    $GLOBALS['test_mode'] = 'good'; $GLOBALS['test_http'] = array();
    $GLOBALS['publion_article_research'] = array();
    unset( $GLOBALS['publion_active_queue_id'] );
    update_option( 'publion_pipeline_limits', array() );
    update_option( 'publion_api_key', 'test-key-no-live-access' );
    update_option( 'publion_openai_model', 'configured-test-model' );
    update_option( 'publion_openai_image_model', 'configured-test-image' );
    update_option( 'publion_source_policy', 'draft_on_warning' );
    update_option( 'publion_post_settings', array( 'post_status' => 'publish', 'rank_math_integration' => 'no', 'web_research_enabled' => 'no', 'cta_enabled' => 'no' ) );
    wp_set_current_user( get_user_by( 'login', 'publion_test_admin' )->ID );
    $_POST = array();
}
function article( $topic = 'Achterzetraam tegen verkeerslawaai' ) {
    return '<h2>' . esc_html( $topic ) . '</h2><p>' . esc_html( $topic ) . ' helpt alleen als de uitvoering aansluit op de woning. ' . str_repeat( 'Controleer de kierdichting en bespreek de ventilatie voordat je een keuze maakt. ', 10 ) . '</p><h2>Praktische afwegingen</h2><p>' . str_repeat( 'Een raam vraagt onderhoud en een zorgvuldige montage. Houd voldoende ruimte over en vergelijk de mogelijkheden in de bestaande situatie. ', 10 ) . '</p>';
}
function queue( $topic = 'Achterzetraam tegen verkeerslawaai', $keyword = '' ) {
    global $wpdb;
    $category = get_term_by( 'slug', 'uncategorized', 'category' );
    $wpdb->insert( $wpdb->publion_queue, array( 'topic' => $topic, 'focus_keyword' => $keyword, 'category_id' => $category->term_id, 'category_label' => $category->name, 'status' => 'pending', 'scheduled_at' => current_time( 'mysql' ) ) );
    return (int) $wpdb->insert_id;
}
function ajax( $id, $with_nonce = true ) {
    $_POST = array( 'id' => $id );
    if ( $with_nonce ) { $_POST['nonce'] = wp_create_nonce( 'publion_nonce' ); }
    $_REQUEST = $_POST;
    ob_start();
    try { publion_create_post_now(); } catch ( Publion_Test_Die $error ) {}
    $output = ob_get_clean();
    return json_decode( $output, true );
}
function row( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->publion_queue} WHERE id = %d", $id ) ); }
function image_calls() { return count( array_filter( $GLOBALS['test_http'], function ( $url ) { return false !== strpos( $url, '/images/generations' ); } ) ); }

test( 'legacy missing/null/empty/whitespace keyword fallback', function () {
    foreach ( array( null, '', '   ', '<script></script>' ) as $keyword ) { check( 'Raamgeluid' === publion_effective_focus_keyword( $keyword, 'Raamgeluid' ), 'Empty keyword not recovered' ); }
    check( is_wp_error( publion_validate_generation_input( '  ', array() ) ), 'Empty topic accepted' );
    check( is_wp_error( publion_validate_generation_input( '123', array() ) ), 'Non-topic accepted' );
} );
test( 'exact primary-keyword diagnostic, source editor note and alt instructions rejected', function () {
    foreach ( array( 'De primaire zoekterm is leeg. Daardoor kan deze HTML niet voldoen aan de eis om een exacte primaire zoekterm natuurlijk tussen 1,0% en 1,5% van de zichtbare tekst te verwerken, inclusief de eerste alinea en een beschrijvende kop.', 'Controleer deze bronnen altijd voordat je publiceert.', 'Geef uitsluitend valide HTML-content terug, zonder uitleg.' ) as $bad ) {
        check( is_wp_error( publion_validate_article_content( '<h2>Artikel</h2><p>' . $bad . '</p>' ) ), 'Known incident accepted' );
        check( '' === publion_build_descriptive_image_alt( $bad ), 'Bad alt retained' );
    }
} );
test( 'valid error-message article and natural low density accepted', function () {
    $html = article( 'Foutmelding op slimme thermostaat' );
    check( true === publion_validate_article_content( $html, 'Foutmelding op slimme thermostaat', 'thermostaat' ), 'Legitimate error topic blocked' );
    check( false === strpos( publion_get_rank_math_generation_instruction( 'thermostaat', true ), '1,5%' ), 'Density quota still prompted' );
    $report = publion_get_rank_math_quality_report( $html, 'thermostaat', 'Foutmelding op slimme thermostaat' );
    check( ! in_array( 'focus_keyword_density', $report['failed_required'], true ), 'Density still hard requirement' );
} );
test( 'malformed/incomplete/unsafe HTML and unrelated topic rejected', function () {
    foreach ( array( '<p>tekst', '<h2>Kop</h2><p>x</h3>', article() . '<script>alert(1)</script>', article() . '<ul><h2>Invalid</h2></ul>', '<h2>Bronnen</h2><p>Te kort.</p>', article( 'Belastingaangifte' ) ) as $index => $html ) {
        $topic = 5 === $index ? 'Bananen smoothie gezond ontbijt' : 'Achterzetraam';
        check( is_wp_error( publion_validate_article_content( $html, $topic ) ), 'Invalid fixture accepted #' . $index );
    }
} );
test( 'JSON finish_reason/refusal/nonstring gate', function () {
    foreach ( array( response( article(), 'length' ), response( article(), 'content_filter' ), response( article(), 'stop', 'Refused' ), response( array( 'text' ) ), array( 'body' => '{' ) ) as $bad ) { check( is_wp_error( publion_parse_article_response( $bad ) ), 'Invalid API answer accepted' ); }
    check( article() === publion_parse_article_response( response( article() ) ), 'Complete API answer lost' );
} );
foreach ( array( 'manual', 'cron' ) as $path ) {
    test( "$path happy path empty keyword draft->metadata->publish idempotency", function () use ( $path ) {
        reset_case(); $id = queue(); $GLOBALS['test_article'] = article();
        if ( 'manual' === $path ) { $response = ajax( $id ); check( $response['success'] ?? false, 'Manual generation failed: ' . wp_json_encode( $response ) ); } else { ( new Publion_Cron() )->maybe_create_queued_post(); }
        $post_id = publion_get_post_id_for_queue_entry( row( $id ) );
        check( $post_id > 0 && 'publish' === get_post_status( $post_id ), 'Good article did not publish' );
        check( 'published' === row( $id )->status, 'Queue status incorrect: ' . wp_json_encode( row( $id ) ) );
        check( count( publion_make_image_plan( article(), row( $id )->topic, 'Wonen' ) ) === image_calls(), 'Unexpected media plan requests' );
        $post = get_post( $post_id );
        check( true === publion_validate_article_content( $post->post_content, $post->post_title ), 'Final assembled HTML invalid' );
        check( false === strpos( $post->post_content, 'Controleer de kierdichting' . ': ' ), 'Alt copied raw paragraph' );
        check( is_array( get_post_meta( $post_id, '_publion_safety_report', true ) ), 'Missing provenance' );
        $count = count( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => -1 ) ) );
        if ( 'manual' === $path ) { ajax( $id ); } else { ( new Publion_Cron() )->maybe_create_queued_post(); }
        check( $count === count( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => -1 ) ) ), 'Retry duplicated a post' );
    } );
    foreach ( array( 'diagnostic', 'refusal', 'truncated', 'malformed_json', 'auth_fail', 'timeout', 'rate_limit' ) as $mode ) {
        test( "$path $mode fails before image/publication", function () use ( $path, $mode ) {
            reset_case(); $id = queue(); $GLOBALS['test_mode'] = $mode;
            $GLOBALS['test_article'] = 'diagnostic' === $mode ? '<p>De primaire zoekterm is leeg.</p>' : article();
            if ( 'manual' === $path ) { ajax( $id ); } else { ( new Publion_Cron() )->maybe_create_queued_post(); }
            check( 0 === image_calls(), 'Invalid output triggered image costs' );
            check( ! publion_get_post_id_for_queue_entry( row( $id ) ), 'Invalid post written' );
            $retryable = in_array( $mode, array( 'refusal', 'truncated', 'malformed_json', 'timeout', 'rate_limit' ), true );
            check( ( $retryable ? 'pending' : 'blocked' ) === row( $id )->status, 'Wrong failure status ' . row( $id )->status );
            check( ! empty( row( $id )->last_error ), 'No durable error reason' );
        } );
    }
}
test( 'invalid repair preserves original valid article', function () {
    reset_case(); $GLOBALS['test_mode'] = 'bad_repair'; $GLOBALS['test_article'] = article();
    update_option( 'publion_post_settings', array( 'rank_math_integration' => 'yes', 'rank_math_auto_repair' => 'yes', 'rank_math_add_toc' => 'no' ) );
    $html = publion_generate_chatgpt_html( 'Achterzetraam tegen verkeerslawaai', 'Wonen', array() );
    check( is_string( $html ) && false !== strpos( $html, 'Praktische afwegingen' ), 'Original lost after invalid repair' );
    check( false === strpos( $html, 'De primaire zoekterm is leeg' ), 'Repair diagnostic leaked' );
} );
test( 'Rank Math gate independent of publish preference', function () {
    reset_case();
    check( publion_get_rank_math_settings( array( 'post_status' => 'publish', 'rank_math_publish_gate' => 'yes' ) )['publish_gate'], 'Explicit publish bypassed SEO setting' );
    foreach ( array( 'yes', 'no' ) as $rank_math ) { foreach ( array( 'yes', 'no' ) as $gate ) {
        update_option( 'publion_post_settings', array( 'rank_math_integration' => $rank_math, 'rank_math_publish_gate' => $gate ) );
        $id = wp_insert_post( array( 'post_title' => 'Incident', 'post_content' => '<p>De primaire zoekterm is leeg.</p>', 'post_status' => 'publish', 'meta_input' => array( '_publion_queue_id' => 123 ) ) );
        check( 'draft' === get_post_status( $id ), 'Unsafe published despite content guard' );
    } }
} );
test( 'manual publish and scheduler revalidate only Publion posts', function () {
    reset_case();
    $id = wp_insert_post( array( 'post_title' => 'Incident', 'post_content' => '<p>De primaire zoekterm is leeg.</p>', 'post_status' => 'draft', 'meta_input' => array( '_publion_queue_id' => 321 ) ) );
    wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
    check( 'draft' === get_post_status( $id ), 'Manual publish bypassed validation' );
    global $wpdb;
    $wpdb->update( $wpdb->posts, array( 'post_status' => 'future' ), array( 'ID' => $id ) ); clean_post_cache( $id );
    publion_guard_future_publication( $id );
    check( 'draft' === get_post_status( $id ), 'Future publication bypassed validation' );
    $normal = wp_insert_post( array( 'post_title' => 'Site-managed post', 'post_content' => '<p>Short ordinary content</p>', 'post_status' => 'publish' ) );
    check( 'publish' === get_post_status( $normal ), 'Unrelated site post blocked' );
} );
test( 'nonce missing/invalid and capability rejection', function () {
    reset_case(); $id = queue(); $GLOBALS['test_article'] = article();
    ajax( $id, false ); check( 'pending' === row( $id )->status && 0 === count( $GLOBALS['test_http'] ), 'Missing nonce accepted' );
    $_POST = array( 'id' => $id, 'nonce' => 'invalid' ); ob_start(); try { publion_create_post_now(); } catch ( Publion_Test_Die $e ) {} ob_end_clean();
    check( 0 === count( $GLOBALS['test_http'] ), 'Invalid nonce accepted' );
    wp_set_current_user( 0 ); ajax( $id ); check( 0 === count( $GLOBALS['test_http'] ), 'Unauthorized user generated' );
} );
test( 'parallel claim and expired owner cannot renew/delete replacement', function () {
    reset_case(); $id = queue();
    check( publion_claim_queue_entry( $id, row( $id )->topic ), 'First claim failed' );
    $old = $GLOBALS['publion_claim_tokens'][$id];
    check( ! publion_claim_queue_entry( $id, row( $id )->topic ), 'Concurrent claim accepted' );
    $name = publion_generation_lock_option_name( row( $id )->topic );
    update_option( $name, array( 'topic_id' => $id, 'started_at' => time() - 1900, 'token' => $old ) );
    global $wpdb; $wpdb->update( $wpdb->publion_queue, array( 'processing_started_at' => wp_date( 'Y-m-d H:i:s', time() - 1900 ) ), array( 'id' => $id ) );
    check( publion_claim_queue_entry( $id, row( $id )->topic ), 'Expired work not recovered' );
    $new = $GLOBALS['publion_claim_tokens'][$id]; check( $new !== $old, 'Lease token reused' );
    $GLOBALS['publion_claim_tokens'][$id] = $old;
    check( ! publion_renew_queue_claim( $id, row( $id )->topic ), 'Stale owner renewed' );
    publion_release_generation_lock( $id, row( $id )->topic );
    check( $new === get_option( $name )['token'], 'Old attempt deleted new lock' );
} );
test( 'precommit owner loss cannot insert post', function () {
    reset_case(); $id = queue(); $GLOBALS['test_article'] = article(); $GLOBALS['test_mode'] = 'steal_claim';
    ajax( $id ); check( ! publion_get_post_id_for_queue_entry( row( $id ) ), 'Old attempt saved post after losing ownership' );
} );
test( 'bounded retry and poison job does not starve next job', function () {
    reset_case(); $bad = queue(); $GLOBALS['test_mode'] = 'timeout'; $GLOBALS['test_article'] = article();
    global $wpdb;
    for ( $i = 0; $i < 3; $i++ ) { $wpdb->update( $wpdb->publion_queue, array( 'retry_after' => null ), array( 'id' => $bad ) ); wp_cache_flush(); ( new Publion_Cron() )->maybe_create_queued_post(); }
    check( 'blocked' === row( $bad )->status && 3 === (int) row( $bad )->attempts, 'Retry not bounded' );
    $good = queue( 'Slimme thermostaat instellen', 'thermostaat' ); $GLOBALS['test_mode'] = 'good'; $GLOBALS['test_article'] = article( 'Slimme thermostaat instellen' ); wp_cache_flush();
    ( new Publion_Cron() )->maybe_create_queued_post(); check( 'published' === row( $good )->status, 'Poison job starved later jobs' );
} );
test( 'crash after insert recovers linked draft without new API requests', function () {
    reset_case(); $id = queue();
    $post_id = wp_insert_post( array( 'post_title' => row( $id )->topic, 'post_content' => article(), 'post_status' => 'draft', 'meta_input' => array( '_publion_queue_id' => $id ) ) );
    ( new Publion_Cron() )->maybe_create_queued_post();
    check( 'created' === row( $id )->status && $post_id === publion_get_post_id_for_queue_entry( row( $id ) ), 'Linked draft not recovered' );
    check( 0 === count( $GLOBALS['test_http'] ), 'Crash retry regenerated' );
} );
test( 'source warning keeps valid concept for hash-bound manual review', function () {
    reset_case(); $id = queue(); $GLOBALS['test_article'] = article();
    update_option( 'publion_post_settings', array( 'post_status' => 'publish', 'web_research_enabled' => 'yes', 'web_research_failure_mode' => 'continue' ) );
    ( new Publion_Cron() )->maybe_create_queued_post();
    $post_id = publion_get_post_id_for_queue_entry( row( $id ) ); check( $post_id && 'draft' === get_post_status( $post_id ), 'Research warning auto-published' );
    check( publion_source_review_required( $post_id ), 'Source warning not visible' );
    update_post_meta( $post_id, '_publion_source_review_hash', hash( 'sha256', get_post( $post_id )->post_content ) );
    check( ! publion_source_review_required( $post_id ), 'Exact-content review ignored' );
    wp_update_post( array( 'ID' => $post_id, 'post_content' => article() . '<p>Gewijzigde tekst.</p>' ) );
    check( publion_source_review_required( $post_id ), 'Review survived changed content' );
} );
test( 'sources only cited URLs no public editorial instructions', function () {
    $url = 'https://example.org/relevant';
    $html = publion_append_web_research_sources( article() . '<p><a href="' . $url . '">Onderzoek</a></p>', array( array( 'url' => $url, 'title' => 'Onderzoek' ), array( 'url' => 'https://example.org/battery', 'title' => 'Batterij' ) ) );
    check( false === strpos( $html, 'voordat je publiceert' ), 'Editorial instruction emitted' );
    check( false === strpos( $html, '/battery' ), 'Uncited unrelated source appended' );
    check( article() === publion_ensure_configured_external_reference( article(), array( $url ) ), 'Unrelated reference forced' );
    check( false === strpos( publion_validate_links_in_html( '<p><a href="https://127.0.0.1/private">Nepbron</a></p>' ), 'href' ), 'Unapproved external URL retained' );
} );
test( 'image failure omits placeholders without dropping valid article', function () {
    reset_case(); $id = queue(); $GLOBALS['test_article'] = article(); $GLOBALS['test_mode'] = 'image_fail';
    ( new Publion_Cron() )->maybe_create_queued_post();
    $post = get_post( publion_get_post_id_for_queue_entry( row( $id ) ) );
    check( $post && 'publish' === $post->post_status && false === strpos( $post->post_content, 'image-placeholder' ), 'Image failure damaged article' );
} );
test( 'model choices retained and invalid model no fallback', function () {
    reset_case(); check( 'configured-test-model' === publion_get_openai_model(), 'Selected model changed' );
    update_option( 'publion_openai_model', '<invalid>' );
    check( '' === publion_get_openai_model(), 'Silent fallback' );
    check( is_wp_error( publion_generate_chatgpt_html( 'Achterzetraam', 'Wonen' ) ), 'Invalid model ignored' );
    check( 0 === count( $GLOBALS['test_http'] ), 'Invalid model made network request' );
} );
test( 'secrets redacted and entities preserved', function () {
    reset_case(); check( false === strpos( publion_redact_error( 'Bearer abc123 sk-secret test-key-no-live-access' ), 'test-key' ), 'Secret leaked' );
    check( '<p>R&amp;D &lt; 10</p>' === publion_clean_html_output( '<p>R&amp;D &lt; 10</p>' ), 'Entities corrupted' );
} );
test( 'first hero failure retries bounded and persists specific roles/alt briefs', function () {
    reset_case(); $id = queue(); $GLOBALS['test_article'] = article(); $GLOBALS['test_mode'] = 'image_first_fail';
    ( new Publion_Cron() )->maybe_create_queued_post();
    $post_id = publion_get_post_id_for_queue_entry( row( $id ) );
    $slots = get_post_meta( $post_id, '_publion_image_slots', true );
    check( 'ready' === $slots['hero']['status'] && 2 === $slots['hero']['attempts'], 'Failed first slot not retried' );
    check( (int) $slots['hero']['attachment_id'] === (int) get_post_thumbnail_id( $post_id ), 'Hero role incorrectly mapped' );
    check( count( $slots ) + 1 === image_calls(), 'Nested retries exceeded budget' );
    check( count( array_unique( array_column( $slots, 'alt' ) ) ) === count( $slots ), 'Distinct visual briefs produced duplicate alt' );
    foreach ( $slots as $slot ) { check( 'generation_brief_not_vision_verified' === $slot['alt_basis'], 'Fake vision verification' ); }
} );
test( 'asset reuse after interrupted media stage and inline hero responsive markup', function () {
    reset_case(); $id = queue(); $topic = row( $id ); $GLOBALS['test_article'] = article();
    update_option( 'publion_pipeline_limits', array( 'hero_display' => 'inline' ) );
    check( publion_claim_queue_entry( $id, $topic->topic ), 'Claim failed' );
    $html = publion_generate_queue_article( $topic, array( 'focus_keyword' => $topic->topic ) );
    $media = publion_generate_queue_media( $topic, $html, 'Wonen', get_option( 'publion_api_key' ) );
    $calls = image_calls();
    $again = publion_generate_queue_media( $topic, $html, 'Wonen', get_option( 'publion_api_key' ) );
    check( $calls === image_calls(), 'Good assets regenerated' );
    check( $media['hero_id'] === $again['hero_id'], 'Hero identity changed on retry' );
    check( 0 === strpos( $again['html'], '<figure' ) && false !== strpos( $again['html'], 'fetchpriority="high"' ) && false !== strpos( $again['html'], 'width="' ), 'Leading media/WordPress dimensions missing' );
    $post_id = wp_insert_post( array( 'post_title' => $topic->topic, 'post_content' => $again['html'], 'post_status' => 'draft', 'meta_input' => array( '_publion_queue_id' => $id, '_publion_hero_display' => 'inline' ) ) );
    check( '' === apply_filters( 'post_thumbnail_html', '<img src="duplicate">', $post_id, $again['hero_id'], 'large', array() ), 'Duplicate theme hero retained' );
    publion_release_queue_claim( $id, $topic->topic, 'created' );
} );
test( 'draft media retry preserves good assets and rejects live post changes', function () {
    reset_case(); $id = queue(); $GLOBALS['test_article'] = article(); $GLOBALS['test_mode'] = 'image_fail';
    update_option( 'publion_pipeline_limits', array( 'image_policy' => 'require_all' ) );
    ( new Publion_Cron() )->maybe_create_queued_post();
    $post_id = publion_get_post_id_for_queue_entry( row( $id ) ); check( 'draft' === get_post_status( $post_id ), 'Required image policy ignored' );
    $GLOBALS['test_mode'] = 'good'; $GLOBALS['test_http'] = array();
    check( true === publion_retry_draft_images( $post_id ), 'Draft missing media retry failed' );
    $after = image_calls(); check( $after > 0, 'Missing images not attempted' );
    check( true === publion_retry_draft_images( $post_id ), 'Second draft retry failed' );
    check( image_calls() === $after, 'Good draft assets regenerated' );
    wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
    check( is_wp_error( publion_retry_draft_images( $post_id ) ), 'Live post media changed' );
} );
test( 'public-source URL gate rejects SSRF credentials ports and local networks', function () {
    foreach ( array( 'http://example.org/', 'https://127.0.0.1/', 'https://10.0.0.1/', 'https://[::1]/', 'https://169.254.169.254/latest/', 'https://user:password@example.org/', 'https://example.org:8443/', 'https://host.internal/' ) as $url ) { check( ! publion_is_public_source_url( $url ), 'Unsafe source allowed ' . $url ); }
    check( publion_is_public_source_url( 'https://example.org/evidence' ), 'Public source blocked' );
} );
foreach ( array( 'good', 'broken', 'unrelated', 'fake_quote', 'contradiction', 'stale', 'unsupported' ) as $evidence_mode ) {
    test( 'evidence-first source pipeline: ' . $evidence_mode, function () use ( $evidence_mode ) {
        reset_case(); $id = queue(); $GLOBALS['test_mode'] = 'evidence_' . $evidence_mode;
        $GLOBALS['test_article'] = article() . '<p><a href="https://example.org/traffic">Bron over verkeerslawaai</a></p>';
        update_option( 'publion_post_settings', array( 'post_status' => 'publish', 'web_research_enabled' => 'yes', 'web_research_failure_mode' => 'continue' ) );
        ( new Publion_Cron() )->maybe_create_queued_post();
        $post_id = publion_get_post_id_for_queue_entry( row( $id ) );
        check( $post_id > 0, 'Valid reviewable draft lost' );
        check( ( 'good' === $evidence_mode ? 'publish' : 'draft' ) === get_post_status( $post_id ), 'Wrong evidence publication outcome for ' . $evidence_mode );
        $report = get_post_meta( $post_id, '_publion_safety_report', true );
        check( 'not_verified' === $report['claim_verification'], 'Automatic fact verification falsely claimed' );
        check( false === strpos( get_post( $post_id )->post_content, '/batteries' ), 'Unrelated consulted source leaked' );
        if ( 'good' === $evidence_mode ) {
            check( 1 === count( $report['research']['evidence'] ) && 1 === count( $report['research']['claim_map']['claims'] ), 'Variable relevant evidence selection failed' );
            check( $report['research']['claim_map']['claims'][0]['quote_match'], 'Literal quote was not matched' );
            check( ! empty( $report['research']['evidence'][0]['content_sha256'] ) && ! empty( $report['research']['evidence'][0]['fetched_at'] ), 'Missing traceable evidence provenance' );
        }
        if ( 'stale' === $evidence_mode ) { check( 0 === strpos( $report['research']['evidence'][0]['published_at'], '2010' ), 'Page-declared date omitted' ); }
    } );
}
test( 'FAQ structured data refresh after content edit', function () {
    reset_case();
    $id = wp_insert_post( array( 'post_title' => 'Achterzetraam', 'post_status' => 'draft', 'post_content' => article() . '<h2>Veelgestelde vragen</h2><h3>Hoe werkt het?</h3><p>Eerste antwoord.</p>', 'meta_input' => array( '_publion_queue_id' => 23 ) ) );
    check( 'Eerste antwoord.' === get_post_meta( $id, '_publion_faq_pairs', true )[0]['answer'], 'FAQ not created' );
    wp_update_post( array( 'ID' => $id, 'post_content' => article() . '<h2>Veelgestelde vragen</h2><h3>Hoe werkt het?</h3><p>Nieuw antwoord.</p>' ) );
    check( 'Nieuw antwoord.' === get_post_meta( $id, '_publion_faq_pairs', true )[0]['answer'], 'FAQ became stale' );
} );
test( 'diagnostics dry-run renders escaped review controls without modifying posts', function () {
    reset_case(); $id = queue( 'Onderwerp <b>veilig</b>' );
    $post_id = wp_insert_post( array( 'post_title' => 'Achterzetraam', 'post_content' => article(), 'post_status' => 'draft', 'meta_input' => array( '_publion_queue_id' => $id ) ) );
    $before = get_post( $post_id )->post_content;
    ob_start(); publion_render_diagnostics(); $output = ob_get_clean();
    check( false !== strpos( $output, 'publion_safety_action' ) && false !== strpos( $output, '_wpnonce' ), 'Review controls/nonces absent' );
    check( $before === get_post( $post_id )->post_content, 'Dry-run changed content' );
    check( false === strpos( $output, 'test-key-no-live-access' ), 'API key shown in diagnose' );
} );
test( 'daily cron creates a usable keyword instead of schema empty default', function () {
    reset_case();
    wp_insert_term( 'Raamonderhoud test', 'category' );
    update_option( 'publion_post_settings', array( 'auto_daily_topic' => 'yes' ) );
    ( new Publion_Cron() )->maybe_create_daily_topic();
    global $wpdb;
    $entry = $wpdb->get_row( "SELECT * FROM {$wpdb->publion_queue} ORDER BY id DESC LIMIT 1" );
    check( $entry && '' !== trim( $entry->focus_keyword ) && $entry->topic === $entry->focus_keyword, 'Daily topic keyword missing' );
} );
test( 'additive migration preserves old records schedules and settings', function () {
    reset_case(); $id = queue(); global $wpdb;
    $wpdb->update( $wpdb->publion_queue, array( 'schedule_locked' => 1, 'seo_brief' => '{"angle":"bewaren"}' ), array( 'id' => $id ) );
    $before = row( $id ); $settings = get_option( 'publion_post_settings' );
    publion_maybe_update_queue_table(); publion_maybe_update_queue_table();
    $after = row( $id );
    check( $before->topic === $after->topic && $before->scheduled_at === $after->scheduled_at && $before->seo_brief === $after->seo_brief && 1 === (int) $after->schedule_locked, 'Migration changed existing queue record' );
    check( $settings === get_option( 'publion_post_settings' ), 'Migration reset choices' );
} );
test( 'real two-process contention permits one claim and one draft', function () {
    reset_case(); $id = queue();
    $repo = getenv( 'PUBLION_TEST_REPO' );
    check( $repo && is_file( $repo . '/tests/concurrency-worker.php' ), 'PUBLION_TEST_REPO must point to this checkout' );
    $command = array( PHP_BINARY, $repo . '/tests/concurrency-worker.php', ABSPATH, (string) $id );
    $descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
    $process1 = proc_open( $command, $descriptors, $pipes1 ); $process2 = proc_open( $command, $descriptors, $pipes2 );
    fclose( $pipes1[0] ); fclose( $pipes2[0] );
    $out1 = stream_get_contents( $pipes1[1] ); $out2 = stream_get_contents( $pipes2[1] );
    $err1 = stream_get_contents( $pipes1[2] ); $err2 = stream_get_contents( $pipes2[2] );
    foreach ( array( $pipes1[1], $pipes1[2], $pipes2[1], $pipes2[2] ) as $pipe ) { fclose( $pipe ); }
    check( 0 === proc_close( $process1 ) && 0 === proc_close( $process2 ), 'Worker failed: ' . $err1 . $err2 );
    $one = json_decode( $out1, true ); $two = json_decode( $out2, true );
    check( 1 === (int) ( $one['claimed'] ?? false ) + (int) ( $two['claimed'] ?? false ), 'Concurrent owner count wrong: ' . $out1 . $out2 );
    check( 1 === count( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_publion_queue_id', 'meta_value' => $id ) ) ), 'Concurrent workers duplicated draft' );
} );
test( 'robots policy longest rule and specific Publion agent respected', function () {
    check( ! publion_robots_path_allowed( "User-agent: *\nDisallow: /private/\nAllow: /private/public/", '/private/data' ), 'Blocked path crawled' );
    check( publion_robots_path_allowed( "User-agent: *\nDisallow: /private/\nAllow: /private/public/", '/private/public/page' ), 'Longest allow rule ignored' );
    check( ! publion_robots_path_allowed( "User-agent: *\nAllow: /\nUser-agent: Publion\nDisallow: /", '/page' ), 'Specific bot restriction ignored' );
} );
test( 'legitimate Data label and error text are not broad filter matches', function () {
    check( true === publion_validate_article_content( article( 'Data: foutmelding op thermostaat' ), 'thermostaat' ), 'Broad error/data word rejection' );
} );
require __DIR__ . '/menu-tests.php';

global $wp_version;
$failed = array_filter( $results, function ( $row ) { return 'FAIL' === $row[1]; } );
echo 'WordPress ' . $wp_version . ', PHP ' . PHP_VERSION . ', ' . ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? 'SQLite integration' : 'MySQL/MariaDB' ) . '; ' . count( $results ) . ' tests, ' . count( $failed ) . " failures. All HTTP and mail intercepted.\n";
if ( $failed ) { WP_CLI::halt( 1 ); }
