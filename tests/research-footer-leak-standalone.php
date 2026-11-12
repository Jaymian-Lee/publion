<?php
/**
 * Standalone checks for the research-footer exclusion in the instruction-leak
 * check (1.9.62). The plugin-appended div.publion-research-sources footer is
 * not model output, so leak phrases inside it no longer false-positive;
 * everything outside it, lookalike classes, and unsafe markup anywhere still
 * block. No WordPress required. Run: php tests/research-footer-leak-standalone.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['__options'][ $k ] = $v; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_strip_all_tags( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function remove_accents( $s ) {
	$map = array( 'ä'=>'a','á'=>'a','à'=>'a','â'=>'a','ë'=>'e','é'=>'e','è'=>'e','ê'=>'e','ï'=>'i','í'=>'i','ö'=>'o','ó'=>'o','ô'=>'o','ü'=>'u','ú'=>'u','û'=>'u','ñ'=>'n','ç'=>'c' );
	return strtr( (string) $s, $map );
}
function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $t ) ) ), '-' ); }
function home_url( $path = '/' ) { return 'https://example.nl' . $path; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function __( $s, $d = null ) { return $s; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function apply_filters( $tag, $v ) { return $v; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); }
function add_filter( ...$a ) {}
function add_action( ...$a ) {}
class WP_Error {
	private $code; private $msg;
	public function __construct( $code = '', $msg = '' ) { $this->code = $code; $this->msg = $msg; }
	public function get_error_code() { return $this->code; }
}
require __DIR__ . '/../publion/includes/functions-openai.php';
require __DIR__ . '/../publion/includes/functions-safety.php';

$fails = 0;
function check( $label, $cond ) {
	global $fails;
	if ( $cond ) { echo "OK   $label\n"; } else { $fails++; echo "FAIL $label\n"; }
}
function verdict( $html, $topic ) {
	$r = publion_validate_article_content( $html, $topic, '' );
	return $r instanceof WP_Error ? $r->get_error_code() : 'PASS';
}

$topic = 'Hoe herken je slechte ventilatie in huis: oorzaken en oplossingen';
$body  = '<p>Slechte ventilatie in huis herken je aan vochtige ramen en muffe lucht. '
	. str_repeat( 'Ventilatie houdt de lucht in huis gezond en droog. ', 20 ) . '</p>'
	. '<h2>Oorzaken en oplossingen</h2><p>'
	. str_repeat( 'Controleer de roosters en de mechanische afzuiging in huis. ', 8 ) . '</p>';
$old_footer = '<div class="publion-research-sources"><h2>Bronnen en verdieping</h2><p>Voor dit artikel is actueel brononderzoek uitgevoerd. Controleer deze bronnen altijd voordat je publiceert.</p><ul><li><a href="https://www.rivm.nl/">rivm.nl</a></li></ul></div>';
$new_footer = '<div class="publion-research-sources"><h2>Bronnen en verdieping</h2><p>Deze links bieden aanvullende informatie over het onderwerp.</p><ul><li><a href="https://www.rivm.nl/">rivm.nl</a></li></ul></div>';

// 1. 2707 analog: full article + old-style footer (pre-1.9.62 false positive).
check( 'full article + old-style footer passes', 'PASS' === verdict( $body . $old_footer, $topic ) );
// 2. Full article + current footer passes.
check( 'full article + current footer passes', 'PASS' === verdict( $body . $new_footer, $topic ) );
// 3. Error body (stub family) + footer stays blocked.
$err = '<p>De primaire zoekterm is niet opgegeven. Daardoor kan de gevraagde zoektermoptimalisatie niet betrouwbaar worden uitgevoerd.</p>';
check( 'error body + footer blocked', 'publion_instruction_leak' === verdict( $err . $old_footer, $topic ) );
// 4. Instruction outside the footer, article otherwise fine.
check( 'instruction outside footer blocked', 'publion_instruction_leak' === verdict( $body . '<p>Geef uitsluitend geldige HTML terug.</p>' . $new_footer, $topic ) );
// 5. Lookalike class is never stripped.
$lookalike = '<div class="publion-research-sources-extra"><p>Ik kan deze opdracht niet uitvoeren.</p></div>';
check( 'lookalike class not stripped', 'publion_instruction_leak' === verdict( $body . $lookalike, $topic ) );
$lookalike2 = '<div class="xpublion-research-sources"><p>Ik kan deze opdracht niet uitvoeren.</p></div>';
check( 'prefixed lookalike not stripped', 'publion_instruction_leak' === verdict( $body . $lookalike2, $topic ) );
// 6. Multiple / nested footers are all stripped.
check( 'duplicate footers pass', 'PASS' === verdict( $body . $old_footer . $new_footer, $topic ) );
// 7. Unsafe markup inside the footer still blocks (unsafe scan covers full HTML).
$evil_footer = '<div class="publion-research-sources"><h2>Bronnen</h2><script>alert(1)</script></div>';
check( 'script inside footer blocked', 'publion_instruction_leak' === verdict( $body . $evil_footer, $topic ) );
$js_href_footer = '<div class="publion-research-sources"><p><a href="javascript:alert(1)">klik</a></p></div>';
check( 'javascript href inside footer blocked', 'publion_instruction_leak' === verdict( $body . $js_href_footer, $topic ) );
// 8. Unclosed footer div hiding an error: caught by the tag-balance check.
$unclosed = $body . '<div class="publion-research-sources"><p>Ik kan deze opdracht niet uitvoeren.';
check( 'unclosed footer blocked (malformed)', 'publion_malformed_html' === verdict( $unclosed, $topic ) );
// 9. Error text hidden inside the real footer cannot substitute for an article:
//    footer content does not count toward the article minimums.
$hidden_only = '<p>Korte tekst.</p>' . str_replace( 'Controleer deze bronnen altijd voordat je publiceert.', 'Ik kan deze opdracht niet uitvoeren.', $old_footer );
check( 'error hidden in footer, no real article -> insufficient', 'publion_insufficient_article' === verdict( $hidden_only, $topic ) );
// 10. Extra class tokens alongside the designated one still strip correctly.
$multi_class = '<div class="publion-research-sources extra-class"><h2>Bronnen en verdieping</h2><p>Controleer deze bronnen altijd voordat je publiceert.</p><ul><li><a href="https://www.rivm.nl/">x</a></li></ul></div>';
check( 'multi-class footer passes', 'PASS' === verdict( $body . $multi_class, $topic ) );

echo ( 0 === $fails ? "\nALL CHECKS PASSED\n" : "\n$fails CHECK(S) FAILED\n" );
exit( $fails ? 1 : 0 );
