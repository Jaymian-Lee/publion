<?php
function publion_get_allowed_openai_models() {
    $models = [
        'gpt-5.6-sol'           => 'GPT-5.6 Sol — hoogste kwaliteit',
        'gpt-5.6-terra'         => 'GPT-5.6 Terra — beste balans',
        'gpt-5.6-luna'          => 'GPT-5.6 Luna — snel en schaalbaar',
        'gpt-5.6'               => 'GPT-5.6 — alias voor Sol',
        'gpt-5.5'               => 'GPT-5.5',
        'gpt-5.4'               => 'GPT-5.4',
        'gpt-5.4-mini'          => 'GPT-5.4 Mini',
        'gpt-5.4-nano'          => 'GPT-5.4 Nano — voordelig voor volume',
        'gpt-5.2-2025-12-11'    => 'GPT-5.2 — eerdere versie',
        'gpt-5-mini-2025-08-07' => 'GPT-5 Mini — eerdere versie',
        'gpt-4o' => 'GPT-4o',
        'gpt-4o-mini' => 'GPT-4o Mini',
    ];

    return apply_filters( 'publion/openai_models', $models );
}

/**
 * Keep custom model IDs predictable and safe to pass to the API.
 * OpenAI model IDs use letters, numbers, dots, underscores, colons and hyphens.
 */
function publion_normalize_openai_model_id( $model ) {
    $model = sanitize_text_field( wp_strip_all_tags( (string) $model ) );
    $model = strtolower( trim( $model ) );

    if ( ! preg_match( '/^[a-z0-9][a-z0-9._:-]{0,127}$/', $model ) ) {
        return '';
    }

    return $model;
}

function publion_get_openai_model() {
    $default  = 'gpt-5.6-terra';
    $selected = publion_normalize_openai_model_id( get_option( 'publion_openai_model', $default ) );

    if ( '' === $selected ) {
        $selected = $default;
    }

    // A project can expose a model that is not in the curated list. Its availability
    // remains checked by OpenAI on the first request, while the format is validated here.
    return $selected;
}

/**
 * Return a user-safe explanation for failed OpenAI requests, without ever exposing a key.
 */
function publion_get_openai_request_error( $response, $model = '' ) {
    if ( is_wp_error( $response ) ) {
        $transport_error = strtolower( (string) $response->get_error_message() );
        if ( false !== strpos( $transport_error, 'timed out' ) || false !== strpos( $transport_error, 'timeout' ) || false !== strpos( $transport_error, 'curl error 28' ) ) {
            return __( 'OpenAI gaf niet op tijd een antwoord. Publion heeft de aanvraag automatisch opnieuw geprobeerd, maar kreeg nog geen reactie. Dit kan tijdelijk gebeuren bij een trage verbinding, firewall of drukte bij de dienst.', 'publion' );
        }
        return sprintf(
            /* translators: %s: transport error returned by WordPress. */
            __( 'OpenAI is niet bereikbaar: %s', 'publion' ),
            sanitize_text_field( $response->get_error_message() )
        );
    }

    $status = (int) wp_remote_retrieve_response_code( $response );
    $body   = json_decode( wp_remote_retrieve_body( $response ), true );
    $detail = is_array( $body ) ? sanitize_text_field( $body['error']['message'] ?? '' ) : '';
    $label  = $model ? ' (' . $model . ')' : '';

    if ( 401 === $status ) {
        return __( 'OpenAI heeft de API-sleutel geweigerd. Vervang de sleutel en controleer het API-project.', 'publion' );
    }

    if ( 429 === $status ) {
        return __( 'OpenAI heeft deze aanvraag tijdelijk beperkt. Controleer tegoed, facturatie en rate limits en probeer later opnieuw.', 'publion' );
    }

    if ( $detail ) {
        return sprintf(
            /* translators: 1: selected model ID, 2: error returned by OpenAI. */
            __( 'OpenAI kon het geselecteerde model%s niet gebruiken: %s', 'publion' ),
            $label,
            $detail
        );
    }

    return sprintf(
        /* translators: %d: HTTP status returned by OpenAI. */
        __( 'OpenAI gaf een onverwachte fout terug (HTTP %d).', 'publion' ),
        $status
    );
}

