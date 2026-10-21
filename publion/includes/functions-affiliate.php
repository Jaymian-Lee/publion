<?php
/**
 * Dynamic affiliate monetization for Publion articles.
 *
 * Offers are managed centrally in the settings. Article generation only places
 * a neutral [publion_affiliate] shortcode at a suitable anchor; the front end
 * renders the current link. The model never writes affiliate URLs, links stay
 * updatable in one place and every rendered link carries rel="sponsored".
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Default disclosure shown on posts that render affiliate links. Dutch consumer
 * law (oneerlijke handelspraktijken) and program terms (Amazon, bol.com) expect
 * a clear note; keep it near the top of the article.
 */
function publion_default_affiliate_disclosure() {
	return __( 'Dit artikel bevat affiliate links. Vraag je via zo\'n link een offerte aan of koop je iets, dan kunnen wij daar een vergoeding voor ontvangen. Jij betaalt nooit meer.', 'publion' );
}

/**
 * Affiliate settings live inside the publion_post_settings option.
 */
function publion_get_affiliate_settings() {
	$settings = get_option( 'publion_post_settings', array() );
	return array(
		'affiliate_enabled'         => ( ( $settings['affiliate_enabled'] ?? 'no' ) === 'yes' ) ? 'yes' : 'no',
		'affiliate_disclosure_text' => (string) ( $settings['affiliate_disclosure_text'] ?? publion_default_affiliate_disclosure() ),
		'affiliate_offers_json'     => (string) ( $settings['affiliate_offers_json'] ?? '' ),
		'affiliate_max_blocks'      => max( 1, min( 3, (int) ( $settings['affiliate_max_blocks'] ?? 2 ) ) ),
	);
}

/**
 * Normalize a comma/space separated list or JSON array to a lowercase array.
 */
function publion_affiliate_normalize_list( $value ) {
	if ( is_array( $value ) ) {
		$items = $value;
	} else {
		$items = preg_split( '/[\s,]+/', (string) $value );
	}
	$out = array();
	foreach ( (array) $items as $item ) {
		$item = strtolower( trim( sanitize_text_field( (string) $item ) ) );
		if ( '' !== $item ) {
			$out[] = $item;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Validate and normalize one affiliate offer. Returns the offer or WP_Error.
 */
function publion_validate_affiliate_offer( $offer, $index ) {
	if ( ! is_array( $offer ) ) {
		return new WP_Error( 'publion_affiliate_invalid', sprintf( __( 'Affiliate-aanbieding %d is geen object.', 'publion' ), $index + 1 ) );
	}

	$id = sanitize_key( $offer['id'] ?? '' );
	if ( '' === $id ) {
		return new WP_Error( 'publion_affiliate_invalid', sprintf( __( 'Affiliate-aanbieding %d mist een geldig "id" (kleine letters, cijfers, - of _).', 'publion' ), $index + 1 ) );
	}

	$label = sanitize_text_field( (string) ( $offer['label'] ?? '' ) );
	if ( '' === $label ) {
		return new WP_Error( 'publion_affiliate_invalid', sprintf( __( 'Affiliate-aanbieding "%s" mist een "label" (de linktekst).', 'publion' ), $id ) );
	}

	$url = esc_url_raw( (string) ( $offer['url'] ?? '' ) );
	if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
		return new WP_Error( 'publion_affiliate_invalid', sprintf( __( 'Affiliate-aanbieding "%s" heeft geen geldige http(s) "url".', 'publion' ), $id ) );
	}

	$type = sanitize_key( $offer['type'] ?? 'custom' );
	if ( ! in_array( $type, array( 'lead', 'product', 'custom' ), true ) ) {
		$type = 'custom';
	}

	$placement = sanitize_key( $offer['placement'] ?? '' );
	if ( ! in_array( $placement, array( 'mid', 'end' ), true ) ) {
		$placement = '';
	}

	$style = sanitize_key( $offer['style'] ?? 'box' );
	if ( ! in_array( $style, array( 'box', 'button', 'link' ), true ) ) {
		$style = 'box';
	}

	return array(
		'id'         => $id,
		'label'      => $label,
		'url'        => $url,
		'type'       => $type,
		'intents'    => publion_affiliate_normalize_list( $offer['intents'] ?? array() ),
		'categories' => publion_affiliate_normalize_list( $offer['categories'] ?? array() ),
		'keywords'   => publion_affiliate_normalize_list( $offer['keywords'] ?? array() ),
		'placement'  => $placement,
		'style'      => $style,
		'text'       => sanitize_text_field( (string) ( $offer['text'] ?? '' ) ),
		'enabled'    => array_key_exists( 'enabled', $offer ) ? (bool) $offer['enabled'] : true,
	);
}

/**
 * Parse the offers JSON from settings. Returns normalized offers or WP_Error.
 */
function publion_parse_affiliate_offers_json( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return array();
	}
	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) || ( array() !== $decoded && array_keys( $decoded ) !== range( 0, count( $decoded ) - 1 ) ) ) {
		return new WP_Error( 'publion_affiliate_invalid_json', __( 'De affiliate-JSON is ongeldig. Gebruik een lijst van objecten, bijvoorbeeld: [{"id":"dak-offertes","label":"Vergelijk dakoffertes","url":"https://..."}]', 'publion' ) );
	}
	$offers = array();
	$seen   = array();
	foreach ( $decoded as $i => $offer ) {
		$valid = publion_validate_affiliate_offer( $offer, $i );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( isset( $seen[ $valid['id'] ] ) ) {
			return new WP_Error( 'publion_affiliate_invalid', sprintf( __( 'Affiliate-aanbieding "%s" komt dubbel voor; elk id moet uniek zijn.', 'publion' ), $valid['id'] ) );
		}
		$seen[ $valid['id'] ] = true;
		$offers[] = $valid;
	}
	return $offers;
}

