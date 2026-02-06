<?php
function publion_get_allowed_openai_models() {
    $models = [
        'gpt-5.2-2025-12-11' => 'GPT-5.2',
        'gpt-5-mini-2025-08-07' => 'GPT-5 Mini',
        'gpt-4o' => 'GPT-4o',
        'gpt-4o-mini' => 'GPT-4o Mini',
    ];

    return apply_filters( 'publion/openai_models', $models );
}

function publion_get_openai_model() {
    $models   = publion_get_allowed_openai_models();
    $default  = 'gpt-4o';
    $selected = sanitize_text_field( get_option( 'publion_openai_model', $default ) );

    if ( ! isset( $models[ $selected ] ) ) {
        $selected = array_key_first( $models );
    }

    return $selected ?: $default;
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

function publion_get_default_post_prompt_template() {
    return "Je bent een ervaren content- en SEO-schrijver. Schrijf een behulpzame, scanbare blogpost in HTML-formaat.\n\n"
        . "Onderwerp: \"{{topic}}\"\n"
        . "Categorie: \"{{category}}\"\n\n"
        . "Belangrijk:\n"
        . "- Geef geen <!DOCTYPE html>, <head>, <body>, <header>, <footer> of <meta> tags.\n"
        . "- Gebruik geen <h1>. Begin met <h2> en daarna <h3> waar logisch.\n"
        . "- Start altijd met een exact parseerbaar SEO/SERP blok in een HTML comment:\n"
        . "<!--\n"
        . "SEO_TITLE: ...\n"
        . "META_DESCRIPTION: ... (max 155 tekens)\n"
        . "SLUG: ...\n"
        . "PRIMARY_KEYWORD: ...\n"
        . "LONGTAIL_KEYWORDS: ... | ... | ...\n"
        . "INTENT: ...\n"
        . "NOT_ABOUT: ...\n"
        . "INTERNAL_LINKS:\n"
        . "- ...\n"
        . "- ...\n"
        . "- ...\n"
        . "-->\n"
        . "Daarna volgt direct de HTML-content.\n\n"
        . "Structuur:\n"
        . "- Korte inleiding.\n"
        . "- Meerdere secties met <h2> en <h3>.\n"
        . "- Gebruik bullets waar passend.\n"
        . "- Voeg 1 tabel toe met kolommen: Symptoom, Oorzaak, Actie.\n"
        . "- Sluit af met een korte, praktische conclusie.\n\n"
        . "Externe bronnen:\n"
        . "- Maximaal 1 externe link, alleen als echt relevant.\n"
        . "- Link in HTML met rel=\"noopener noreferrer\" en target=\"_blank\".\n"
        . "{{preferred_note}}\n\n"
        . "Geen Markdown en geen extra uitleg. Alleen het SEO-blok + HTML.";
}

function publion_get_post_prompt_template() {
    $template = (string) get_option( 'publion_post_prompt', '' );
    $template = trim( $template );
    if ( '' === $template ) {
        $template = publion_get_default_post_prompt_template();
    }
    return $template;
}

function publion_build_post_prompt( $topic, $category_name ) {
    $template = publion_get_post_prompt_template();
    $preferred_domain = publion_get_preferred_external_domain();
    $preferred_urls = publion_get_preferred_external_urls();

    $preferred_note = '';
    if ( ! empty( $preferred_domain ) ) {
        $preferred_note = "Als je (max 1) externe bron toevoegt, geef dan voorkeur aan \"" . $preferred_domain . "\".";
        if ( ! empty( $preferred_urls ) ) {
            $preferred_note .= " Gebruik bij voorkeur deze URL's:\n- " . implode( "\n- ", $preferred_urls );
        }
    }

    $replacements = [
        '{{topic}}' => $topic,
        '{{category}}' => $category_name,
        '{{site_name}}' => get_bloginfo( 'name' ),
        '{{site_url}}' => home_url(),
        '{{preferred_note}}' => $preferred_note,
    ];

    return strtr( $template, $replacements );
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

function publion_parse_ai_output( $raw_output ) {
    $raw_output = trim( (string) $raw_output );
    $defaults = [
        'seo_title' => '',
        'meta_description' => '',
        'slug' => '',
        'primary_keyword' => '',
        'longtails' => [],
        'intent' => '',
        'not_about' => '',
        'internal_links' => [],
    ];

    $seo = $defaults;
    $block = '';

    if ( preg_match( '/<!--\\s*(.*?)\\s*-->/s', $raw_output, $match ) ) {
        $block_text = $match[1];
        if ( stripos( $block_text, 'SEO_TITLE:' ) !== false ) {
            $block = $match[0];
            $lines = preg_split( '/\\r\\n|\\n|\\r/', trim( $block_text ) );
            $in_links = false;
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( '' === $line ) {
                    continue;
                }
                if ( 0 === stripos( $line, 'INTERNAL_LINKS:' ) ) {
                    $in_links = true;
                    continue;
                }
                if ( $in_links && preg_match( '/^-\\s*(.+)$/', $line, $m ) ) {
                    $seo['internal_links'][] = trim( $m[1] );
                    continue;
                }
                if ( preg_match( '/^([A-Z_]+)\\s*:\\s*(.*)$/', $line, $m ) ) {
                    $key = strtoupper( trim( $m[1] ) );
                    $value = trim( $m[2] );
                    switch ( $key ) {
                        case 'SEO_TITLE':
                            $seo['seo_title'] = $value;
                            break;
                        case 'META_DESCRIPTION':
                            $seo['meta_description'] = $value;
                            break;
                        case 'SLUG':
                            $seo['slug'] = sanitize_title( $value );
                            break;
                        case 'PRIMARY_KEYWORD':
                            $seo['primary_keyword'] = $value;
                            break;
                        case 'LONGTAIL_KEYWORDS':
                            $parts = preg_split( '/\\s*\\|\\s*/', $value );
                            $parts = array_filter( array_map( 'trim', $parts ) );
                            $seo['longtails'] = array_values( $parts );
                            break;
                        case 'INTENT':
                            $seo['intent'] = $value;
                            break;
                        case 'NOT_ABOUT':
                            $seo['not_about'] = $value;
                            break;
                    }
                }
            }
        }
    }

    $html_body = $raw_output;
    if ( $block ) {
        $html_body = trim( str_replace( $block, '', $raw_output ) );
    }

    return [
        'seo' => $seo,
        'html_body' => $html_body,
        'raw' => $raw_output,
        'has_seo_block' => ( '' !== $block ),
    ];
}

function publion_generate_meta_description( $html, $primary_keyword = '', $topic = '' ) {
    $text = wp_strip_all_tags( (string) $html );
    $text = preg_replace( '/\s+/', ' ', $text );
    $text = trim( $text );
    if ( '' === $text ) {
        $text = trim( (string) $topic );
    }

    $desc = $text;
    if ( mb_strlen( $desc ) > 155 ) {
        $desc = mb_substr( $desc, 0, 155 );
    }
    $desc = trim( rtrim( $desc, ' .,-' ) );

    $keyword = trim( (string) $primary_keyword );
    if ( $keyword !== '' && stripos( $desc, $keyword ) === false ) {
        $suffix = ' ' . $keyword;
        if ( mb_strlen( $desc ) + mb_strlen( $suffix ) > 155 ) {
            $desc = mb_substr( $desc, 0, 155 - mb_strlen( $suffix ) );
            $desc = trim( rtrim( $desc, ' .,-' ) );
        }
        $desc .= $suffix;
    }

    return $desc;
}

function publion_generate_chatgpt_html($topic, $category_name) {
    $api_key = get_option('publion_api_key', false);
    if (!$api_key) {
        $api_key = maybe_unserialize(get_option('publion_api_key'));
    }
    if ( empty( $api_key ) ) {
        return new WP_Error( 'publion_missing_api_key', 'OpenAI API-sleutel ontbreekt.' );
    }

    $model = publion_get_openai_model();
    $base_prompt = publion_build_post_prompt( $topic, $category_name );

    $messages = [
        ['role' => 'system', 'content' => 'Je bent een behulpzame AI-blogschrijver. Geef alleen het SEO-blok en daarna HTML terug.'],
        ['role' => 'user', 'content' => $base_prompt]
    ];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.7,
            'max_tokens'  => 2400
        ]),
        'timeout' => 60
    ]);
    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $raw_output = $body['choices'][0]['message']['content'] ?? '';

    if ( ! $raw_output ) {
        return new WP_Error( 'publion_empty_output', 'AI output is leeg.' );
    }

    $parsed = publion_parse_ai_output( $raw_output );
    $html_output = $parsed['html_body'];

    // Clean & validate
    $html_output = publion_clean_html_output($html_output);
    $html_output = publion_validate_links_in_html($html_output);
    $html_output = publion_enhance_external_links($html_output);
	$html_output = preg_replace('/<h1>(.*?)<\/h1>/i', '<h2>$1</h2>', $html_output, 1);
	$html_output = str_ireplace(['<header>', '</header>'], '', $html_output);

    // Rename Conclusion heading
    $html_output = preg_replace_callback(
        '/<h([2-4])[^>]*>(\s*)(Conclusion|Conclusie)(\s*)<\/h\1>/i',
        function($matches) {
            return '<h' . $matches[1] . '>Belangrijkste punten</h' . $matches[1] . '>';
        },
        $html_output
    );
    
    $html_output = preg_replace('/<h([1-2])>(.*?)<\/h\1>/i', '<h$1 class="publion-title">$2</h$1>', $html_output, 1);

    return [
        'html' => $html_output,
        'seo' => $parsed['seo'],
        'raw' => $raw_output,
        'has_seo_block' => $parsed['has_seo_block'],
    ];
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

