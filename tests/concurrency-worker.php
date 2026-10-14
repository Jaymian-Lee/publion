<?php
/** A real second process against the disposable integration installation. */
define( 'DISABLE_WP_CRON', true );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( 'publion.test' !== wp_parse_url( home_url(), PHP_URL_HOST ) || 'Publion isolated tests' !== get_option( 'blogname' ) ) { exit( 2 ); }
publion_register_table_on_wpdb();
global $wpdb;
$id = absint( $argv[2] );
$topic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->publion_queue} WHERE id = %d", $id ) );
usleep( 300000 );
$claimed = $topic && publion_claim_queue_entry( $id, $topic->topic );
if ( $claimed ) {
    usleep( 1200000 );
    $html = '<h2>' . esc_html( $topic->topic ) . '</h2><p>' . str_repeat( 'Het achterzetraam vraagt zorgvuldige kierdichting en aandacht voor ventilatie. ', 20 ) . '</p><p>' . str_repeat( 'Vergelijk de mogelijkheden in de woning en bespreek de uitvoering. ', 20 ) . '</p>';
    wp_insert_post( array( 'post_title' => $topic->topic, 'post_content' => $html, 'post_status' => 'draft', 'meta_input' => array( '_publion_queue_id' => $id ) ) );
    publion_release_queue_claim( $id, $topic->topic, 'created' );
}
echo wp_json_encode( array( 'claimed' => (bool) $claimed ) );
