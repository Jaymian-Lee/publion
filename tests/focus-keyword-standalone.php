<?php
/**
 * Standalone checks for focus keyword derivation and the Rank Math title/slug
 * checks it feeds. No WordPress required: minimal WP stubs, then the real
 * functions-safety.php and functions-openai.php are loaded.
 * Run: php tests/focus-keyword-standalone.php
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

/** Mirror of the title check expression in the quality report. */
function title_check_passes( $seo_title, $focus_keyword ) {
	$kw = mb_strtolower( trim( (string) $focus_keyword ) );
	$t  = mb_strtolower( $seo_title );
	$pos = '' !== $kw ? mb_strpos( $t, $kw ) : false;
	return false !== $pos && $pos <= (int) floor( mb_strlen( $t ) / 2 );
}

$stopwords = publion_slug_stopwords();

$question_topics = array(
	'Waarom klinkt mijn tegelvloer hol en wanneer moeten losse tegels worden hersteld?',
	'Hoe los ik vochtproblemen in de kruipruimte van mijn woning op?',
	'Wat kost het herstellen van scheuren in de gevel van een jaren dertig woning?',
	'Wanneer moet je een funderingsprobleem laten onderzoeken door een expert?',
	'Waarom trekt mijn houten vloer krom en wat kan ik daaraan doen?',
	'Hoe herken je asbest in een oude woning en wat zijn de gevolgen voor verbouwen?',
	'Wat zijn de oorzaken van tocht in huis en hoe los je dat definitief op?',
);

foreach ( $question_topics as $topic ) {
	$keyword = publion_effective_focus_keyword( '', $topic );
	$words   = explode( ' ', $keyword );
	check( "derive [$topic]: not the full topic", $keyword !== $topic );
	check( "derive [$topic]: 3-5 words", count( $words ) >= 3 && count( $words ) <= 5 );
	check( "derive [$topic]: <= 40 chars", strlen( $keyword ) <= 40 );
	$edge = publion_focus_keyword_edge_stopwords();
	check( "derive [$topic]: no edge stopwords", ! in_array( $words[0], $edge, true ) && ! in_array( end( $words ), $edge, true ) );
	check( "derive [$topic]: contiguous span of topic", false !== strpos( publion_normalize_content_for_comparison( $topic ), $keyword ) );

	// The derived keyword must satisfy the actual checks it feeds.
	$slug = publion_build_rank_math_slug( $keyword, $topic );
	check( "derive [$topic]: slug contains keyword", publion_article_has_focus_keyword( $slug, $keyword ) );
	check( "derive [$topic]: slug <= 52 chars", strlen( $slug ) <= 52 );
	$title = publion_build_rank_math_seo_title( $topic, $keyword );
	check( "derive [$topic]: title <= 60 chars", mb_strlen( $title ) <= 60 );
	check( "derive [$topic]: keyword at beginning of title", title_check_passes( $title, $keyword ) );
}

// --- effective keyword semantics ---
check( 'short topic stays its own keyword', 'Tegelvloer hol herstellen' === publion_effective_focus_keyword( '', 'Tegelvloer hol herstellen' ) );
check( 'explicit distinct keyword preserved', 'dakkapel plaatsen' === publion_effective_focus_keyword( 'dakkapel plaatsen', $question_topics[0] ) );
check( 'stored full-topic keyword is repaired', publion_effective_focus_keyword( $question_topics[0], $question_topics[0] ) === publion_effective_focus_keyword( '', $question_topics[0] ) );
check( 'topic-only punctuation/case difference still repairs', publion_effective_focus_keyword( rtrim( $question_topics[0], '?' ), $question_topics[0] ) === publion_effective_focus_keyword( '', $question_topics[0] ) );
check( 'no topic keeps explicit keyword', 'isolatieadvies' === publion_effective_focus_keyword( 'isolatieadvies', '' ) );
check( 'no topic no keyword stays empty', '' === publion_effective_focus_keyword( '', '' ) );

// --- title check semantics ---
$short_topic = 'Holle tegelvloer herstellen';
$keyword     = publion_effective_focus_keyword( '', $short_topic );
$title       = publion_build_rank_math_seo_title( $short_topic, $keyword );
check( 'short topic keyword is the topic', $keyword === $short_topic );
check( 'title==keyword-prefix passes early check', title_check_passes( $title, $keyword ) );
check( 'keyword absent from title fails', ! title_check_passes( 'Praktische gids voor wonen', $keyword ) );
check( 'keyword only in second half fails', ! title_check_passes( 'Praktische complete gids voor wonen - ' . $keyword, $keyword ) );


// --- 1.9.56: dangling edge words and imperative-led instruction topics ---
$seo_topic = 'Hoe optimaliseer je lokale SEO zonder fysiek bezoekadres?';
$seo_kw    = publion_derive_focus_keyword( $seo_topic );
check( 'imperative-led topic drops the command verb', 0 !== strpos( $seo_kw, 'optimaliseer' ) );
check( 'derived keyword keeps the noun phrase', 'lokale seo zonder fysiek bezoekadres' === $seo_kw );
check( 'no dangling preposition at keyword end', ! preg_match( '/ (zonder|met|voor|van|tegen|via|tijdens|volgens)$/u', $seo_kw ) );
check( 'derived seo keyword is substring-safe length', strlen( $seo_kw ) <= 40 );

$leak_kw = publion_derive_focus_keyword( 'Wat doe je bij een lekkage zonder zichtbare bron?' );
check( 'function-word prefix trimmed', 0 === strpos( $leak_kw, 'lekkage' ) );
check( 'no trailing zonder fragment', ! preg_match( '/ zonder$/u', $leak_kw ) );

$verf_kw = publion_derive_focus_keyword( 'Verf bladdert af op houten kozijnen door vocht en zon' );
check( 'noun-led topic keeps its leading noun', 0 === strpos( $verf_kw, 'verf' ) );

$plan_kw = publion_derive_focus_keyword( 'Plan van aanpak voor een verbouwing in een oud huis' );
check( 'noun plan untouched without pronoun', 0 === strpos( $plan_kw, 'plan' ) );

$imp_kw = publion_derive_focus_keyword( 'Hoe repareer je een houten schuifraam dat vastloopt?' );
check( 'imperative repareer dropped', 0 !== strpos( $imp_kw, 'repareer' ) );
check( 'noun phrase preserved after drop', 0 === strpos( $imp_kw, 'houten schuifraam' ) );

// --- 1.9.56: internal link anchor quality ---
check( 'single generic anchor seo rejected', ! publion_is_usable_internal_link_anchor( 'seo' ) );
check( 'single generic anchor website rejected', ! publion_is_usable_internal_link_anchor( 'website' ) );
check( 'single generic anchor bedrijf rejected', ! publion_is_usable_internal_link_anchor( 'bedrijf' ) );
check( 'single generic anchor zoekmachine rejected', ! publion_is_usable_internal_link_anchor( 'zoekmachine' ) );
check( 'short single word anchor rejected', ! publion_is_usable_internal_link_anchor( 'parket' ) );
check( 'specific single word anchor allowed', publion_is_usable_internal_link_anchor( 'dakkapel' ) );
check( 'multi-word anchor allowed', publion_is_usable_internal_link_anchor( 'lokale seo' ) );
check( 'multi-word generic words allowed as phrase', publion_is_usable_internal_link_anchor( 'website laten maken' ) );

echo $fails ? "\n$fails FAILURES\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