function publion_get_pillar_links_map() {
    $settings = get_option( 'publion_post_settings', [] );
    $map = $settings['pillar_links'] ?? [];
    return is_array( $map ) ? $map : [];
}

function publion_resolve_pillar_url( $value ) {
    $value = trim( (string) $value );
    if ( '' === $value ) {
        return '';
    }
    if ( ctype_digit( $value ) ) {
        $url = get_permalink( (int) $value );
        return $url ? $url : '';
    }
    return esc_url_raw( $value );
}

function publion_find_post_by_title_like( $needle, $category_id, $exclude_ids = [] ) {
    $needle = trim( (string) $needle );
    if ( '' === $needle ) {
        return 0;
    }
    $q = new WP_Query( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'cat'            => (int) $category_id,
        's'              => $needle,
        'fields'         => 'ids',
        'post__not_in'   => array_map( 'intval', (array) $exclude_ids ),
        'no_found_rows'  => true,
    ] );

    $id = 0;
    if ( $q->have_posts() ) {
        $id = (int) $q->posts[0];
    }
    wp_reset_postdata();
    return $id;
}

function publion_get_recent_posts_in_category( $category_id, $limit = 2, $exclude_ids = [] ) {
    $q = new WP_Query( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => (int) $limit,
        'cat'            => (int) $category_id,
        'fields'         => 'ids',
        'post__not_in'   => array_map( 'intval', (array) $exclude_ids ),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ] );

    $ids = $q->have_posts() ? array_map( 'intval', $q->posts ) : [];
    wp_reset_postdata();
    return $ids;
}