/**
 * Parsed offers for the current settings, with a request-static cache.
 */
function publion_get_affiliate_offers( $settings = null ) {
	static $cache = null;
	if ( null === $settings ) {
		if ( null !== $cache ) {
			return $cache;
		}
		$settings = publion_get_affiliate_settings();
		$offers   = publion_parse_affiliate_offers_json( $settings['affiliate_offers_json'] );
		$cache    = is_wp_error( $offers ) ? array() : $offers;
		return $cache;
	}
	$offers = publion_parse_affiliate_offers_json( $settings['affiliate_offers_json'] ?? '' );
	return is_wp_error( $offers ) ? array() : $offers;
}

function publion_find_affiliate_offer( $settings, $id ) {
	foreach ( publion_get_affiliate_offers( $settings ) as $offer ) {
		if ( $offer['id'] === $id ) {
			return $offer;
		}
	}
	return null;
}

/**
 * Score one offer against the article context. 0 means: does not apply.
 * Constraints are AND-ed: a configured intents/categories/keywords list must
 * match, otherwise the offer is skipped. More specific offers win.
 */
function publion_score_affiliate_offer( $offer, $context ) {
	if ( empty( $offer['enabled'] ) ) {
		return 0;
	}
	if ( ! empty( $offer['intents'] ) && ( '' === $context['search_intent'] || ! in_array( $context['search_intent'], $offer['intents'], true ) ) ) {
		return 0;
	}
	if ( ! empty( $offer['categories'] ) && ! in_array( $context['category'], $offer['categories'], true ) ) {
		return 0;
	}
	if ( ! empty( $offer['keywords'] ) ) {
		$haystack = trim( $context['focus_keyword'] . ' ' . $context['topic'] );
		$hit      = false;
		foreach ( $offer['keywords'] as $keyword ) {
			if ( '' !== $keyword && false !== strpos( $haystack, $keyword ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			return 0;
		}
	}
	$specificity = 0;
	if ( ! empty( $offer['keywords'] ) ) {
		$specificity += 4;
	}
	if ( ! empty( $offer['categories'] ) ) {
		$specificity += 2;
	}
	if ( ! empty( $offer['intents'] ) ) {
		$specificity += 1;
	}
	// A general offer (no constraints) matches everywhere with the lowest rank.
	return max( 1, $specificity );
}

/**
 * Pick the matching offers for an article, most specific first, capped.
 */
function publion_match_affiliate_offers( $offers, $context, $max_blocks ) {
	$scored = array();
	foreach ( $offers as $position => $offer ) {
		$score = publion_score_affiliate_offer( $offer, $context );
		if ( $score > 0 ) {
			$scored[] = array( 'score' => $score, 'position' => $position, 'offer' => $offer );
		}
	}
	usort(
		$scored,
		function ( $a, $b ) {
			if ( $a['score'] === $b['score'] ) {
				return $a['position'] - $b['position'];
			}
			return $b['score'] - $a['score'];
		}
	);
	return array_map( function ( $row ) { return $row['offer']; }, array_slice( $scored, 0, max( 1, (int) $max_blocks ) ) );
}

/**
 * Insert $insert after the first </p> that follows the $n-th <h2>. Returns the
 * original HTML when the anchor is missing, so callers can fall back.
 */
function publion_affiliate_insert_after_nth_h2_paragraph( $html, $insert, $n = 2 ) {
	if ( ! preg_match_all( '/<h2[\s>]/i', $html, $matches, PREG_OFFSET_CAPTURE ) || count( $matches[0] ) < $n ) {
		return $html;
	}
	$h2_pos  = $matches[0][ $n - 1 ][1];
	$p_close = stripos( $html, '</p>', $h2_pos );
	if ( false === $p_close ) {
		return $html;
	}
	$at = $p_close + 4;
	return substr( $html, 0, $at ) . "\n\n" . $insert . substr( $html, $at );
}

/**
 * Insert $insert right before the FAQ heading, or append at the end.
 */
function publion_affiliate_insert_before_faq_or_end( $html, $insert ) {
	if ( preg_match( '/<h2[^>]*>\s*Veelgestelde vragen/i', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
		$at = $matches[0][1];
		return substr( $html, 0, $at ) . $insert . "\n\n" . substr( $html, $at );
	}
	return $html . "\n\n" . $insert;
}

/**
 * Place affiliate shortcodes in a finished article. Deterministic: intent,
 * category and focus keyword decide which offers apply and where they land.
 * Called at the end of generation, after every content gate has passed.
 */
function publion_insert_affiliate_placeholders( $html, $seo_brief = array(), $category_name = '', $topic = '' ) {
	$settings = publion_get_affiliate_settings();
	if ( 'yes' !== $settings['affiliate_enabled'] ) {
		return $html;
	}
	$offers = publion_get_affiliate_offers( $settings );
	if ( empty( $offers ) ) {
		return $html;
	}
	$context = array(
		'search_intent' => strtolower( trim( sanitize_text_field( $seo_brief['search_intent'] ?? '' ) ) ),
		'category'      => strtolower( trim( (string) $category_name ) ),
		'focus_keyword' => strtolower( trim( (string) ( $seo_brief['focus_keyword'] ?? '' ) ) ),
		'topic'         => strtolower( trim( (string) $topic ) ),
	);
	$matched = publion_match_affiliate_offers( $offers, $context, $settings['affiliate_max_blocks'] );
	foreach ( $matched as $i => $offer ) {
		$placement = $offer['placement'];
		if ( '' === $placement ) {
			$placement = ( 0 === $i ) ? 'mid' : 'end';
		}
		$token = '[publion_affiliate id="' . $offer['id'] . '" style="' . $offer['style'] . '"]';
		if ( 'mid' === $placement ) {
			$updated = publion_affiliate_insert_after_nth_h2_paragraph( $html, $token, 2 );
			if ( $updated !== $html ) {
				$html = $updated;
				continue;
			}
		}
		$html = publion_affiliate_insert_before_faq_or_end( $html, $token );
	}
	return $html;
}

/**
 * Render one offer. Links always open in a new tab with rel="sponsored".
 */
function publion_render_affiliate_offer( $offer, $style ) {
	$url   = esc_url( $offer['url'] );
	$label = esc_html( $offer['label'] );
	$rel   = 'sponsored noopener noreferrer';
	if ( 'button' === $style ) {
		return '<p class="publion-affiliate publion-affiliate-button" style="margin:16px 0;"><a href="' . $url . '" target="_blank" rel="' . $rel . '" style="display:inline-block;padding:10px 18px;background:#1a56db;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;">' . $label . '</a></p>';
	}
	if ( 'link' === $style ) {
		return '<p class="publion-affiliate publion-affiliate-link"><a href="' . $url . '" target="_blank" rel="' . $rel . '">' . $label . '</a></p>';
	}
	$text = '' !== $offer['text'] ? '<p style="margin:0 0 8px;">' . esc_html( $offer['text'] ) . '</p>' : '';
	return '<div class="publion-affiliate publion-affiliate-box" style="border:1px solid #e2e2e2;border-left:4px solid #1a56db;border-radius:6px;padding:14px 16px;margin:24px 0;background:#fafafa;">'
		. $text
		. '<p style="margin:0;"><a href="' . $url . '" target="_blank" rel="' . $rel . '" style="font-weight:600;">' . $label . ' &rarr;</a></p>'
		. '</div>';
}

function publion_affiliate_shortcode( $atts ) {
	$settings = publion_get_affiliate_settings();
	if ( 'yes' !== $settings['affiliate_enabled'] ) {
		return '';
	}
	$atts = shortcode_atts( array( 'id' => '', 'style' => '' ), $atts, 'publion_affiliate' );
	$id   = sanitize_key( $atts['id'] );
	if ( '' === $id ) {
		return '';
	}
	$offer = publion_find_affiliate_offer( $settings, $id );
	if ( ! $offer || empty( $offer['enabled'] ) ) {
		return '';
	}
	$style = in_array( $atts['style'], array( 'box', 'button', 'link' ), true ) ? $atts['style'] : $offer['style'];
	return publion_render_affiliate_offer( $offer, $style );
}

/**
 * Prepend the disclosure when a post actually renders an affiliate link.
 * Runs on the_content before wpautop; a removed or disabled offer also
 * removes the disclosure automatically.
 */
function publion_affiliate_disclosure_filter( $content ) {
	if ( false === strpos( $content, '[publion_affiliate' ) ) {
		return $content;
	}
	$settings = publion_get_affiliate_settings();
	if ( 'yes' !== $settings['affiliate_enabled'] ) {
		return $content;
	}
	if ( ! preg_match_all( '/\[publion_affiliate[^\]]*\bid="([a-z0-9_-]+)"/i', $content, $matches ) ) {
		return $content;
	}
	$renders = false;
	foreach ( $matches[1] as $id ) {
		$offer = publion_find_affiliate_offer( $settings, sanitize_key( $id ) );
		if ( $offer && ! empty( $offer['enabled'] ) ) {
			$renders = true;
			break;
		}
	}
	if ( ! $renders ) {
		return $content;
	}
	$disclosure = trim( $settings['affiliate_disclosure_text'] );
	if ( '' === $disclosure ) {
		return $content;
	}
	return '<p class="publion-affiliate-disclosure" style="font-size:0.85em;color:#666666;font-style:italic;margin:0 0 16px;">' . esc_html( $disclosure ) . '</p>' . $content;
}
