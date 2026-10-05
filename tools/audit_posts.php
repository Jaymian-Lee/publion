<?php
/** Read-only WP-CLI export: originals and proposals, never bulk edit/unpublish. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! function_exists( 'publion_validate_article_content' ) ) { throw new RuntimeException( 'Run with wp eval-file after activating Publion 1.9.47 or later.' ); }
$targets = array( 'achterzetraam-tegen-verkeerslawaai-wanneer-werkt', 'thuisbatterij-in-huurwoning', 'waarom-verliest-mijn-slimme-thermostaat-steeds-de', 'ventilatierooster-schoonmaken-zo-verwijder-je' );
$output = array( 'mode' => 'read_only_no_changes', 'created_at' => gmdate( 'c' ), 'plugin_version' => PUBLION_VERSION, 'site' => home_url(), 'posts' => array() );
$page = 1;
do {
    $posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => 100, 'paged' => $page, 'meta_key' => '_publion_queue_id', 'orderby' => 'ID', 'order' => 'ASC' ) );
    foreach ( $posts as $post ) {
        $valid = publion_validate_article_content( $post->post_content, $post->post_title, get_post_meta( $post->ID, '_publion_focus_keyword', true ) );
        if ( ! is_wp_error( $valid ) && ! in_array( $post->post_name, $targets, true ) ) { continue; }
        $output['posts'][] = array( 'post_id' => $post->ID, 'url' => get_permalink( $post->ID ), 'title' => $post->post_title, 'status' => $post->post_status, 'modified_gmt' => $post->post_modified_gmt, 'queue_id' => get_post_meta( $post->ID, '_publion_queue_id', true ), 'original_content' => $post->post_content, 'original_excerpt' => $post->post_excerpt, 'content_sha256' => hash( 'sha256', $post->post_content ), 'finding' => is_wp_error( $valid ) ? array( 'code' => $valid->get_error_code(), 'reason' => $valid->get_error_message() ) : array( 'code' => 'manual_incident_review', 'reason' => 'Known incident: review source relevance, image alts and editorial text.' ), 'proposal' => 'Back up original and revisions; prepare corrected draft; review each source/claim/image; approve per post before replacing content. Preserve URL and post ID.' );
    }
    $page++;
} while ( 100 === count( $posts ) );
echo wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