function publion_append_further_reading_section( $html, $category_id, $internal_suggestions = [] ) {
    $category = get_term( (int) $category_id, 'category' );
    $category_name = ( $category && ! is_wp_error( $category ) ) ? (string) $category->name : '';

    $links = [];
    $exclude_ids = [];

    $pillar_map = publion_get_pillar_links_map();
    $pillar_value = $pillar_map[ $category_id ] ?? '';
    $pillar_url = publion_resolve_pillar_url( $pillar_value );
    if ( $pillar_url && ! publion_is_external_url( $pillar_url ) ) {
        $pillar_label = $category_name ? 'Pillar pagina: ' . $category_name : 'Pillar pagina';
        if ( ctype_digit( (string) $pillar_value ) ) {
            $maybe_title = get_the_title( (int) $pillar_value );
            if ( $maybe_title ) {
                $pillar_label = $maybe_title;
                $exclude_ids[] = (int) $pillar_value;
            }
        }
        $links[] = [
            'url' => $pillar_url,
            'label' => $pillar_label,
        ];
    }

    $internal_suggestions = array_values( array_filter( array_map( 'trim', (array) $internal_suggestions ) ) );
    foreach ( $internal_suggestions as $suggestion ) {
        if ( count( $links ) >= 3 ) {
            break;
        }
        $id = publion_find_post_by_title_like( $suggestion, $category_id, $exclude_ids );
        if ( $id ) {
            $exclude_ids[] = $id;
            $links[] = [
                'url' => get_permalink( $id ),
                'label' => get_the_title( $id ),
            ];
        }
    }

    if ( count( $links ) < 3 ) {
        $need = 3 - count( $links );
        $recent = publion_get_recent_posts_in_category( $category_id, $need, $exclude_ids );
        foreach ( $recent as $id ) {
            if ( count( $links ) >= 3 ) {
                break;
            }
            $links[] = [
                'url' => get_permalink( $id ),
                'label' => get_the_title( $id ),
            ];
        }
    }

    if ( count( $links ) < 2 ) {
        return $html;
    }

    $section = '<h2>Verder lezen</h2><ul>';
    foreach ( $links as $link ) {
        $section .= '<li><a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a></li>';
    }
    $section .= '</ul>';

    return rtrim( $html ) . "\n" . $section;
}