/** Decide whether an OpenAI request may be retried once. */
function publion_should_retry_openai_response( $response ) {
    if ( is_wp_error( $response ) ) {
        $message = strtolower( (string) $response->get_error_message() );
        foreach ( array( 'timed out', 'timeout', 'curl error 28', 'connection', 'could not resolve host', 'ssl' ) as $needle ) {
            if ( false !== strpos( $message, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    $status = (int) wp_remote_retrieve_response_code( $response );
    return 408 === $status || $status >= 500;
}

/**
 * Send an OpenAI POST request with one bounded retry for transient transport
 * failures. No WordPress post is created until the response passes all local
 * validation and duplicate checks.
 */
function publion_openai_post( $url, $args, $operation = 'general' ) {
    $args     = is_array( $args ) ? $args : array();
    $attempts = max( 1, min( 2, (int) apply_filters( 'publion/openai_request_attempts', 2, $operation ) ) );
    $response = null;

    for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
        $response = wp_remote_post( $url, $args );
        if ( ! publion_should_retry_openai_response( $response ) || $attempt === $attempts ) {
            return $response;
        }

        // Short exponential backoff with jitter; never retry in a tight loop.
        $delay_us = (int) apply_filters( 'publion/openai_retry_delay_microseconds', ( 500000 * $attempt ) + wp_rand( 0, 250000 ), $attempt, $operation, $response );
        if ( $delay_us > 0 ) {
            usleep( min( 2000000, $delay_us ) );
        }
    }

    return $response;
}

/**
 * Build a Chat Completions payload that is compatible with both the newer GPT-5
 * reasoning family and the retained GPT-4o models.
 */
function publion_build_openai_chat_body( $model, $messages, $max_output_tokens, $temperature = 0.7 ) {
    $body = array(
        'model'    => $model,
        'messages' => $messages,
    );

    if ( 0 === strpos( $model, 'gpt-5' ) ) {
        // GPT-5 reasoning models use max_completion_tokens and do not accept
        // custom sampling temperatures in Chat Completions.
        $body['max_completion_tokens'] = (int) $max_output_tokens;
    } else {
        $body['temperature'] = (float) $temperature;
        $body['max_tokens']  = (int) $max_output_tokens;
    }

    return $body;
}

/**
 * Read the local editorial archive on every AI run. The map preserves every
 * post title and heading, then adds a substantial body excerpt for context.
 * This keeps the request useful for normal API context limits while the
 * duplicate gate below still compares against the complete local post body.
 */
function publion_get_existing_content_map( $exclude_post_id = 0 ) {
    $posts = get_posts(
        array(
            'post_type'              => 'post',
            'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
            'posts_per_page'         => -1,
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            'post__not_in'           => $exclude_post_id ? array( (int) $exclude_post_id ) : array(),
            'ignore_sticky_posts'    => true,
            'suppress_filters'       => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        )
    );

    if ( empty( $posts ) ) {
        return array(
            'count'   => 0,
            'context' => "Er zijn nog geen bestaande WordPress-berichten. Schrijf daarom een duidelijk afgebakend, origineel eerste artikel.",
        );
    }

    // Preserve every title and heading, but keep body extracts compact enough
    // for a reliable API response on large editorial archives.
    $max_context_chars = (int) apply_filters( 'publion/content_map_max_chars', 120000 );
    $excerpt_chars     = (int) apply_filters( 'publion/content_map_excerpt_chars', 1200 );
    $context           = array();
    $used_chars        = 0;

    foreach ( $posts as $post ) {
        $title    = sanitize_text_field( get_the_title( $post ) );
        $headings = array();
        if ( preg_match_all( '/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', (string) $post->post_content, $matches ) ) {
            $headings = array_slice( array_filter( array_map( 'wp_strip_all_tags', $matches[1] ) ), 0, 12 );
        }

        $plain_content = preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
        $plain_content = trim( (string) $plain_content );
        $excerpt       = function_exists( 'mb_substr' ) ? mb_substr( $plain_content, 0, $excerpt_chars ) : substr( $plain_content, 0, $excerpt_chars );
        $entry         = "BERICHT #" . (int) $post->ID . ": " . $title;
        $entry        .= "\nKoppen: " . ( ! empty( $headings ) ? implode( ' | ', array_map( 'sanitize_text_field', $headings ) ) : 'geen koppen gevonden' );
        $entry        .= "\nInhoudsextract: " . ( $excerpt ?: 'geen inhoudsextract beschikbaar' ) . "\n";

        // Always preserve an entry for the post; shorten only the body extract
        // when a very large archive approaches the API safety budget.
        if ( $used_chars + strlen( $entry ) > $max_context_chars ) {
            $entry = "BERICHT #" . (int) $post->ID . ": " . $title . "\nKoppen: " . ( ! empty( $headings ) ? implode( ' | ', array_map( 'sanitize_text_field', $headings ) ) : 'geen koppen gevonden' ) . "\n";
        }

        $context[]  = $entry;
        $used_chars += strlen( $entry );
    }

    return array(
        'count'   => count( $posts ),
        'context' => implode( "\n", $context ),
    );
}

function publion_normalize_content_for_comparison( $text ) {
    $text = wp_strip_all_tags( (string) $text );
    $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
    $text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
    $text = preg_replace( '/\s+/u', ' ', $text );
    return trim( (string) $text );
}

function publion_get_content_word_set( $text ) {
    $words = preg_split( '/\s+/u', publion_normalize_content_for_comparison( $text ) );
    $set   = array();
    foreach ( (array) $words as $word ) {
        if ( 3 > ( function_exists( 'mb_strlen' ) ? mb_strlen( $word, 'UTF-8' ) : strlen( $word ) ) ) {
            continue;
        }
        $set[ $word ] = true;
    }
    return $set;
}

function publion_get_content_shingles( $text, $size = 5 ) {
    $raw_words = preg_split( '/\s+/u', publion_normalize_content_for_comparison( $text ) );
    $words     = array();
    $shingles  = array();
    foreach ( (array) $raw_words as $word ) {
        if ( 3 <= ( function_exists( 'mb_strlen' ) ? mb_strlen( $word, 'UTF-8' ) : strlen( $word ) ) ) {
            $words[] = $word;
        }
    }
    $word_count = count( $words );
    for ( $index = 0; $index <= $word_count - $size; $index++ ) {
        $shingles[ implode( ' ', array_slice( $words, $index, $size ) ) ] = true;
    }
    return $shingles;
}

/**
 * Check a candidate against every local post. Exact/near-identical titles and
 * repeated five-word sequences are blocked before WordPress creates a draft.
 */
function publion_find_existing_content_conflict( $candidate_title, $candidate_html = '', $exclude_post_id = 0 ) {
    $candidate_title = publion_normalize_content_for_comparison( $candidate_title );
    $candidate_words = $candidate_html ? publion_get_content_word_set( $candidate_html ) : array();
    $candidate_shingles = $candidate_html ? publion_get_content_shingles( $candidate_html ) : array();

    foreach ( get_posts(
        array(
            'post_type'              => 'post',
            'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
            'posts_per_page'         => -1,
            'post__not_in'           => $exclude_post_id ? array( (int) $exclude_post_id ) : array(),
            'ignore_sticky_posts'    => true,
            'suppress_filters'       => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        )
    ) as $post ) {
        $existing_title = publion_normalize_content_for_comparison( get_the_title( $post ) );
        $title_score    = 0.0;
        if ( $candidate_title && $existing_title ) {
            similar_text( $candidate_title, $existing_title, $title_score );
            if ( $candidate_title === $existing_title || $title_score >= 88 ) {
                return array( 'post_id' => (int) $post->ID, 'title' => get_the_title( $post ), 'reason' => 'titel' );
            }
        }

        if ( empty( $candidate_words ) || empty( $candidate_shingles ) ) {
            continue;
        }

        $existing_content  = (string) $post->post_content;
        $existing_words    = publion_get_content_word_set( $existing_content );
        $existing_shingles = publion_get_content_shingles( $existing_content );
        if ( empty( $existing_words ) || empty( $existing_shingles ) ) {
            continue;
        }

        $shared_words = count( array_intersect_key( $candidate_words, $existing_words ) );
        $word_union   = count( $candidate_words ) + count( $existing_words ) - $shared_words;
        $word_score   = $word_union ? $shared_words / $word_union : 0;
        $shared_phrases = count( array_intersect_key( $candidate_shingles, $existing_shingles ) );
        $shortest_phrase_set = min( count( $candidate_shingles ), count( $existing_shingles ) );
        $phrase_score = $shortest_phrase_set ? $shared_phrases / $shortest_phrase_set : 0;

        if ( ( $word_score >= 0.82 && $shared_words >= 80 ) || ( $phrase_score >= 0.16 && $shared_phrases >= 18 ) || ( $title_score >= 75 && $word_score >= 0.68 ) ) {
            return array( 'post_id' => (int) $post->ID, 'title' => get_the_title( $post ), 'reason' => 'inhoud' );
        }
    }

    return false;
}

/**
 * Reject a duplicate subject before any article or image request starts.
 *
 * The full-content validation still runs after generation; this inexpensive
 * title validation prevents an already-known duplicate from consuming a slot.
 */
function publion_validate_topic_originality( $topic, $exclude_post_id = 0 ) {
    $content_conflict = publion_find_existing_content_conflict( $topic, '', $exclude_post_id );
    if ( ! $content_conflict ) {
        return true;
    }

    $error = sprintf(
        /* translators: 1: duplicate type, 2: existing post title. */
        __( 'Concept overgeslagen: de nieuwe %1$s lijkt te veel op bestaand bericht “%2$s”. Kies een duidelijk andere zoekvraag of invalshoek.', 'publion' ),
        __( 'titel', 'publion' ),
        $content_conflict['title']
    );

    return new WP_Error( 'publion_duplicate_content', $error, array( 'conflict' => $content_conflict ) );
}

function publion_get_preferred_external_domain() {
    $settings = get_option( 'publion_post_settings', [] );
    $domain = sanitize_text_field( $settings['preferred_external_domain'] ?? '' );
    return trim( $domain );
}

function publion_get_preferred_external_urls() {
    $settings = get_option( 'publion_post_settings', [] );
    $raw = $settings['preferred_external_urls'] ?? '';
    if ( empty( $raw ) ) {
        return [];
    }
    $urls = array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', (string) $raw ) ) ) );
    return $urls;
}

/**
 * Returns the editor-approved external sources that may be used in an article.
 *
 * A URL supplied by an editor is the only link Publion can guarantee without
 * fabricating a source. The optional domain remains backwards compatible and
 * is used as its HTTPS homepage when no more specific URL is supplied.
 */
function publion_get_configured_external_reference_urls() {
    $urls   = publion_get_preferred_external_urls();
    $domain = publion_get_preferred_external_domain();

    if ( '' !== $domain ) {
        $urls[] = preg_match( '/^https?:\/\//i', $domain ) ? $domain : 'https://' . $domain;
    }

    $site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
    $valid     = array();

    foreach ( $urls as $url ) {
        $url    = esc_url_raw( trim( (string) $url ), array( 'https' ) );
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        $host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

        if ( '' === $url || 'https' !== $scheme || '' === $host || ( $site_host && 0 === strcasecmp( $site_host, $host ) ) ) {
            continue;
        }

        $key = untrailingslashit( strtolower( $url ) );
        if ( ! isset( $valid[ $key ] ) ) {
            $valid[ $key ] = $url;
        }
    }

    return array_values( $valid );
}

/**
 * Checks whether a URL is one of the exact editor-approved source URLs.
 */
function publion_is_configured_external_reference_url( $url, $reference_urls = array() ) {
    $candidate = untrailingslashit( strtolower( esc_url_raw( (string) $url, array( 'https' ) ) ) );
    if ( '' === $candidate ) {
        return false;
    }

    foreach ( $reference_urls as $reference_url ) {
        if ( $candidate === untrailingslashit( strtolower( (string) $reference_url ) ) ) {
            return true;
        }
    }

    return false;
}

function publion_normalize_domain( $domain ) {
    $domain = trim( (string) $domain );
    if ( '' === $domain ) {
        return '';
    }
    if ( ! preg_match( '/^https?:\\/\\//i', $domain ) ) {
        $domain = 'https://' . $domain;
    }
    $host = wp_parse_url( $domain, PHP_URL_HOST );
    return $host ? strtolower( $host ) : '';
}

/**
 * Return a human-readable output language from the WordPress site locale.
 *
 * Content deliberately follows the site language, not an individual editor's
 * profile language: an English-speaking administrator should not accidentally
 * publish English content on a Dutch public website.
 */
function publion_get_site_content_language() {
	$locale   = (string) get_locale();
	$language = strtolower( strtok( $locale, '_' ) );
	$languages = array(
		'nl' => 'Nederlands',
		'en' => 'English',
		'de' => 'Deutsch',
		'fr' => 'Français',
		'es' => 'Español',
		'it' => 'Italiano',
		'pt' => 'Português',
		'pl' => 'Polski',
		'da' => 'Dansk',
		'sv' => 'Svenska',
		'no' => 'Norsk',
		'fi' => 'Suomi',
	);

	$output_language = $languages[ $language ] ?? $locale;
	return (string) apply_filters( 'publion_content_language', $output_language, $locale );
}

function publion_generate_chatgpt_html( $topic, $category_name, $seo_brief = array() ) {
    $topic_validation = publion_validate_topic_originality( $topic );
    if ( is_wp_error( $topic_validation ) ) {
        update_option( 'publion_last_openai_error', $topic_validation->get_error_message() );
        return $topic_validation;
    }

    $api_key = get_option('publion_api_key', false);
    if (!$api_key) {
        $api_key = maybe_unserialize(get_option('publion_api_key'));
    }

    $model = publion_get_openai_model();
	$content_language = publion_get_site_content_language();
    // A complete response is more reliable than joining continuations: the latter can
    // leave lists and headings open and produces repeated phrases in published HTML.
    $target_word_count = 1200;
    $max_iterations = 1;

    $html_output = '';
    $word_count = 0;
    $iteration = 0;

    $reference_urls = publion_get_configured_external_reference_urls();
    $external_link_instruction = "\n\nGebruik externe links alleen als ze inhoudelijk echt iets toevoegen. Verzin, gok of reconstrueer nooit een URL. Gebruik voor externe links target=\\\"_blank\\\" rel=\\\"noopener noreferrer\\\".";
    if ( ! empty( $reference_urls ) ) {
        $external_link_instruction .= " Voeg precies één relevante externe bronlink toe uit deze door de redacteur gecontroleerde URL's. Gebruik alleen een URL uit deze lijst, met duidelijke ankertekst, bij voorkeur in een korte slotparagraaf met de kop <h2>Bronnen en verdieping</h2>:\n- " . implode( "\n- ", $reference_urls );
    } else {
        $external_link_instruction .= " Voeg alleen een andere bron toe als je de exacte, relevante HTTPS-URL zeker weet. Als je die zekerheid niet hebt, laat de link weg; een redacteur kan in de instellingen geverifieerde bron-URL's toevoegen om voor elk artikel een externe link te waarborgen.";
    }

    $focus_keyword  = sanitize_text_field( $seo_brief['focus_keyword'] ?? $topic );
    $search_intent  = sanitize_text_field( $seo_brief['search_intent'] ?? 'informatief' );
    $angle          = sanitize_text_field( $seo_brief['angle'] ?? '' );
    $faq_questions  = ! empty( $seo_brief['faq_questions'] ) && is_array( $seo_brief['faq_questions'] ) ? $seo_brief['faq_questions'] : array();
    $faq_instruction = '';
    if ( ! empty( $faq_questions ) ) {
        $faq_instruction = "\nGebruik aan het eind een <h2>Veelgestelde vragen</h2> met deze vragen als <h3>-koppen en een direct, feitelijk antwoord per vraag:\n- " . implode( "\n- ", array_map( 'sanitize_text_field', $faq_questions ) );
    }

    $content_map = publion_get_existing_content_map();
    $originality_instruction = "\n\nORIGINALITEITSCONTROLE: Lees eerst de volledige onderstaande contentkaart van " . (int) $content_map['count'] . " bestaande WordPress-berichten. Dit artikel moet een aantoonbaar nieuwe zoekvraag, invalshoek en koppenstructuur toevoegen. Kopieer, parafraseer of herstructureer geen bestaand bericht. Vermijd een titel die sterk lijkt op een bestaande titel en herhaal geen lange zinnen, FAQ's of stappenplannen uit de kaart.\n\n=== ACTUELE CONTENTKAART ===\n" . $content_map['context'] . "\n=== EINDE CONTENTKAART ===";

	$base_prompt = "Schrijf een origineel, behulpzaam en inhoudelijk sterk artikel in HTML over \"$topic\" binnen de categorie \"$category_name\". De primaire zoekterm is \"$focus_keyword\" en de zoekintentie is \"$search_intent\". Schrijf de volledige zichtbare artikelinhoud, inclusief titelkoppen, FAQ en alt-teksten, uitsluitend in $content_language.";
    if ( $angle ) {
        $base_prompt .= " De gekozen invalshoek is: \"$angle\".";
    }
    $base_prompt .= "

Schrijf voor mensen eerst en maak het antwoord ook goed citeerbaar door AI-zoekmachines: begin met een helder, direct antwoord, werk met beschrijvende <h2> en <h3>-koppen, korte alinea's, concrete voorbeelden en waar passend een lijst of stappenplan. Beantwoord de volledige zoekvraag en benoem beperkingen of context wanneer nodig. Verzin nooit feiten, cijfers, ervaringen, citaten of bronnen.

Maak de inhoud bruikbaar voor klassieke zoekmachines, antwoordmachines en generatieve zoekinterfaces: definieer belangrijke begrippen direct, houd feitelijke beweringen specifiek en controleerbaar, gebruik relevante entiteiten bij naam en plaats antwoorden vlak bij de vraag waarop ze reageren. Voeg geen tekst toe die alleen voor een algoritme is geschreven en doe geen ongefundeerde claims over resultaten, prijzen, kortingen of prestaties.

Wanneer de zoekintentie commercieel of transactioneel is, help de lezer eerlijk vergelijken met duidelijke selectiecriteria, beperkingen en een rustige volgende stap. Schrijf geen advertentietekst, verzin geen aanbiedingen en gebruik geen druk- of clickbaittaal.

Schrijf circa 1.200 tot 1.600 woorden, maar vermijd opvultekst en herhaling. Voeg geen paginamarkup toe zoals <!DOCTYPE html>, <head>, <body>, <header>, <footer>, <script> of <meta>. Maak de eerste kop een <h2>, geen <h1>. Gebruik uitsluitend semantische content-HTML: <p>, <h2>, <h3>, <ul>, <ol>, <strong>, <table> waar dat inhoudelijk helpt. Sluit elke HTML-tag. Plaats een kop, alinea, tabel of nieuw onderwerp nooit binnen een <ul> of <ol>; daarin staan uitsluitend <li>-elementen. Herhaal een kop, openingszin, woordgroep of FAQ-vraag niet.

Neem alleen links op naar relevante, betrouwbare en verifieerbare bronnen.$external_link_instruction$faq_instruction$originality_instruction

Geef uitsluitend valide HTML-content terug, zonder uitleg, notities of Markdown.";

    $messages = [
        ['role' => 'system', 'content' => 'Je bent een deskundige contentschrijver. Je bent strikt feitelijk, vermijdt SEO-spam en geeft uitsluitend valide HTML terug. De taal van de zichtbare inhoud volgt altijd de WordPress-sitetaal: ' . $content_language . '.'],
        ['role' => 'user', 'content' => $base_prompt]
    ];

    while ($word_count < $target_word_count && $iteration < $max_iterations) {
        $response = publion_openai_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( publion_build_openai_chat_body( $model, $messages, 4096 ) ),
            'timeout' => 135
        ], 'article');

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            $error = publion_get_openai_request_error( $response, $model );
            update_option( 'publion_last_openai_error', $error );
            return new WP_Error( 'publion_openai_request_failed', $error );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $new_html = $body['choices'][0]['message']['content'] ?? '';

        if (!$new_html) {
            $error = __( 'OpenAI gaf geen bruikbare artikeltekst terug. Probeer een ander model of verkort de voorprompt.', 'publion' );
            update_option( 'publion_last_openai_error', $error );
            return new WP_Error( 'publion_openai_empty_response', $error );
        }

        $html_output .= $new_html;
        $word_count = str_word_count(wp_strip_all_tags($html_output));
        $iteration++;

    }

    // Clean & validate
    $html_output = publion_clean_html_output($html_output);
    $html_output = publion_normalize_article_html( $html_output );
    $html_output = publion_validate_links_in_html( $html_output, $reference_urls );
    $html_output = publion_enhance_external_links($html_output);
    $html_output = publion_ensure_configured_external_reference( $html_output, $reference_urls, $topic );
	$html_output = preg_replace('/<h1>(.*?)<\/h1>/i', '<h2>$1</h2>', $html_output, 1);
	$html_output = str_ireplace(['<header>', '</header>'], '', $html_output);
	$keywords = publion_extract_noun_keywords($html_output, $api_key, $model);
	$html_output = publion_auto_internal_links($html_output, $keywords);

    $content_conflict = publion_find_existing_content_conflict( $topic, $html_output );
    if ( $content_conflict ) {
        $error = sprintf(
            /* translators: 1: duplicate type, 2: existing post title. */
            __( 'Concept overgeslagen: de nieuwe %1$s lijkt te veel op bestaand bericht “%2$s”. Kies een duidelijk andere zoekvraag of invalshoek.', 'publion' ),
            'titel' === $content_conflict['reason'] ? __( 'titel', 'publion' ) : __( 'inhoud', 'publion' ),
            $content_conflict['title']
        );
        update_option( 'publion_last_openai_error', $error );
        return new WP_Error( 'publion_duplicate_content', $error );
    }

    // Append CTA
    $settings = get_option('publion_post_settings', []);
    if (($settings['cta_enabled'] ?? 'no') === 'yes' && !empty($settings['cta_text']) && !empty($settings['cta_link'])) {
        $html_output .= "<div style='clear:both; padding-top:20px; margin-top:30px; border-top:1px solid #ccc;'>
            <p>Hulp nodig bij <strong>{$topic}</strong>?<br><strong><em><a class=\"ai-blog-cta\" href='" . esc_url($settings['cta_link']) . "'>{$settings['cta_text']}</a></em></strong></p>
        </div>";
    }

    // Rename Conclusion heading
    $html_output = preg_replace_callback(
        '/<h([2-4])[^>]*>(\s*)(Conclusion|Conclusie)(\s*)<\/h\1>/i',
        function($matches) {
            return '<h' . $matches[1] . '>Belangrijkste punten</h' . $matches[1] . '>';
        },
        $html_output
    );
    
    $html_output = preg_replace('/<h([1-2])>(.*?)<\/h\1>/i', '<h$1 class="publion-title">$2</h$1>', $html_output, 1);

    delete_option( 'publion_last_openai_error' );
    return $html_output;
}

/**
 * Extracts FAQ pairs from a generated article for optional JSON-LD output.
 * The schema is kept outside post content so WordPress sanitizers cannot strip it.
 */
function publion_extract_faq_pairs( $html ) {
    $faqs = array();
    if ( ! preg_match( '/<h2[^>]*>\s*(Veelgestelde vragen|FAQ)\s*<\/h2>(.*)$/is', $html, $section ) ) {
        return $faqs;
    }

    if ( preg_match_all( '/<h3[^>]*>(.*?)<\/h3>\s*<p[^>]*>(.*?)<\/p>/is', $section[2], $pairs, PREG_SET_ORDER ) ) {
        foreach ( $pairs as $pair ) {
            $question = trim( wp_strip_all_tags( $pair[1] ) );
            $answer   = trim( wp_strip_all_tags( $pair[2] ) );
            if ( $question && $answer ) {
                $faqs[] = array( 'question' => $question, 'answer' => $answer );
            }
            if ( count( $faqs ) >= 5 ) {
                break;
            }
        }
    }
    return $faqs;
}

function publion_store_article_seo_data( $post_id, $post_html, $focus_keyword = '' ) {
    update_post_meta( $post_id, '_publion_focus_keyword', sanitize_text_field( $focus_keyword ) );
    update_post_meta( $post_id, '_publion_faq_pairs', publion_extract_faq_pairs( $post_html ) );
}

function publion_auto_internal_links($html, $keywords) {
    if (empty($keywords)) return $html;

    // Extract existing <a> tags to prevent duplicate or nested links
    preg_match_all('/<a\b[^>]*>.*?<\/a>/is', $html, $a_matches);
    $placeholders = [];
    foreach ($a_matches[0] as $i => $a_tag) {
        $ph = "%%A_TAG_$i%%";
        $placeholders[$ph] = $a_tag;
        $html = str_replace($a_tag, $ph, $html);
    }

    // Split HTML into <p> blocks
    $paragraphs = preg_split('/(<\/p>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $combined = [];
    for ($i = 0; $i < count($paragraphs); $i += 2) {
        $combined[] = ($paragraphs[$i] ?? '') . ($paragraphs[$i + 1] ?? '');
    }

    $sections = array_chunk($combined, ceil(count($combined) / 4));
    $linked = 0;

    foreach ($keywords as $keyword) {
        if ($linked >= 4) break;

        $query = new WP_Query([
            's' => $keyword,
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'post_type' => ['post', 'page'],
            'orderby' => 'relevance',
        ]);

        if ($query->have_posts()) {
            $post = $query->posts[0];
            $url = get_permalink($post);
            $pattern = '/\b(' . preg_quote($keyword, '/') . ')\b/i';
            $replacement = '<a href="' . esc_url($url) . '" rel="internal" target="_blank">$1</a>';

            // Try placing it in a different quarter of the content each time
            for ($section_index = $linked; $section_index < count($sections); $section_index++) {
                for ($i = 0; $i < count($sections[$section_index]); $i++) {
                    $new_para = preg_replace($pattern, $replacement, $sections[$section_index][$i], 1);
                    if ($new_para !== $sections[$section_index][$i]) {
                        $sections[$section_index][$i] = $new_para;
                        $linked++;
                        break 2;
                    }
                }
            }
        }

        wp_reset_postdata();
    }

    // Reassemble content
    $final_html = '';
    foreach ($sections as $group) {
        foreach ($group as $block) {
            $final_html .= $block;
        }
    }

    // Restore saved <a> tags
    foreach ($placeholders as $ph => $tag) {
        $final_html = str_replace($ph, $tag, $final_html);
    }

    return $final_html;
}

function publion_extract_noun_keywords($text, $api_key, $model = '') {
    $prompt = "Extraheer 10 van de belangrijkste zelfstandige naamwoorden of zelfstandige naamwoordgroepen uit de volgende blogpostcontent. Geef ze terug als een platte JSON-array met strings. Gebruik geen werkwoorden of bijvoeglijke naamwoorden.

$text";

    $response = publion_openai_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => wp_json_encode( publion_build_openai_chat_body(
            $model ?: publion_get_openai_model(),
            array(
                array( 'role' => 'system', 'content' => 'Je bent een assistent die nuttige keywords extraheert.' ),
                array( 'role' => 'user', 'content' => $prompt ),
            ),
            200,
            0.3
        ) ),
        'timeout' => 60
    ], 'keywords');

    $body = json_decode(wp_remote_retrieve_body($response), true);
	// Get raw response content
	$content = trim($body['choices'][0]['message']['content'] ?? '');

	// Manually strip ```json or ``` from start and end
	if (str_starts_with($content, '```json')) {
	    $content = substr($content, 7);
	} elseif (str_starts_with($content, '```')) {
	    $content = substr($content, 3);
	}

	$content = rtrim($content, " \n\r\t`");

	// Optional quote cleanup
	$content = str_replace(['“','”',"'"], '"', $content);
	$content = preg_replace('/,\s*]/', ']', $content);

	// Decode
	$keywords = json_decode($content, true);

    return is_array($keywords) ? $keywords : [];
}

