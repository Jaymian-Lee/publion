<?php
function autopost_ai_generate_chatgpt_html($topic, $category_name) {
    $api_key = get_option('autopost_ai_api_key', false);
    if (!$api_key) {
        $api_key = maybe_unserialize(get_option('autopost_ai_api_key'));
    }

    $model = 'gpt-4o';
    $target_word_count = 1400;
    $max_iterations = 5;

    $html_output = '';
    $word_count = 0;
    $iteration = 0;

    $base_prompt = "Write a long-form, high-quality, SEO-optimized blog post in HTML format about the topic: \"$topic\" under the category \"$category_name\". Do not include any page markup like <!DOCTYPE html>, <head>, <body>, <header>, <footer> or any <meta>. Make the first heading in the post an <h2>, not an <h1>. This html will be place withn the content of a page that already has all of those things.

The post must be at least 1500 words long (not characters) and use appropriate HTML structure including <p>, <h2>, <h3>, etc.

Do not summarize or skip sections. Go deep into subtopics, provide examples, and cover all aspects. You must include an introduction, multiple detailed sections, and a conclusion.

Embed 4- 5 key phrases as dofollow links to high-authority, non-competing informational websites (use <a href=\\\"...\\\" target=\\\"_blank\\\" rel=\\\"dofollow\\\">anchor text</a>), but do not mention they are links. Make sure to always include these links throughout the content. It's highly important!'

Return only the HTML content. No explanations, notes, or markdown.";

    $messages = [
        ['role' => 'system', 'content' => 'You are a helpful AI blog post writer. Return only HTML.'],
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
        $messages[] = ['role' => 'user', 'content' => 'Continue writing the rest of the article in the same HTML format, picking up where you left off. Do not repeat content.'];
    }

    // Clean & validate
    $html_output = autopost_ai_clean_html_output($html_output);
    $html_output = autopost_ai_validate_links_in_html($html_output);
	$html_output = preg_replace('/<h1>(.*?)<\/h1>/i', '<h2>$1</h2>', $html_output, 1);
	$html_output = str_ireplace(['<header>', '</header>'], '', $html_output);
	$keywords = autopost_ai_extract_noun_keywords($html_output, $api_key);
	$html_output = autopost_ai_auto_internal_links($html_output, $keywords);

    // Append CTA
    $settings = get_option('autopost_ai_post_settings', []);
    if (($settings['cta_enabled'] ?? 'no') === 'yes' && !empty($settings['cta_text']) && !empty($settings['cta_link'])) {
        $html_output .= "<div style='clear:both; padding-top:20px; margin-top:30px; border-top:1px solid #ccc;'>
            <p>Need help with <strong>{$topic}</strong>?<br><strong><em><a class=\"ai-blog-cta\" href='" . esc_url($settings['cta_link']) . "'>{$settings['cta_text']}</a></em></strong></p>
        </div>";
    }

    // Rename Conclusion heading
    $html_output = preg_replace_callback(
        '/<h([2-4])[^>]*>(\s*)Conclusion(\s*)<\/h\1>/i',
        function($matches) {
            return '<h' . $matches[1] . '>Takeaways</h' . $matches[1] . '>';
        },
        $html_output
    );
    
    $html_output = preg_replace('/<h([1-2])>(.*?)<\/h\1>/i', '<h$1 class="autopost-title">$2</h$1>', $html_output, 1);

    return $html_output;
}

function autopost_ai_auto_internal_links($html, $keywords) {
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

function autopost_ai_extract_noun_keywords($text, $api_key) {
    $prompt = "Extract 10 of the most important individual nouns or noun phrases from the following blog post content. Return them as a plain JSON array of strings. Do not include verbs or adjectives.

$text";

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode([
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => 'You are an assistant that extracts useful keywords.'],
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

function autopost_ai_validate_links_in_html($html) {
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

function autopost_ai_clean_html_output($html) {
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

function autopost_ai_upload_image($url, $context = '') {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url);

    // Create a slug-like filename from the context
    $slug = sanitize_title($context);
    if (empty($slug)) $slug = 'autopost-image';

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

function autopost_ai_process_and_upload_images($remote_image_urls) {
    $processed = [];

    foreach ($remote_image_urls as $url) {
        $upload = autopost_ai_upload_image($url);
        if ($upload && isset($upload['url'])) {
            $processed[] = $upload;
        }
    }

    return $processed;
}

function autopost_ai_insert_images_into_content($html, $image_urls) {
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
        $alt_text = 'Blog image';

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

function autopost_ai_get_pixabay_images($topic, $count) {
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

function autopost_ai_extract_keywords($topic, $max_words = 3) {
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