function publion_append_cta_footer( $html, $topic, $settings = [] ) {
    $settings = is_array( $settings ) ? $settings : [];
    if ( ( $settings['cta_enabled'] ?? 'no' ) !== 'yes' ) {
        return $html;
    }
    $cta_text = trim( (string) ( $settings['cta_text'] ?? '' ) );
    $cta_link = trim( (string) ( $settings['cta_link'] ?? '' ) );
    if ( '' === $cta_text || '' === $cta_link ) {
        return $html;
    }

    $html .= "<div style='clear:both; padding-top:20px; margin-top:30px; border-top:1px solid #ccc;'>
        <p>Hulp nodig bij <strong>" . esc_html( $topic ) . "</strong>?<br><strong><em><a class=\"ai-blog-cta\" href='" . esc_url( $cta_link ) . "'>" . esc_html( $cta_text ) . "</a></em></strong></p>
    </div>";

    return $html;
}

function publion_append_last_updated_footer( $html, $settings = [] ) {
    $settings = is_array( $settings ) ? $settings : [];
    if ( ( $settings['last_updated_enabled'] ?? 'no' ) !== 'yes' ) {
        return $html;
    }

    $ts = current_time( 'timestamp' );
    $date = wp_date( get_option( 'date_format' ), $ts );
    $html .= '<p class="publion-last-updated">Laatst bijgewerkt: ' . esc_html( $date ) . '</p>';

    return $html;
}

function publion_similarity_stopwords() {
    return [
        'de','het','een','en','of','voor','met','op','in','van','naar','bij','over','als','dat','die','deze','dit',
        'zijn','is','was','waren','worden','je','jij','we','wij','jullie','u','uw','maar','ook','meer','meest','minder',
        'veel','weinig','door','tot','uit','om','aan','via',
        'the','a','an','and','or','of','in','on','for','with','to','from','by','at','about','this','that','these','those',
        'is','are','was','were','be','being','been','how','what','why','when','where'
    ];
}

function publion_light_stem_token( $token ) {
    $token = preg_replace( '/(heden|heid|lijke|lijk|ingen|ing|eren|en|er|es|s|tjes|tje)$/u', '', $token );
    return $token;
}