function publion_validate_links_in_html( $html, $reference_urls = array() ) {
    if (empty($html)) return $html;

    // Match all <a href="...">...</a> links
    preg_match_all('/<a\b[^>]*href=["\'](.*?)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $url = $match[1];
        $anchor_text = $match[2];

        // Skip invalid or non-http(s) links
        if (!preg_match('/^https?:\/\//i', $url)) continue;

        // An editor-approved URL is intentionally retained even when its host
        // blocks automated probes. Many reputable services reject HEAD calls.
        if ( publion_is_configured_external_reference_url( $url, $reference_urls ) ) {
            continue;
        }

        $response = wp_remote_head( $url, array( 'timeout' => 5, 'redirection' => 3 ) );
        $code = wp_remote_retrieve_response_code($response);

        // A number of reliable sites return 403/405 for HEAD while serving a
        // normal page. Use a tiny ranged GET as a safe fallback before
        // deciding that a generated source is broken.
        if ( is_wp_error( $response ) || ! $code || $code >= 400 ) {
            $response = wp_remote_get(
                $url,
                array(
                    'timeout'             => 7,
                    'redirection'         => 3,
                    'limit_response_size' => 1024,
                    'headers'             => array( 'Range' => 'bytes=0-1023' ),
                )
            );
            $code = wp_remote_retrieve_response_code( $response );
        }

        // If broken, remove the full anchor tag and keep only the anchor text
        if (!$code || $code >= 400) {
            $html = str_replace($match[0], $anchor_text, $html);
        }
    }

    return $html;
}

