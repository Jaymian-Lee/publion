<?php
/**
 * Standalone checks for the Rank Math quality report semantics (1.9.60):
 * focus_keyword_in_intro is advisory, never a hard gate.
 *
 * Covers the actual report output plus the wiring of its two consumers
 * (auto-repair and publish gate), which both key off failed_required only.
 * No WordPress required: minimal WP stubs, then the real
 * functions-openai.php and functions-safety.php are loaded.
 * Run: php tests/quality-report-standalone.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_strip_all_tags( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function remove_accents( $s ) {
	$s = (string) $s;
	$map = array( 'ä'=>'a','á'=>'a','à'=>'a','â'=>'a','ë'=>'e','é'=>'e','è'=>'e','ê'=>'e','ï'=>'i','í'=>'i','ö'=>'o','ó'=>'o','ô'=>'o','ü'=>'u','ú'=>'u','û'=>'u','ñ'=>'n','ç'=>'c' );
	return strtr( $s, $map );
}
function sanitize_title( $t ) {
	$t = strtolower( remove_accents( $t ) );
	$t = preg_replace( '/[^a-z0-9]+/', '-', $t );
	return trim( $t, '-' );
}
function home_url( $path = '/' ) { return 'https://example.nl' . $path; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function get_bloginfo( $show = '' ) { return 'UTF-8'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function __( $s, $d = null ) { return $s; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	private $msg;
	public function __construct( $code = '', $msg = '' ) { $this->msg = $msg; }
	public function get_error_message() { return $this->msg; }
}
function add_filter( ...$a ) {}
function add_action( ...$a ) {}

require __DIR__ . '/../publion/includes/functions-openai.php';
require __DIR__ . '/../publion/includes/functions-safety.php';

$fails = 0;
function check( $label, $cond ) {
	global $fails;
	if ( $cond ) { echo "OK   $label\n"; } else { $fails++; echo "FAIL $label\n"; }
}

// Keep the report cheap: 1200-word floor, no optional link/alt/toc checks.
$GLOBALS['__options']['publion_post_settings'] = array(
	'rank_math_target_word_count'   => 1200,
	'rank_math_add_toc'             => 'no',
	'rank_math_check_image_alt'     => 'no',
	'rank_math_check_external_link' => 'no',
	'rank_math_check_internal_link' => 'no',
);

$kw = 'schuifpui loopt zwaar';
$topic = 'Schuifpui loopt zwaar of gaat niet meer dicht: ligt het aan de rails, wielen of deurvleugel?';

$paragraph = str_repeat( 'Een schuifpui bestaat uit een vast deel en een beweegbare deurvleugel die over een onderrail loopt. ', 7 );
$body = '';
for ( $i = 0; $i < 13; $i++ ) { $body .= '<p>' . $paragraph . '</p>'; }

// A: grammatical variant in the intro, exact keyword in heading and content.
$html_a = '<h2>Schuifpui loopt zwaar of gaat niet meer dicht: onderzoek eerst rails, wielen en deurvleugel</h2>'
	. '<p>Wanneer een schuifpui zwaar loopt of niet meer volledig dichtgaat, ligt de oorzaak vaak bij vervuiling in de onderrail, versleten loopwielen of een scheefhangende deurvleugel.</p>'
	. $body;

// B: exact keyword nowhere (variant everywhere): required checks must still fail.
$html_b = '<h2>Een schuifpui die zwaar loopt: oorzaken en herstel</h2>'
	. '<p>Wanneer een schuifpui zwaar loopt of niet meer volledig dichtgaat, ligt de oorzaak vaak bij vervuiling in de onderrail, versleten loopwielen of een scheefhangende deurvleugel.</p>'
	. $body;

// C: old-style fully compliant article with the exact keyword in the intro.
$html_c = '<h2>Schuifpui loopt zwaar of gaat niet meer dicht: onderzoek eerst rails, wielen en deurvleugel</h2>'
	. '<p>Schuifpui loopt zwaar? Vervuiling in de onderrail, versleten loopwielen of een scheefhangende deurvleugel zijn meestal de oorzaak.</p>'
	. $body;

$report_a = publion_get_rank_math_quality_report( $html_a, $kw, $topic );
check( 'A: exact keyword genuinely absent from first paragraph', false === $report_a['checks']['focus_keyword_in_intro'] );
check( 'A: intro mismatch not in failed_required', ! in_array( 'focus_keyword_in_intro', $report_a['failed_required'], true ) );
check( 'A: intro mismatch stays visible as advisory', in_array( 'focus_keyword_in_intro', $report_a['needs_editor_review'], true ) );
check( 'A: no required check fails on intro mismatch alone', array() === $report_a['failed_required'] );
check( 'A: report status ready', 'ready' === $report_a['status'] );

$report_b = publion_get_rank_math_quality_report( $html_b, $kw, $topic );
check( 'B: missing keyword in content still a required failure', in_array( 'focus_keyword_in_content', $report_b['failed_required'], true ) );
check( 'B: missing keyword in heading still a required failure', in_array( 'focus_keyword_in_heading', $report_b['failed_required'], true ) );
check( 'B: intro mismatch itself still not in failed_required', ! in_array( 'focus_keyword_in_intro', $report_b['failed_required'], true ) );
check( 'B: report status review_required', 'review_required' === $report_b['status'] );

$report_c = publion_get_rank_math_quality_report( $html_c, $kw, $topic );
check( 'C: exact keyword in intro detected', true === $report_c['checks']['focus_keyword_in_intro'] );
check( 'C: no advisory intro flag when exact keyword present', ! in_array( 'focus_keyword_in_intro', $report_c['needs_editor_review'], true ) );
check( 'C: report status ready', 'ready' === $report_c['status'] );

check( 'report version bumped for new semantics', 2 === $report_a['version'] );

// Pipeline wiring: both consumers of the report key off failed_required only,
// so an empty failed_required neither triggers the paid repair pass nor
// forces a draft.
$cron_src = file_get_contents( __DIR__ . '/../publion/includes/class-publion-cron.php' );
$openai_src = file_get_contents( __DIR__ . '/../publion/includes/functions-openai.php' );
check( 'publish gate consumes only failed_required', 1 === preg_match( "/publish_gate'\] && ! empty\( \\\$rank_math_quality_report\['failed_required'\] \)/", $cron_src ) );
check( 'auto-repair consumes only failed_required', 1 === preg_match( "/auto_repair.*?\\n.*?! empty\( \\\$rank_math_report\['failed_required'\] \)/s", $openai_src ) );

// Prompt: grammar outranks the verbatim intro match.
$instruction = publion_get_rank_math_generation_instruction( $kw, true );
check( 'prompt states grammar priority', false !== strpos( $instruction, 'Grammaticaal correct en natuurlijk Nederlands gaat altijd vóór op de exacte vorm' ) );
check( 'prompt no longer demands the exact keyword in the intro', false === strpos( $instruction, 'exacte focus-keyword' ) );
check( 'prompt still empty when integration disabled', '' === publion_get_rank_math_generation_instruction( $kw, false ) );

echo $fails ? "\n$fails FAILURES\n" : "\nALL OK\n";
exit( $fails ? 1 : 0 );
