<?php
/**
 * Standalone checks for the generation-error / instruction-leak blocklist.
 * Covers the observed 2026-09/10 error-stub phrasing family (posts 2672,
 * 2679, 2700, 2714, 2721, 2728, 2776, 2783, 2797, 2811) and negative
 * controls: legitimate articles that discuss zoektermen/SEO must NOT be
 * flagged. No WordPress required. Run: php tests/leak-variants-standalone.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
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
class WP_Error {
	private $code; private $msg;
	public function __construct( $code = '', $msg = '' ) { $this->code = $code; $this->msg = $msg; }
	public function get_error_code() { return $this->code; }
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
function leak( $html ) { return publion_content_has_instruction_leak( $html ); }

// --- 1. Observed error-stub lead sentences (must be flagged) ---
$observed = array(
	'post 2672-style' => 'De primaire zoekterm is leeg. Daardoor kan deze HTML niet voldoen aan de gevraagde eisen.',
	'post 2679 lead'  => 'De primaire zoekterm is niet opgegeven. Daardoor kan deze HTML niet betrouwbaar worden herschreven met de vereiste exacte zoekterm en zoekwoorddichtheid.',
	'post 2700 lead'  => 'De primaire zoekterm is niet opgegeven. Daardoor kan de gevraagde zoektermoptimalisatie niet betrouwbaar worden uitgevoerd.',
	'post 2714 lead'  => 'De primaire zoekterm is niet opgegeven. Daardoor kan de vereiste zoektermfrequentie niet betrouwbaar worden toegepast.',
	'post 2721 lead'  => 'De primaire zoekterm is leeg. Daarom kan de HTML niet voldoen aan de vereisten om een exacte zoekterm natuurlijk in de eerste alinea en in een beschrijvende kop te verwerken.',
	'post 2797 lead'  => 'De primaire zoekterm is leeg. Daardoor kan deze niet natuurlijk en meetbaar tussen 1,0% en 1,5% van de zichtbare tekst worden verwerkt, noch betekenisvol in een alinea of kop worden opgenomen.',
	'research footer' => 'Bronnen en verdieping: voor dit artikel is actueel brononderzoek uitgevoerd. Controleer deze bronnen altijd voordat je publiceert.',
);
foreach ( $observed as $label => $sentence ) {
	check( "flags: $label", leak( '<p>' . $sentence . '</p>' ) );
}

// --- 2. The demonstrated residual: a long, well-structured error answer in
// the "niet opgegeven" family must now be blocked (previously passed). ---
$padded = '<p>De primaire zoekterm is niet opgegeven. Daardoor kan de gevraagde zoektermoptimalisatie niet betrouwbaar worden uitgevoerd.</p><h2>Wat betekent dit</h2><p>'
	. str_repeat( 'De gevraagde zoektermoptimalisatie kan niet betrouwbaar worden uitgevoerd omdat de zoekterm niet is opgegeven. ', 20 ) . '</p>';
check( 'padded niet-opgegeven error body >=150 words is flagged', leak( $padded ) );
$r = publion_validate_article_content( $padded, 'Kan ik mijn kelder veilig als slaapkamer gebruiken?', '' );
check( 'padded error body blocked by validator', $r instanceof WP_Error && 'publion_instruction_leak' === $r->get_error_code() );

// --- 3. Negative controls: legitimate zoekterm/SEO discussion must pass ---
$legit_sentences = array(
	'advice to choose keyword'   => 'Kies een primaire zoekterm die aansluit bij het onderwerp van je artikel.',
	'rank math placement'        => 'Rank Math controleert of de zoekterm in de eerste alinea voorkomt.',
	'missing keyword warning'    => 'Als de zoekterm ontbreekt, laat de plugin een waarschuwing zien.',
	'check setting'              => 'Controleer of de primaire zoekterm is opgegeven in de SEO-instellingen.',
	'subclause word order'       => 'Wanneer de primaire zoekterm niet is opgegeven, kun je die alsnog invullen.',
	'unreliable measurement'     => 'Een meting met een goedkope meter is niet betrouwbaar genoeg voor een rapportage.',
	'optimization quality'       => 'Goede zoektermoptimalisatie is betrouwbaar meetbaar met de juiste tools.',
	'density advice'             => 'Een zoekwoorddichtheid van ongeveer 1 procent is een prima richtlijn.',
);
foreach ( $legit_sentences as $label => $sentence ) {
	check( "does NOT flag legit: $label", ! leak( '<p>' . $sentence . '</p>' ) );
}

// --- 4. Full legitimate article that discusses SEO/zoektermen passes the
// whole validator. ---
$article = '<p>Een goede zoekterm kiezen is de basis van elke SEO-strategie. '
	. str_repeat( 'De zoekterm bepaalt waar zoekmachines je pagina voor tonen. ', 18 ) . '</p>'
	. '<h2>Zo controleer je de instellingen</h2><p>Controleer of de primaire zoekterm is opgegeven in de SEO-instellingen. '
	. str_repeat( 'Als de zoekterm ontbreekt, laat de plugin een waarschuwing zien. ', 8 ) . '</p>';
$r = publion_validate_article_content( $article, 'Hoe kies je een goede zoekterm voor SEO?', 'zoekterm kiezen' );
check( 'legit SEO article passes validator', true === $r );

// --- 5. Existing detections unchanged ---
check( 'still flags: instruction leak (geef uitsluitend html)', leak( '<p>Geef uitsluitend geldige HTML terug.</p>' ) );
check( 'still flags: refusal NL', leak( '<p>Ik kan deze opdracht niet uitvoeren zonder zoekterm.</p>' ) );
check( 'still flags: refusal EN', leak( "<p>I cannot fulfill this request.</p>" ) );

echo ( 0 === $fails ? "\nALL CHECKS PASSED\n" : "\n$fails CHECK(S) FAILED\n" );
exit( $fails ? 1 : 0 );