function publion_clean_html_output($html) {
    // Step 1: Remove everything before <article>
    $html = preg_replace('/.*?<article>/is', '', $html);

    // Step 2: Remove </article> if it exists
    $html = str_replace('</article>', '', $html);
    $html = str_replace('<article>', '', $html);

    // Step 3: Trim after the LAST </section>
    $last_section_pos = strripos($html, '</section>');
    if ($last_section_pos !== false) {
        $html = substr($html, 0, $last_section_pos + 10); // 10 = length of </section>
    }

    // Step 4: Remove content BETWEEN adjacent </section><section> tags (usually noise)
    $html = preg_replace('/<\/section>\s*[^<]*?<section>/is', '</section><section>', $html);

    // Step 5: Strip all remaining <section> and </section> tags
    $html = str_replace(['<section>', '</section>'], '', $html);

    // Step 6: Remove triple backticks or similar junk
    $html = preg_replace('/[`]{3,}(html)?/i', '', $html);

    // Step 7: Remove all HTML entities like &lt;, &gt;, &nbsp;, etc.
    $html = preg_replace('/&[a-z0-9#]+;/i', '', $html);

    // Final trim
    return trim($html);
}

/**
 * Applies a final, conservative cleanup after generation. WordPress will still
 * sanitize on save, but balancing here keeps the editor and frontend markup
 * predictable before images and links are added.
 */