function publion_normalize_title_for_similarity( $title ) {
    $title = wp_strip_all_tags( (string) $title );
    $title = wp_specialchars_decode( $title, ENT_QUOTES );
    $title = mb_strtolower( $title );
    $title = preg_replace( '/[^\\p{L}\\p{N}\\s]+/u', ' ', $title );
    $tokens = preg_split( '/\\s+/', $title );
    $stopwords = publion_similarity_stopwords();
    $out = [];
    foreach ( $tokens as $token ) {
        $token = trim( $token );
        if ( '' === $token ) {
            continue;
        }
        if ( mb_strlen( $token ) <= 2 ) {
            continue;
        }
        if ( in_array( $token, $stopwords, true ) ) {
            continue;
        }
        $token = publion_light_stem_token( $token );
        if ( '' !== $token ) {
            $out[] = $token;
        }
    }
    return trim( implode( ' ', $out ) );
}

function publion_similarity_ratio( $a, $b ) {
    $a = trim( (string) $a );
    $b = trim( (string) $b );
    if ( '' === $a || '' === $b ) {
        return 0;
    }
    similar_text( $a, $b, $pct );
    return $pct / 100;
}

function publion_get_titles_for_similarity( $category_id ) {
    $titles = [];

    $q = new WP_Query( [
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => 50,
        'cat'            => (int) $category_id,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] );

    if ( $q->have_posts() ) {
        foreach ( $q->posts as $pid ) {
            $titles[] = get_the_title( $pid );
        }
    }
    wp_reset_postdata();

    global $wpdb;
    if ( empty( $wpdb->publion_queue ) ) {
        $wpdb->publion_queue = $wpdb->prefix . 'publion_queue';
    }
    $queue_titles = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->prepare(
            "SELECT topic FROM {$wpdb->publion_queue} WHERE category_id = %d",
            (int) $category_id
        )
    );
    if ( ! empty( $queue_titles ) ) {
        $titles = array_merge( $titles, $queue_titles );
    }

    return array_unique( array_filter( array_map( 'trim', $titles ) ) );
}

function publion_is_similar_topic_in_category( $candidate, $category_id, $threshold = 0.82 ) {
    $candidate_norm = publion_normalize_title_for_similarity( $candidate );
    if ( '' === $candidate_norm ) {
        return false;
    }

    $titles = publion_get_titles_for_similarity( $category_id );
    foreach ( $titles as $title ) {
        $norm = publion_normalize_title_for_similarity( $title );
        if ( '' === $norm ) {
            continue;
        }
        if ( publion_similarity_ratio( $candidate_norm, $norm ) >= $threshold ) {
            return true;
        }
    }
    return false;
}

