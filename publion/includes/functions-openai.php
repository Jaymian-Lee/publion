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

function publion_generate_chatgpt_html($topic, $category_name) {
    $api_key = get_option('publion_api_key', false);
    if (!$api_key) {
        $api_key = maybe_unserialize(get_option('publion_api_key'));
    }

    $model = publion_get_openai_model();
    $target_word_count = 1400;
    $max_iterations = 5;

    $html_output = '';
    $word_count = 0;
    $iteration = 0;

    $preferred_domain = publion_get_preferred_external_domain();
    $preferred_urls = publion_get_preferred_external_urls();
    $preferred_note = '';
    if ( ! empty( $preferred_domain ) ) {
        $preferred_note = "\n\nGebruik in de externe links minimaal 1 link naar \"" . $preferred_domain . "\". Deze heeft prioriteit.";
        if ( ! empty( $preferred_urls ) ) {
            $preferred_note .= " Gebruik bij voorkeur deze URL's:\n- " . implode( "\n- ", $preferred_urls );
        }
        $preferred_note .= "\nAls je andere externe links toevoegt, kies dan alleen relevante, niet-concurrerende, betrouwbare bronnen.";
    }

    $base_prompt = "Schrijf een lange, hoogwaardige, SEO-geoptimaliseerde blogpost in HTML-formaat over het onderwerp: \"$topic\" binnen de categorie \"$category_name\". Voeg geen paginamarkup toe zoals <!DOCTYPE html>, <head>, <body>, <header>, <footer> of <meta>. Maak de eerste kop in de post een <h2> en geen <h1>. Deze HTML wordt geplaatst in de content van een pagina die dit al bevat.

De post moet minimaal 1500 woorden bevatten (geen tekens) en een passende HTML-structuur gebruiken met <p>, <h2>, <h3>, etc.

Vat niets samen en sla geen onderdelen over. Ga diep in op subonderwerpen, geef voorbeelden en behandel alle aspecten. Voeg een inleiding, meerdere gedetailleerde secties en een conclusie toe.

Verwerk 4-5 kernzinnen als dofollow-links naar hoogwaardige, niet-concurrerende informatieve websites (gebruik <a href=\\\"...\\\" target=\\\"_blank\\\" rel=\\\"dofollow\\\">ankertekst</a>), maar vermeld niet dat het links zijn. Zorg dat deze links verspreid door de content voorkomen. Dit is erg belangrijk!
$preferred_note

Geef alleen de HTML-content terug. Geen uitleg, notities of markdown.";

    $messages = [
        ['role' => 'system', 'content' => 'Je bent een behulpzame AI-blogschrijver. Geef alleen HTML terug.'],
        ['role' => 'user', 'content' => $base_prompt]
    ];

    while ($word_count < $target_word_count && $iteration < $max_iterations) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => json_encode([
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => 0.7,
                'max_tokens'  => 2048
            ]),
            'timeout' => 60
        ]);

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $new_html = $body['choices'][0]['message']['content'] ?? '';

        if (!$new_html) break;

        $html_output .= $new_html;
        $word_count = str_word_count(wp_strip_all_tags($html_output));
        $iteration++;

        $messages[] = ['role' => 'assistant', 'content' => $new_html];
        $messages[] = ['role' => 'user', 'content' => 'Ga verder met de rest van het artikel in hetzelfde HTML-formaat, precies waar je was gebleven. Herhaal geen content.'];
    }

    // Clean & validate
    $html_output = publion_clean_html_output($html_output);
    $html_output = publion_validate_links_in_html($html_output);
    $html_output = publion_enhance_external_links($html_output);
    $html_output = publion_ensure_preferred_domain_link( $html_output, $preferred_domain, $topic );
	$html_output = preg_replace('/<h1>(.*?)<\/h1>/i', '<h2>$1</h2>', $html_output, 1);
	$html_output = str_ireplace(['<header>', '</header>'], '', $html_output);
	$keywords = publion_extract_noun_keywords($html_output, $api_key, $model);
	$html_output = publion_auto_internal_links($html_output, $keywords);

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

    return $html_output;
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

        // Keep preferred domain links even if HEAD fails.
        $preferred_domain = publion_get_preferred_external_domain();
        $preferred_host = publion_normalize_domain( $preferred_domain );
        $link_host = wp_parse_url( $url, PHP_URL_HOST );
        if ( $preferred_host && $link_host && strtolower( $link_host ) === $preferred_host ) {
            continue;
        }

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
    $link_html = '<a href="' . esc_url( $url ) . '" target="_blank" rel="dofollow noopener noreferrer">' . $anchor . '</a>';

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