function publion_normalize_article_html( $html ) {
    $html = wp_kses_post( (string) $html );
    $html = force_balance_tags( $html );
    $html = preg_replace( '/<p>\s*(<(?:h[2-4]|ul|ol|table|figure)\b[^>]*>)/i', '$1', $html );
    $html = preg_replace( '/(<\/(?:h[2-4]|ul|ol|table|figure)>)\s*<\/p>/i', '$1', $html );
    $html = preg_replace( '/<p>\s*<\/p>/i', '', $html );

    return trim( $html );
}

/**
 * Shortens text on a word boundary, avoiding cut-off words in snippets and alt text.
 */
function publion_trim_text_at_word_boundary( $text, $max_length = 155 ) {
    $text       = preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( (string) $text ) ) );
    $max_length = max( 20, (int) $max_length );

    if ( mb_strlen( $text ) <= $max_length ) {
        return $text;
    }

    $trimmed = mb_substr( $text, 0, $max_length + 1 );
    $trimmed = preg_replace( '/\s+\S*$/u', '', $trimmed );
    return rtrim( $trimmed, " \t\n\r\0\x0B,;:-" );
}

/**
 * Produces a readable search snippet without truncating a word or sentence.
 */
function publion_build_meta_description( $html, $max_length = 155 ) {
    $text       = preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( (string) $html ) ) );
    $max_length = max( 120, min( 160, (int) $max_length ) );

    if ( mb_strlen( $text ) <= $max_length ) {
        return $text;
    }

    $sentences = preg_split( '/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
    $result    = '';
    foreach ( $sentences as $sentence ) {
        $candidate = trim( $result . ' ' . $sentence );
        if ( mb_strlen( $candidate ) > $max_length ) {
            break;
        }
        $result = $candidate;
        if ( mb_strlen( $result ) >= 110 ) {
            break;
        }
    }

    return mb_strlen( $result ) >= 90 ? $result : publion_trim_text_at_word_boundary( $text, $max_length );
}

