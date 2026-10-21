<?php
/**
 * Standalone unit checks for the affiliate module. No WordPress required:
 * minimal WP stubs, then the real functions-affiliate.php is loaded.
 * Run: php tests/affiliate-standalone.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url( $u ) { return (string) $u; }
function esc_url_raw( $u ) { return (string) $u; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function __( $s, $d = null ) { return $s; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	private $msg;
	public function __construct( $code = '', $msg = '' ) { $this->msg = $msg; }
	public function get_error_message() { return $this->msg; }
}
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	return array_merge( $pairs, array_intersect_key( $atts, $pairs ) );
}

require __DIR__ . '/../publion/includes/functions-affiliate.php';

$fails = 0;
function check( $label, $cond ) {
	global $fails;
	if ( $cond ) { echo "OK   $label\n"; } else { $fails++; echo "FAIL $label\n"; }
}

$two_offers = json_encode( array(
	array( 'id' => 'Dak-Offertes', 'label' => 'Vergelijk dakoffertes', 'url' => 'https://example.nl/dak', 'type' => 'lead', 'intents' => array( 'commercieel' ), 'categories' => array( 'Dakwerk' ), 'keywords' => array( 'dakkapel' ), 'placement' => 'mid', 'style' => 'box', 'text' => 'Wat kost het?', 'enabled' => true ),
	array( 'id' => 'tuin-product', 'label' => 'Bekijk tuinhuisjes', 'url' => 'https://example.nl/tuin', 'type' => 'product', 'intents' => array(), 'categories' => array(), 'keywords' => array(), 'placement' => 'end', 'style' => 'button', 'enabled' => true ),
) );

// --- parsing & validation ---
$parsed = publion_parse_affiliate_offers_json( $two_offers );
check( 'parse: two offers normalized', is_array( $parsed ) && 2 === count( $parsed ) );
check( 'parse: id lowercased', 'dak-offertes' === $parsed[0]['id'] );
check( 'parse: categories lowercased', array( 'dakwerk' ) === $parsed[0]['categories'] );
check( 'parse: defaults for second offer', 'custom' !== $parsed[1]['type'] && 'product' === $parsed[1]['type'] && '' !== $parsed[1]['placement'] );
check( 'parse: empty string ok', array() === publion_parse_affiliate_offers_json( '' ) );
check( 'parse: invalid json errors', is_wp_error( publion_parse_affiliate_offers_json( '{nope' ) ) );
check( 'parse: object-not-list errors', is_wp_error( publion_parse_affiliate_offers_json( '{"id":"x"}' ) ) );
check( 'parse: missing label errors', is_wp_error( publion_parse_affiliate_offers_json( '[{"id":"x","url":"https://a.nl"}]' ) ) );
check( 'parse: bad url errors', is_wp_error( publion_parse_affiliate_offers_json( '[{"id":"x","label":"y","url":"ftp://a.nl"}]' ) ) );
check( 'parse: duplicate id errors', is_wp_error( publion_parse_affiliate_offers_json( '[{"id":"x","label":"a","url":"https://a.nl"},{"id":"x","label":"b","url":"https://b.nl"}]' ) ) );

// --- matching ---
$ctx_comm_dak = array( 'search_intent' => 'commercieel', 'category' => 'dakwerk', 'focus_keyword' => 'dakkapel kosten', 'topic' => 'Dakkapel kosten 2026' );
$ctx_info_dak = array( 'search_intent' => 'informatief', 'category' => 'dakwerk', 'focus_keyword' => 'dakkapel kosten', 'topic' => 'Dakkapel kosten 2026' );
$ctx_comm_tuin = array( 'search_intent' => 'commercieel', 'category' => 'tuin', 'focus_keyword' => 'tuinhuis kopen', 'topic' => 'Tuinhuis kopen' );

check( 'match: keyword+category+intent offer scores on dak/commercieel', publion_score_affiliate_offer( $parsed[0], $ctx_comm_dak ) > 0 );
check( 'match: same offer skipped on informatief', 0 === publion_score_affiliate_offer( $parsed[0], $ctx_info_dak ) );
check( 'match: keyword offer skipped when keyword absent (tuin)', 0 === publion_score_affiliate_offer( $parsed[0], $ctx_comm_tuin ) );
check( 'match: general offer scores everywhere', publion_score_affiliate_offer( $parsed[1], $ctx_comm_tuin ) > 0 );
check( 'match: disabled offer never scores', 0 === publion_score_affiliate_offer( array_merge( $parsed[1], array( 'enabled' => false ) ), $ctx_comm_tuin ) );

$matched = publion_match_affiliate_offers( $parsed, $ctx_comm_dak, 2 );
check( 'match: specific offer first', 'dak-offertes' === $matched[0]['id'] );
check( 'match: capped by max_blocks', 1 === count( publion_match_affiliate_offers( $parsed, $ctx_comm_dak, 1 ) ) );

// --- insertion ---
$html = '<p>Intro.</p><h2>Kosten van een dakkapel</h2><p>Eerste alinea.</p><p>Tweede alinea.</p><h2>Wat kost een dakkapel gemiddeld?</h2><p>Derde alinea.</p><h2>Veelgestelde vragen</h2><h3>Vraag 1</h3><p>Antwoord.</p>';

$GLOBALS['__options']['publion_post_settings'] = array( 'affiliate_enabled' => 'no', 'affiliate_offers_json' => $two_offers );
check( 'insert: module off leaves html untouched', $html === publion_insert_affiliate_placeholders( $html, array( 'search_intent' => 'commercieel', 'focus_keyword' => 'dakkapel kosten' ), 'dakwerk', 'Dakkapel kosten' ) );

$GLOBALS['__options']['publion_post_settings'] = array( 'affiliate_enabled' => 'yes', 'affiliate_offers_json' => $two_offers, 'affiliate_max_blocks' => 2 );
$out = publion_insert_affiliate_placeholders( $html, array( 'search_intent' => 'commercieel', 'focus_keyword' => 'dakkapel kosten' ), 'dakwerk', 'Dakkapel kosten' );
check( 'insert: dak shortcode present', false !== strpos( $out, '[publion_affiliate id="dak-offertes" style="box"]' ) );
check( 'insert: general offer also placed (max 2)', false !== strpos( $out, '[publion_affiliate id="tuin-product" style="button"]' ) );
$mid_pos = strpos( $out, 'dak-offertes' );
$end_pos = strpos( $out, 'tuin-product' );
$faq_pos = strpos( $out, 'Veelgestelde vragen' );
check( 'insert: mid lands before end, end lands before FAQ', $mid_pos < $end_pos && $end_pos < $faq_pos );
$second_h2 = strpos( $out, '<h2>Wat kost een dakkapel gemiddeld?</h2>' );
$derde = strpos( $out, 'Derde alinea.' );
check( 'insert: mid lands after the second h2 paragraph', $mid_pos > $second_h2 && $mid_pos >= $derde + strlen( 'Derde alinea.</p>' ) && $mid_pos < $faq_pos );

$out_info = publion_insert_affiliate_placeholders( $html, array( 'search_intent' => 'informatief', 'focus_keyword' => 'iets anders' ), 'dakwerk', 'Iets anders' );
check( 'insert: informational article only gets the general offer', false === strpos( $out_info, 'dak-offertes' ) && false !== strpos( $out_info, 'tuin-product' ) );

$html_no_faq = '<p>Intro.</p><p>Alleen tekst.</p>';
$out_no_faq = publion_insert_affiliate_placeholders( $html_no_faq, array( 'search_intent' => 'commercieel', 'focus_keyword' => 'dakkapel' ), 'dakwerk', 'Dakkapel' );
check( 'insert: falls back to end when no second h2/FAQ', substr( trim( $out_no_faq ), -strlen( '[publion_affiliate id="tuin-product" style="button"]' ) ) === '[publion_affiliate id="tuin-product" style="button"]' && false !== strpos( $out_no_faq, 'dak-offertes' ) );

// --- rendering ---
$GLOBALS['__options']['publion_post_settings'] = array( 'affiliate_enabled' => 'no', 'affiliate_offers_json' => $two_offers );
check( 'render: module off renders empty', '' === publion_affiliate_shortcode( array( 'id' => 'dak-offertes', 'style' => 'box' ) ) );

$GLOBALS['__options']['publion_post_settings'] = array( 'affiliate_enabled' => 'yes', 'affiliate_offers_json' => $two_offers );
$box = publion_affiliate_shortcode( array( 'id' => 'dak-offertes', 'style' => 'box' ) );
check( 'render: box has sponsored rel', false !== strpos( $box, 'rel="sponsored noopener noreferrer"' ) );
check( 'render: box opens new tab', false !== strpos( $box, 'target="_blank"' ) );
check( 'render: box has url + label', false !== strpos( $box, 'https://example.nl/dak' ) && false !== strpos( $box, 'Vergelijk dakoffertes' ) );
check( 'render: box shows optional text', false !== strpos( $box, 'Wat kost het?' ) );
check( 'render: unknown id empty', '' === publion_affiliate_shortcode( array( 'id' => 'bestaat-niet', 'style' => 'box' ) ) );
$btn = publion_affiliate_shortcode( array( 'id' => 'tuin-product', 'style' => 'button' ) );
check( 'render: button style renders button class', false !== strpos( $btn, 'publion-affiliate-button' ) );
check( 'render: invalid style falls back to offer style', false !== strpos( publion_affiliate_shortcode( array( 'id' => 'tuin-product', 'style' => 'weird' ) ), 'publion-affiliate-button' ) );

// --- disclosure ---
$GLOBALS['__options']['publion_post_settings'] = array( 'affiliate_enabled' => 'yes', 'affiliate_offers_json' => $two_offers, 'affiliate_disclosure_text' => 'Bevat affiliate links.' );
$content = '<p>Hallo.</p>[publion_affiliate id="dak-offertes" style="box"]';
$with = publion_affiliate_disclosure_filter( $content );
check( 'disclosure: prepended when offer renders', 0 === strpos( $with, '<p class="publion-affiliate-disclosure"' ) && false !== strpos( $with, 'Bevat affiliate links.' ) );
check( 'disclosure: untouched without shortcode', '<p>Hallo.</p>' === publion_affiliate_disclosure_filter( '<p>Hallo.</p>' ) );
$unknown = '<p>Hallo.</p>[publion_affiliate id="bestaat-niet"]';
check( 'disclosure: none for unknown offer', $unknown === publion_affiliate_disclosure_filter( $unknown ) );
$GLOBALS['__options']['publion_post_settings'] = array( 'affiliate_enabled' => 'no', 'affiliate_offers_json' => $two_offers );
check( 'disclosure: none when module off', $content === publion_affiliate_disclosure_filter( $content ) );

echo $fails ? "\n$fails FAILURES\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