function publion_extract_noun_keywords($text, $api_key, $model = '') {
    $prompt = "Extraheer 10 van de belangrijkste zelfstandige naamwoorden of zelfstandige naamwoordgroepen uit de volgende blogpostcontent. Geef ze terug als een platte JSON-array met strings. Gebruik geen werkwoorden of bijvoeglijke naamwoorden.

$text";

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode([
            'model' => $model ?: publion_get_openai_model(),
            'messages' => [
                ['role' => 'system', 'content' => 'Je bent een assistent die nuttige keywords extraheert.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 200
        ]),
        'timeout' => 30
    ]);

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

function publion_validate_links_in_html($html) {
    if (empty($html)) return $html;

    // Match all <a href="...">...</a> links
    preg_match_all('/<a\b[^>]*href=["\'](.*?)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $url = $match[1];
        $anchor_text = $match[2];

        // Skip invalid or non-http(s) links
        if (!preg_match('/^https?:\/\//i', $url)) continue;

        $response = wp_remote_head($url, ['timeout' => 5]);
        $code = wp_remote_retrieve_response_code($response);

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

    update_post_meta($id, '_wp_attachment_image_alt', ucwords(str_replace('-', ' ', $slug)));

    return [
        'attachment_id' => $id,
        'url' => wp_get_attachment_url($id)
    ];
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
    $link_html = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . $anchor . '</a>';

    if ( preg_match( '/<p[^>]*>.*?<\\/p>/is', $html, $m ) ) {
        $new_p = preg_replace( '/<\\/p>$/', ' ' . $link_html . '</p>', $m[0] );
        return preg_replace( '/<p[^>]*>.*?<\\/p>/is', $new_p, $html, 1 );
    }

    return $html . '<p>' . $link_html . '</p>';
}

function publion_get_openai_image_model() {
    return apply_filters( 'publion/openai_image_model', 'gpt-image-1.5' );
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
        $context = mb_substr( $block_text, 0, 180 );
        $prompt = 'Maak een realistische, hoogwaardige foto-achtige afbeelding die past bij dit tekstfragment: "' . $context . '". ';
        $prompt .= 'Onderwerp: "' . $topic . '". ';
        if ( $category_name !== '' ) {
            $prompt .= 'Categorie: "' . $category_name . '". ';
        }
        $prompt .= 'Contextueel en relevant, geen tekst, watermerk of logo. Maak de compositie duidelijk anders dan andere afbeeldingen in dit artikel.';
        $items[] = [
            'prompt'  => $prompt,
            'context' => $context,
        ];
    }

    // Featured image prompt (6e)
    $featured = publion_build_image_prompt( $topic, $category_name ) . ' Maak de compositie duidelijk anders dan de andere afbeeldingen.';
    $items[] = [
        'prompt'  => $featured,
        'context' => $topic,
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
                $rels = array_diff( $rels, [ 'dofollow' ] );
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

function publion_generate_image_base64s( $prompt, $api_key, $count = 1 ) {
    $prompt = trim( (string) $prompt );
    $api_key = trim( (string) $api_key );
    $count = max( 1, (int) $count );

    if ( '' === $prompt || '' === $api_key ) {
        return [];
    }

    $response = wp_remote_post(
        'https://api.openai.com/v1/images/generations',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode(
                [
                    'model'         => publion_get_openai_image_model(),
                    'prompt'        => $prompt,
                    'n'             => $count,
                    'size'          => '1024x1024',
                    'quality'       => 'medium',
                    'output_format' => 'jpeg',
                ]
            ),
            'timeout' => 120,
        ]
    );

    if ( is_wp_error( $response ) ) {
        update_option( 'publion_last_image_error', $response->get_error_message() );
        return [];
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( 200 !== $code ) {
        $message = $body['error']['message'] ?? 'Onbekende fout bij het genereren van afbeeldingen.';
        update_option( 'publion_last_image_error', $message );
        return [];
    }

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

    update_post_meta( $id, '_wp_attachment_image_alt', ucwords( str_replace( '-', ' ', $slug ) ) );

    return [
        'attachment_id' => $id,
        'url'           => wp_get_attachment_url( $id ),
    ];
}

function publion_generate_and_upload_images( $prompt, $count, $context, $api_key ) {
    $images = publion_generate_image_base64s( $prompt, $api_key, $count );
    if ( empty( $images ) ) {
        // Fallback: try single-image requests if bulk failed.
        $fallback = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $single = publion_generate_image_base64s( $prompt, $api_key, 1 );
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

function publion_insert_images_into_content($html, $image_urls) {
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
        $alt_text = 'Blogafbeelding';

        foreach ($all_offsets as $offset) {
            $diff = abs($offset[1] - $target);
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $closest = $offset[1];
                // Strip HTML and limit to 10 words
                $alt_text = wp_strip_all_tags($offset[0]);
                $alt_text = implode(' ', array_slice(explode(' ', $alt_text), 0, 10));
            }
        }

        $style = ($i % 2 === 0)
            ? 'float:left; width:45%; padding:20px 20px 20px 0;'
            : 'float:right; width:45%; padding:20px 0 20px 20px;';

        if ($i === 4) {
            $style = 'float:left; width:45%; padding:20px 20px 20px 0;';
        }

        $inserts[$closest ?? $target] = '<img src="' . esc_url($image_urls[$i]) . '" alt="' . esc_attr($alt_text) . '" style="' . $style . '" />';
    }

    // Sort descending so offsets don’t shift
    krsort($inserts);
    foreach ($inserts as $offset => $tag) {
        $html = substr_replace($html, $tag, $offset, 0);
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