function publion_upload_image($url, $context = '') {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url);

    // Create a slug-like filename from the context
    $slug = sanitize_title($context);
    if (empty($slug)) $slug = 'publion-image';

	$parsed_url = wp_parse_url($url);
	$ext = pathinfo($parsed_url['path'] ?? '', PATHINFO_EXTENSION);
    if (!$ext) $ext = 'jpg';
    $filename = $slug . '-' . wp_generate_password(6, false, false) . '.' . $ext;

    $file_array = [
        'name'     => $filename,
        'tmp_name' => $tmp
    ];

    // Upload
    $id = media_handle_sideload($file_array, 0);

    // Optionally update image title and alt text
    wp_update_post([
        'ID'         => $id,
        'post_title' => ucwords(str_replace('-', ' ', $slug)),
        'post_name'  => $slug,
    ]);

    update_post_meta($id, '_wp_attachment_image_alt', publion_build_descriptive_image_alt( $context ));

    return [
        'attachment_id' => $id,
        'url' => wp_get_attachment_url($id)
    ];
}

function publion_build_descriptive_image_alt( $context = '' ) {
    $context = html_entity_decode( wp_strip_all_tags( (string) $context ), ENT_QUOTES, 'UTF-8' );
    $context = preg_replace( '/\s+/', ' ', trim( $context ) );
    if ( '' === $context ) {
        return 'Illustratie bij het artikel';
    }
    $context = publion_trim_text_at_word_boundary( $context, 92 );
    return 'Illustratie bij: ' . $context;
}

function publion_ensure_preferred_domain_link( $html, $preferred_domain, $topic ) {
    $preferred_domain = trim( (string) $preferred_domain );
    if ( '' === $preferred_domain || empty( $html ) ) {
        return $html;
    }

    $host = publion_normalize_domain( $preferred_domain );
    if ( '' === $host ) {
        return $html;
    }

    if ( preg_match( '/https?:\\/\\/' . preg_quote( $host, '/' ) . '/i', $html ) ) {
        return $html;
    }

    $url = ( preg_match( '/^https?:\\/\\//i', $preferred_domain ) ) ? $preferred_domain : 'https://' . $preferred_domain;
    $anchor = esc_html( $topic ? $topic : $host );
    $link_html = '<a href="' . esc_url( $url ) . '" target="_blank" rel="dofollow noopener noreferrer">' . $anchor . '</a>';

    if ( preg_match( '/<p[^>]*>.*?<\\/p>/is', $html, $m ) ) {
        $new_p = preg_replace( '/<\\/p>$/', ' ' . $link_html . '</p>', $m[0] );
        return preg_replace( '/<p[^>]*>.*?<\\/p>/is', $new_p, $html, 1 );
    }

    return $html . '<p>' . $link_html . '</p>';
}

/**
 * Guarantees one safe external source when the editor has configured one.
 * The fallback never invents a source: it only uses an exact URL saved in the
 * settings screen. It is called after generated links have been checked.
 */
function publion_ensure_configured_external_reference( $html, $reference_urls, $topic ) {
    if ( empty( $html ) || empty( $reference_urls ) || ! is_array( $reference_urls ) ) {
        return $html;
    }

    if ( preg_match_all( '/<a\b[^>]*href=["\'](https?:\/\/[^"\']+)["\'][^>]*>/i', $html, $links ) ) {
        foreach ( $links[1] as $link ) {
            if ( publion_is_configured_external_reference_url( $link, $reference_urls ) ) {
                return $html;
            }
        }
    }

    $index = abs( (int) crc32( sanitize_title( (string) $topic ) ) ) % count( $reference_urls );
    $url   = $reference_urls[ $index ];
    $host  = (string) wp_parse_url( $url, PHP_URL_HOST );
    $label = sprintf(
        /* translators: %s: source website hostname. */
        __( 'Meer achtergrondinformatie van %s', 'publion' ),
        $host
    );
    $link_html = '<p class="publion-external-source"><strong>' . esc_html__( 'Bron en verdieping:', 'publion' ) . '</strong> <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a></p>';

    return $html . $link_html;
}

function publion_get_allowed_openai_image_models() {
    $models = array(
        'gpt-image-2'   => 'GPT Image 2 — aanbevolen',
        'gpt-image-1.5' => 'GPT Image 1.5 — vorige generatie',
        'gpt-image-1'   => 'GPT Image 1 — eerdere generatie',
    );

    return apply_filters( 'publion/openai_image_models', $models );
}

function publion_get_openai_image_model() {
    $default  = 'gpt-image-2';
    $selected = publion_normalize_openai_model_id( get_option( 'publion_openai_image_model', $default ) );

    return apply_filters( 'publion/openai_image_model', $selected ? $selected : $default );
}

/**
 * GPT Image 2 accepts arbitrary divisible-by-16 sizes, so use a true 16:9
 * asset when it is selected. Earlier GPT Image models retain their documented
 * standard landscape size and are cropped by the presentation layer if needed.
 */
function publion_get_image_size_for_layout( $layout ) {
    if ( 'square' === $layout ) {
        return '1024x1024';
    }

    return 0 === strpos( publion_get_openai_image_model(), 'gpt-image-2' ) ? '1536x864' : '1536x1024';
}

function publion_build_image_prompt( $topic, $category_name = '' ) {
    $topic = trim( (string) $topic );
    $category_name = trim( (string) $category_name );

    if ( $category_name !== '' ) {
        return 'Maak een realistische, hoogwaardige foto-achtige afbeelding voor een blogpost over "' . $topic . '" in de categorie "' . $category_name . '". Contextueel en relevant, zonder tekst, watermerk of logo.';
    }

    return 'Maak een realistische, hoogwaardige foto-achtige afbeelding voor een blogpost over "' . $topic . '". Contextueel en relevant, zonder tekst, watermerk of logo.';
}

function publion_generate_contextual_image_prompts( $html, $topic, $category_name = '' ) {
    $blocks = [];
    preg_match_all( '/<h[2-4][^>]*>.*?<\\/h[2-4]>|<p[^>]*>.*?<\\/p>/is', $html, $matches, PREG_OFFSET_CAPTURE );
    $all_offsets = $matches[0] ?? [];

    $length = strlen( $html );
    $desired_positions = [
        floor( $length * 0.165 ),
        floor( $length * 0.33 ),
        floor( $length * 0.495 ),
        floor( $length * 0.66 ),
        floor( $length * 0.825 ),
    ];

    foreach ( $desired_positions as $target ) {
        $closest = null;
        $min_diff = PHP_INT_MAX;
        $text = '';
        foreach ( $all_offsets as $offset ) {
            $diff = abs( $offset[1] - $target );
            if ( $diff < $min_diff ) {
                $min_diff = $diff;
                $closest = $offset[1];
                $text = wp_strip_all_tags( $offset[0] );
            }
        }
        $text = trim( preg_replace( '/\s+/', ' ', $text ) );
        if ( $text !== '' ) {
            $blocks[] = $text;
        }
    }

    $topic = trim( (string) $topic );
    $category_name = trim( (string) $category_name );

    $items = [];
    foreach ( $blocks as $i => $block_text ) {
        $layout = ( 0 === $i % 2 ) ? 'landscape' : 'square';
        $size   = publion_get_image_size_for_layout( $layout );
        $context = mb_substr( $block_text, 0, 180 );
        $prompt = 'Maak een realistische, hoogwaardige foto-achtige afbeelding die past bij dit tekstfragment: "' . $context . '". ';
        $prompt .= 'Onderwerp: "' . $topic . '". ';
        if ( $category_name !== '' ) {
            $prompt .= 'Categorie: "' . $category_name . '". ';
        }
        $prompt .= 'Contextueel en relevant, geen tekst, watermerk of logo. Maak de compositie duidelijk anders dan andere afbeeldingen in dit artikel. ';
        $prompt .= ( 'landscape' === $layout )
            ? 'Gebruik een brede horizontale compositie met ruimte aan de zijkanten; het beeld wordt in een 16:9 artikelvlak getoond.'
            : 'Gebruik een sterke vierkante compositie met het hoofdonderwerp centraal; het beeld wordt in een 1:1 artikelvlak getoond.';
        $items[] = [
            'prompt'  => $prompt,
            'context' => $context,
            'layout'  => $layout,
            'size'    => $size,
        ];
    }

    // Featured image prompt (6e)
    $featured = publion_build_image_prompt( $topic, $category_name ) . ' Maak de compositie duidelijk anders dan de andere afbeeldingen. Gebruik een brede horizontale compositie die geschikt is als uitgelichte afbeelding.';
    $items[] = [
        'prompt'  => $featured,
        'context' => $topic,
        'layout'  => 'landscape',
        'size'    => publion_get_image_size_for_layout( 'landscape' ),
    ];

    return array_slice( $items, 0, 6 );
}

function publion_is_external_url( $url ) {
    $host = wp_parse_url( home_url(), PHP_URL_HOST );
    $link_host = wp_parse_url( $url, PHP_URL_HOST );
    return ! empty( $link_host ) && ! empty( $host ) && strcasecmp( $host, $link_host ) !== 0;
}

function publion_enhance_external_links( $html ) {
    if ( empty( $html ) ) {
        return $html;
    }

    return preg_replace_callback(
        '/<a\\s+[^>]*href=[\"\\\']([^\"\\\']+)[\"\\\'][^>]*>/i',
        function ( $matches ) {
            $tag = $matches[0];
            $url = $matches[1];

            if ( ! preg_match( '/^https?:\\/\\//i', $url ) ) {
                return $tag;
            }
            if ( ! publion_is_external_url( $url ) ) {
                return $tag;
            }

            if ( stripos( $tag, 'target=' ) === false ) {
                $tag = rtrim( $tag, '>' ) . ' target="_blank">';
            }

            if ( preg_match( '/\\srel=[\"\\\']([^\"\\\']*)[\"\\\']/i', $tag, $rel_match ) ) {
                $rels = preg_split( '/\\s+/', $rel_match[1] );
                $rels = array_filter( array_map( 'strtolower', $rels ) );
                foreach ( [ 'noopener', 'noreferrer' ] as $rel ) {
                    if ( ! in_array( $rel, $rels, true ) ) {
                        $rels[] = $rel;
                    }
                }
                $new_rel = implode( ' ', array_unique( $rels ) );
                $tag = preg_replace( '/\\srel=[\"\\\'][^\"\\\']*[\"\\\']/i', ' rel="' . esc_attr( $new_rel ) . '"', $tag );
            } else {
                $tag = rtrim( $tag, '>' ) . ' rel="noopener noreferrer">';
            }

            return $tag;
        },
        $html
    );
}

function publion_generate_image_base64s( $prompt, $api_key, $count = 1, $size = '1024x1024' ) {
    $prompt = trim( (string) $prompt );
    $api_key = trim( (string) $api_key );
    $count = max( 1, (int) $count );
    $allowed_sizes = array( '1024x1024', '1536x1024', '1024x1536' );
    if ( 0 === strpos( publion_get_openai_image_model(), 'gpt-image-2' ) ) {
        $allowed_sizes[] = '1536x864';
    }
    $size = in_array( $size, $allowed_sizes, true ) ? $size : '1024x1024';

    if ( '' === $prompt || '' === $api_key ) {
        return [];
    }

    $image_model = publion_get_openai_image_model();
    $response = publion_openai_post(
        'https://api.openai.com/v1/images/generations',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode(
                [
                    'model'         => $image_model,
                    'prompt'        => $prompt,
                    'n'             => $count,
                    'size'          => $size,
                    'quality'       => 'medium',
                    'output_format' => 'jpeg',
                ]
            ),
            'timeout' => 180,
        ],
        'image'
    );

    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        update_option( 'publion_last_image_error', publion_get_openai_request_error( $response, $image_model ) );
        return [];
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
        update_option( 'publion_last_image_error', 'Geen afbeeldingsdata ontvangen.' );
        return [];
    }

    $images = [];
    foreach ( $body['data'] as $item ) {
        $entry = [
            'b64_json' => $item['b64_json'] ?? '',
            'url'      => $item['url'] ?? '',
        ];
        if ( '' !== $entry['b64_json'] || '' !== $entry['url'] ) {
            $images[] = $entry;
        }
    }

    if ( empty( $images ) ) {
        update_option( 'publion_last_image_error', 'Lege afbeeldingsdata ontvangen.' );
        return [];
    }

    delete_option( 'publion_last_image_error' );
    return $images;
}

function publion_upload_image_base64( $base64, $context = '', $format = 'jpeg' ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $base64 = trim( (string) $base64 );
    if ( '' === $base64 ) {
        return false;
    }

    $binary = base64_decode( $base64 );
    if ( false === $binary ) {
        return false;
    }

    $tmp = wp_tempnam( 'publion-image' );
    if ( ! $tmp ) {
        return false;
    }

    file_put_contents( $tmp, $binary );

    $slug = sanitize_title( $context );
    if ( empty( $slug ) ) {
        $slug = 'publion-image';
    }

    $ext = 'jpg';
    if ( 'png' === $format ) {
        $ext = 'png';
    } elseif ( 'webp' === $format ) {
        $ext = 'webp';
    }

    $filename = $slug . '-' . wp_generate_password( 6, false, false ) . '.' . $ext;

    $file_array = [
        'name'     => $filename,
        'tmp_name' => $tmp,
    ];

    $id = media_handle_sideload( $file_array, 0 );
    if ( is_wp_error( $id ) ) {
        @unlink( $tmp );
        return false;
    }

    wp_update_post(
        [
            'ID'         => $id,
            'post_title' => ucwords( str_replace( '-', ' ', $slug ) ),
            'post_name'  => $slug,
        ]
    );

    update_post_meta( $id, '_wp_attachment_image_alt', publion_build_descriptive_image_alt( $context ) );

    return [
        'attachment_id' => $id,
        'url'           => wp_get_attachment_url( $id ),
    ];
}

function publion_generate_and_upload_images( $prompt, $count, $context, $api_key, $size = '1024x1024' ) {
    $images = publion_generate_image_base64s( $prompt, $api_key, $count, $size );
    if ( empty( $images ) ) {
        // Fallback: try single-image requests if bulk failed.
        $fallback = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $single = publion_generate_image_base64s( $prompt, $api_key, 1, $size );
            if ( ! empty( $single ) ) {
                $fallback[] = $single[0];
            }
        }
        if ( empty( $fallback ) ) {
            return [ 'urls' => [], 'ids' => [] ];
        }
        $images = $fallback;
    }

    $uploaded_urls = [];
    $uploaded_ids  = [];

    foreach ( $images as $image ) {
        if ( ! empty( $image['b64_json'] ) ) {
            $upload = publion_upload_image_base64( $image['b64_json'], $context, 'jpeg' );
        } elseif ( ! empty( $image['url'] ) ) {
            $upload = publion_upload_image( $image['url'], $context );
        } else {
            $upload = false;
        }
        if ( is_array( $upload ) && isset( $upload['attachment_id'], $upload['url'] ) ) {
            $uploaded_ids[]  = (int) $upload['attachment_id'];
            $uploaded_urls[] = $upload['url'];
        }
    }

    return [
        'urls' => $uploaded_urls,
        'ids'  => $uploaded_ids,
    ];
}

function publion_process_and_upload_images($remote_image_urls) {
    $processed = [];

    foreach ($remote_image_urls as $url) {
        $upload = publion_upload_image($url);
        if ($upload && isset($upload['url'])) {
            $processed[] = $upload;
        }
    }

    return $processed;
}

function publion_insert_images_into_content($html, $image_urls, $layouts = array()) {
    if (!is_array($image_urls) || count($image_urls) < 5 || empty($html)) return $html;

    // Match to headings (h2–h4)
    preg_match_all('/<h[2-4][^>]*>.*?<\/h[2-4]>|<p[^>]*>.*?<\/p>/is', $html, $matches, PREG_OFFSET_CAPTURE);
    $all_offsets = $matches[0];

    $length = strlen($html);
    $desired_positions = [
        floor($length * 0.165),
        floor($length * 0.33),
        floor($length * 0.495),
        floor($length * 0.66),
        floor($length * 0.825)
    ];

    $inserts = [];
    foreach ($desired_positions as $i => $target) {
        $closest = null;
        $min_diff = PHP_INT_MAX;
        $alt_text = '';

        foreach ($all_offsets as $offset) {
            $diff = abs($offset[1] - $target);
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $closest = $offset[1];
                // Keep the alt text concise, contextual and useful for screen readers.
                $alt_text = wp_strip_all_tags($offset[0]);
                $alt_text = preg_replace('/\s+/', ' ', trim($alt_text));
                $alt_text = implode(' ', array_slice(explode(' ', $alt_text), 0, 14));
            }
        }

        $layout = isset( $layouts[ $i ] ) && 'square' === $layouts[ $i ] ? 'square' : 'landscape';
        $alt_text = publion_build_descriptive_image_alt( $alt_text );
        $inserts[] = array(
            'offset' => $closest ?? $target,
            'html'   => '<figure class="publion-article-media publion-article-media--' . $layout . '"><img class="publion-generated-image" src="' . esc_url($image_urls[$i]) . '" alt="' . esc_attr($alt_text) . '" loading="lazy" decoding="async" /></figure>',
        );
    }

    // Sort descending so offsets don’t shift
    usort(
        $inserts,
        static function ( $a, $b ) {
            return $b['offset'] <=> $a['offset'];
        }
    );
    foreach ($inserts as $insert) {
        $html = substr_replace($html, $insert['html'], $insert['offset'], 0);
    }

    return $html;
}

function publion_get_pixabay_images($topic, $count) {
    $api_key = '43505663-0cdcc08fe88f23c843f4a27c3';
    $endpoint = 'https://pixabay.com/api/';

    // First attempt: same topic, random page
    $params = [
        'key'         => $api_key,
        'q'           => urlencode($topic),
        'image_type'  => 'all',
        'orientation' => 'horizontal',
        'safesearch'  => 'true',
        'per_page'    => 200,
        'page'        => wp_rand(1, 3),
        'order'       => 'latest'
    ];

    $url = $endpoint . '?' . http_build_query($params);
    $response = wp_remote_get($url);

    if (is_wp_error($response)) return [];

    $body = json_decode(wp_remote_retrieve_body($response), true);

    // If not enough images, try again using same topic but fixed to page 1
    if (empty($body['hits']) || count($body['hits']) < $count) {
	    $params = [
	        'key'         => $api_key,
	        'q'           => urlencode($topic),
	        'image_type'  => 'all',
	        'orientation' => 'horizontal',
	        'safesearch'  => 'true',
	        'per_page'    => 200,
	        'page'        => 1,
	        'order'       => 'popular'
	    ];
        $url = $endpoint . '?' . http_build_query($params);
        $response = wp_remote_get($url);
        $body = json_decode(wp_remote_retrieve_body($response), true);
    }

    if (empty($body['hits'])) return [];

    shuffle($body['hits']);
    $selected = array_slice($body['hits'], 0, $count);

    return array_column($selected, 'largeImageURL');
}

function publion_extract_keywords($topic, $max_words = 3) {
    // Lowercase and remove punctuation
    $clean = strtolower(preg_replace('/[^\w\s]/', '', $topic));

    // Stopwords to skip
    $stopwords = ['the', 'of', 'in', 'and', 'on', 'to', 'for', 'with', 'a', 'an', 'is', 'are', 'as', 'by', 'at', 'from'];

    // Explode into words and remove stopwords
    $words = array_filter(explode(' ', $clean), function($word) use ($stopwords) {
        return !in_array($word, $stopwords) && strlen($word) > 2;
    });

    // Limit to $max_words
    $keywords = array_slice($words, 0, $max_words);

    return implode(' ', $keywords);
}

