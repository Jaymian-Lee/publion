<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Publion_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'handle_form_submissions' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_styles' ] );
        add_filter( 'post_class', [ $this, 'add_generated_post_class' ], 10, 3 );
        add_filter( 'post_thumbnail_html', [ $this, 'add_generated_thumbnail_class' ], 10, 5 );
        add_action( 'wp_head', [ $this, 'output_structured_data' ], 20 );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php', // parent slug = Posts
            __( 'Publion', 'publion' ),
            __( 'Publion', 'publion' ),
            'manage_options',
            'publion',
            [ $this, 'render_admin_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        $is_publion_page = ( $hook === 'posts_page_publion' );
        if ( ! $is_publion_page ) {
            $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
            $is_publion_page = ( $page === 'publion' );
        }
        if ( ! $is_publion_page ) {
            return;
        }

        $css_version = file_exists( PUBLION_PATH . 'assets/admin.css' ) ? filemtime( PUBLION_PATH . 'assets/admin.css' ) : PUBLION_VERSION;
        $js_version  = file_exists( PUBLION_PATH . 'assets/admin.js' ) ? filemtime( PUBLION_PATH . 'assets/admin.js' ) : PUBLION_VERSION;

        wp_enqueue_style( 'publion-style', PUBLION_URL . 'assets/admin.css', [], $css_version );
        wp_enqueue_script( 'publion-script', PUBLION_URL . 'assets/admin.js', [ 'jquery' ], $js_version, true );

        // Localize a reusable nonce for all AJAX requests that call check_ajax_referer( 'publion_nonce', 'nonce' ).
        wp_localize_script(
            'publion-script',
            'Publion',
            [
                'ajax_url'    => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'publion_nonce' ),
                'has_api_key' => ! empty( get_option( 'publion_api_key' ) ),
            ]
        );
    }

    public function enqueue_frontend_styles() {
        $settings = get_option( 'publion_post_settings', [] );

        $image_radius = isset( $settings['image_border_radius'] ) ? absint( $settings['image_border_radius'] ) : 8;
        $image_radius = min( 48, $image_radius );
        $style_mode   = ( $settings['article_style_mode'] ?? 'inherit' ) === 'refined' ? 'refined' : 'inherit';
        $accent_color = sanitize_hex_color( $settings['content_accent_color'] ?? '' );
        $accent_color = $accent_color ? $accent_color : '#4f46e5';
        $content_width = isset( $settings['content_max_width'] ) ? absint( $settings['content_max_width'] ) : 760;
        $content_width = max( 560, min( 1200, $content_width ) );
        $custom_css = isset( $settings['custom_article_css'] ) ? (string) $settings['custom_article_css'] : '';
        wp_register_style( 'publion-content', false );
        wp_enqueue_style( 'publion-content' );
        $frontend_css = '.publion-generated-post img, .publion-generated-image { border-radius: ' . $image_radius . 'px; }';
        if ( 'refined' === $style_mode ) {
            $frontend_css .= '.publion-generated-post { --publion-accent: ' . $accent_color . '; --publion-content-width: ' . $content_width . 'px; }';
            $frontend_css .= '.publion-generated-post .entry-content, .publion-generated-post .wp-block-post-content { max-width: var(--publion-content-width); }';
            $frontend_css .= '.publion-generated-post .entry-content :is(h2, h3, h4), .publion-generated-post .wp-block-post-content :is(h2, h3, h4) { scroll-margin-top: 2rem; }';
            $frontend_css .= '.publion-generated-post .entry-content a, .publion-generated-post .wp-block-post-content a { color: var(--publion-accent); text-decoration-thickness: .08em; text-underline-offset: .14em; }';
            $frontend_css .= '.publion-generated-post .entry-content img, .publion-generated-post .wp-block-post-content img { max-width: 100%; height: auto; }';
        }
        if ( $custom_css ) {
            $frontend_css .= "\n" . $custom_css;
        }
        wp_add_inline_style( 'publion-content', $frontend_css );

        if ( ! empty( $settings['hide_title'] ) && $settings['hide_title'] === 'yes' ) {
            wp_register_style( 'publion-hide-title', false ); // No file, just inline
            wp_enqueue_style( 'publion-hide-title' );
            wp_add_inline_style( 'publion-hide-title', '.publion-title { display: none !important; }' );
        }
    }

    public static function sanitize_custom_article_css( $css ) {
        $css = wp_strip_all_tags( (string) $css );
        $css = preg_replace( '/@(import|charset)[^;]*;/i', '', $css );
        $css = str_ireplace( array( '</style>', '<style>' ), '', $css );
        return substr( trim( $css ), 0, 6000 );
    }

    public function add_generated_post_class( $classes, $class, $post_id ) {
        if ( get_post_meta( $post_id, '_publion_queue_id', true ) ) {
            $classes[] = 'publion-generated-post';
        }
        return $classes;
    }

    public function add_generated_thumbnail_class( $html, $post_id, $thumbnail_id, $size, $attr ) {
        if ( $html && get_post_meta( $post_id, '_publion_queue_id', true ) ) {
            if ( preg_match( '/<img[^>]*\bclass=["\'][^"\']*["\']/i', $html ) ) {
                $html = preg_replace( '/(<img[^>]*\bclass=["\'][^"\']*)/i', '$1 publion-generated-image', $html, 1 );
            } else {
                $html = preg_replace( '/<img\s/i', '<img class="publion-generated-image" ', $html, 1 );
            }
        }
        return $html;
    }

    public function output_structured_data() {
        if ( ! is_singular( 'post' ) ) {
            return;
        }
        $settings = get_option( 'publion_post_settings', [] );
        if ( ( $settings['structured_data'] ?? 'yes' ) !== 'yes' ) {
            return;
        }
        $post_id = get_queried_object_id();
        if ( ! $post_id || ! get_post_meta( $post_id, '_publion_queue_id', true ) ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }
        $schema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'BlogPosting',
            'headline'         => get_the_title( $post_id ),
            'description'      => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '' ),
            'datePublished'    => get_the_date( DATE_W3C, $post_id ),
            'dateModified'     => get_the_modified_date( DATE_W3C, $post_id ),
            'mainEntityOfPage' => get_permalink( $post_id ),
            'author'           => [ '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', $post->post_author ) ],
            'publisher'        => [ '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) ],
        ];
        if ( has_post_thumbnail( $post_id ) ) {
            $schema['image'] = get_the_post_thumbnail_url( $post_id, 'full' );
        }
        $faq_pairs = get_post_meta( $post_id, '_publion_faq_pairs', true );
        if ( is_array( $faq_pairs ) && $faq_pairs ) {
            $faq_schema = [
                '@type'       => 'FAQPage',
                'mainEntity'  => array_map(
                static function ( $faq ) {
                    return [
                        '@type'          => 'Question',
                        'name'           => $faq['question'],
                        'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $faq['answer'] ],
                    ];
                },
                $faq_pairs
                ),
            ];
            $schema = [ '@context' => 'https://schema.org', '@graph' => [ $schema, $faq_schema ] ];
        }
        echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
    }

    public function handle_form_submissions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['publion_save_openai_key'] ) && check_admin_referer( 'publion_save_openai_key' ) ) {
            $key = sanitize_text_field( wp_unslash( $_POST['publion_api_key'] ?? '' ) );
            update_option( 'publion_api_key', $key );
            add_settings_error( 'publion_messages', 'key_saved', __( 'API-sleutel opgeslagen.', 'publion' ), 'updated' );
        }

        if ( isset( $_POST['publion_save_prompt'] ) && check_admin_referer( 'publion_save_prompt' ) ) {
            $prompt = wp_kses_post( wp_unslash( $_POST['publion_prompt'] ?? '' ) );
            update_option( 'publion_prompt', $prompt );
            add_settings_error( 'publion_messages', 'prompt_saved', __( 'Voorprompt opgeslagen.', 'publion' ), 'updated' );
        }

        if ( isset( $_POST['publion_reset_prompt'] ) && check_admin_referer( 'publion_reset_prompt' ) ) {
            delete_option( 'publion_prompt' );
            add_settings_error( 'publion_messages', 'prompt_reset', __( 'Voorprompt teruggezet naar standaard.', 'publion' ), 'updated' );
        }
    }

    public function render_admin_page() {
        if ( ! get_option( 'publion_last_post_created_at' ) ) {
            update_option( 'publion_last_post_created_at', current_time( 'mysql' ) );
        }

        $openai_api_key   = get_option( 'publion_api_key', '' );
        $openai_model     = publion_get_openai_model();
        $model_options    = publion_get_allowed_openai_models();
        $warning_display  = empty( $openai_api_key ) ? 'display:inline-block;' : 'display:none;';
        $default_prompt   = "Je bent een expert in het schrijven van blogs en maakt hoogwaardige, SEO-geoptimaliseerde content voor [JOUW BEDRIJFSNAAM (INDIEN VAN TOEPASSING) EN WEBSITE-URL], [WAT JOUW BEDRIJF/WEBSITE BIEDT]. Het doel is [JOUW BEDRIJFS/WEBSITE-DOELEN]. Stem de toon af op het merk: [DE TOON DIE JE WILT UITSTRALEN - voorbeeld: professioneel maar benaderbaar, deskundig maar eenvoudig uit te leggen]. Elk onderwerp moet de missie van [JOUW BEDRIJFS/WEBSITE-NAAM] weerspiegelen om [BEDRIJVEN of MENSEN] te helpen met [HOE JE BEDRIJVEN of MENSEN HELPT].\n\n(Vervang deze prompt door je eigen tekst om je doelen beter te weerspiegelen.)";
        $openai_prompt    = get_option( 'publion_prompt', $default_prompt );
        $dashboard_settings = get_option( 'publion_post_settings', array() );
        $last_image_error   = get_option( 'publion_last_image_error', '' );
        global $wpdb;
        publion_register_table_on_wpdb();
        $queue_table = $wpdb->publion_queue;
        $pending_count = (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$queue_table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $created_this_month = (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$queue_table} WHERE post_created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $scheduled_count = (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$queue_table} WHERE status = 'pending' AND scheduled_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $post_counts = wp_count_posts( 'post' );
        $draft_count = isset( $post_counts->draft ) ? (int) $post_counts->draft : 0;

        // --- Handle manual topic form submission (with nonce verification) ---
        if (
            isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] &&
            isset( $_POST['publion_manual_add_topic'] ) &&
            check_admin_referer( 'publion_manual_add_topic' )
        ) {
            $manual_topic    = sanitize_text_field( wp_unslash( $_POST['manual_topic'] ?? '' ) );
            $manual_category = isset( $_POST['manual_category'] ) ? absint( wp_unslash( $_POST['manual_category'] ) ) : 0;

            if ( ! empty( $manual_topic ) && $manual_category ) {
                $category_obj = get_category( $manual_category );

                if ( $category_obj && ! is_wp_error( $category_obj ) ) {
                    global $wpdb;
                    $table = $wpdb->prefix . 'publion_queue';

                    $wpdb->insert( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $table,
                        [
                            'topic'          => $manual_topic,
                            'category_id'    => $manual_category,
                            'category_label' => $category_obj->name,
                            'status'         => 'pending',
                            'created_at'     => current_time( 'mysql' ),
                        ]
                    );
					publion_schedule_pending_entries( false );

                    // Redirect to preserve tab and show success.
                    wp_redirect( admin_url( 'admin.php?page=publion&publion_active_tab=publion-queue&topic_added=1' ) );
                    exit;
                } else {
                    wp_redirect( admin_url( 'admin.php?page=publion&publion_active_tab=publion-queue&topic_error=invalid_category' ) );
                    exit;
                }
            } else {
                wp_redirect( admin_url( 'admin.php?page=publion&publion_active_tab=publion-queue&topic_error=missing_fields' ) );
                exit;
            }
        }
        ?>

        <div class="wrap publion-app-shell">
            <header class="publion-app-header">
                <div>
                    <p class="publion-eyebrow"><?php esc_html_e( 'CONTENT ENGINE', 'publion' ); ?></p>
                    <h1><?php esc_html_e( 'Publion', 'publion' ); ?></h1>
                    <p><?php esc_html_e( 'Van zoekintentie naar heldere, vindbare artikelen.', 'publion' ); ?></p>
                </div>
                <div class="publion-header-status">
                    <span class="publion-status-dot"></span>
                    <?php echo esc_html( $openai_api_key ? __( 'AI verbonden', 'publion' ) : __( 'API-sleutel nodig', 'publion' ) ); ?>
                </div>
            </header>

            <?php settings_errors( 'publion_messages' ); ?>

            <h2 class="nav-tab-wrapper">
                <a href="javascript:void(0)" class="nav-tab nav-tab-active" data-tab="publion-dashboard"><?php esc_html_e( 'Overzicht', 'publion' ); ?></a>
                <a href="javascript:void(0)" class="nav-tab" data-tab="publion-generate"><?php esc_html_e( 'Content plannen', 'publion' ); ?></a>
                <a href="javascript:void(0)" class="nav-tab" data-tab="publion-queue"><?php esc_html_e( 'Postcreatie', 'publion' ); ?></a>
                <a href="javascript:void(0)" class="nav-tab" data-tab="publion-post-settings"><?php esc_html_e( 'Instellingen voor postcreatie', 'publion' ); ?></a>
                <a href="javascript:void(0)" class="nav-tab" data-tab="publion-settings"><?php esc_html_e( 'OpenAI/ChatGPT instellingen', 'publion' ); ?></a>
                <a href="javascript:void(0)" class="nav-tab" data-tab="publion-help"><?php esc_html_e( 'Handleiding & diagnose', 'publion' ); ?></a>
            </h2>

            <div id="publion-global-notice" class="publion-global-notice" aria-live="polite" aria-atomic="true"></div>

            <!-- Dashboard -->
            <div id="publion-dashboard" class="publion-tab-content" style="display:block;">
                <section class="publion-dashboard-top">
                    <div>
                        <p class="publion-eyebrow"><?php esc_html_e( 'CONTENT OPERATIONS', 'publion' ); ?></p>
                        <h2><?php esc_html_e( 'Je contentwerk, helder geordend.', 'publion' ); ?></h2>
                        <p><?php esc_html_e( 'Begin bij de volgende actie, maak inhoudelijke concepten en meet het effect in je eigen analyticsomgeving.', 'publion' ); ?></p>
                    </div>
                    <div class="publion-dashboard-actions">
                        <button type="button" class="button button-primary" data-publion-tab="publion-generate"><?php esc_html_e( 'Plan artikelen', 'publion' ); ?></button>
                        <button type="button" class="button" data-publion-tab="publion-queue"><?php esc_html_e( 'Open wachtrij', 'publion' ); ?></button>
                        <button type="button" class="button" id="publion-open-quality-modal" aria-haspopup="dialog"><?php esc_html_e( 'SEO / SEA / GEO-check', 'publion' ); ?></button>
                    </div>
                </section>

                <?php if ( empty( $openai_api_key ) ) : ?>
                    <div class="publion-callout publion-callout-warning">
                        <div><strong><?php esc_html_e( 'AI is nog niet verbonden.', 'publion' ); ?></strong><span><?php esc_html_e( 'Voeg eerst een OpenAI API-sleutel toe; zonder sleutel kan Publion geen onderwerpen, artikelen of afbeeldingen maken.', 'publion' ); ?></span></div>
                        <button type="button" class="button button-primary" data-publion-tab="publion-settings"><?php esc_html_e( 'API-sleutel instellen', 'publion' ); ?></button>
                    </div>
                <?php elseif ( ! empty( $last_image_error ) ) : ?>
                    <div class="publion-callout publion-callout-error">
                        <div><strong><?php esc_html_e( 'De laatste afbeeldingsopdracht had een probleem.', 'publion' ); ?></strong><span><?php echo esc_html( $last_image_error ); ?></span></div>
                        <button type="button" class="button" data-publion-tab="publion-settings"><?php esc_html_e( 'Controleer AI-instellingen', 'publion' ); ?></button>
                    </div>
                <?php else : ?>
                    <div class="publion-callout publion-callout-success"><div><strong><?php esc_html_e( 'Alles is klaar voor de volgende contentcyclus.', 'publion' ); ?></strong><span><?php esc_html_e( 'Genereer een plan of review je concepten voordat je publiceert.', 'publion' ); ?></span></div></div>
                <?php endif; ?>

                <div class="publion-metric-grid">
                    <section><span><?php esc_html_e( 'In wachtrij', 'publion' ); ?></span><strong><?php echo esc_html( $pending_count ); ?></strong><small><?php echo esc_html( sprintf( _n( '%s ingepland', '%s ingepland', $scheduled_count, 'publion' ), $scheduled_count ) ); ?></small></section>
                    <section><span><?php esc_html_e( 'Concepten te reviewen', 'publion' ); ?></span><strong><?php echo esc_html( $draft_count ); ?></strong><small><?php esc_html_e( 'Alle WordPress-concepten', 'publion' ); ?></small></section>
                    <section><span><?php esc_html_e( 'Aangemaakt (30 dagen)', 'publion' ); ?></span><strong><?php echo esc_html( $created_this_month ); ?></strong><small><?php esc_html_e( 'Via Publion', 'publion' ); ?></small></section>
                    <section><span><?php esc_html_e( 'SEO-structuur', 'publion' ); ?></span><strong><?php echo esc_html( ( $dashboard_settings['structured_data'] ?? 'yes' ) === 'yes' ? __( 'Aan', 'publion' ) : __( 'Uit', 'publion' ) ); ?></strong><small><?php esc_html_e( 'BlogPosting en FAQ-data', 'publion' ); ?></small></section>
                </div>

                <div class="publion-dashboard-layout">
                    <section class="publion-dashboard-panel">
                        <div class="publion-panel-heading"><div><p class="publion-eyebrow"><?php esc_html_e( 'VOLGENDE STAPPEN', 'publion' ); ?></p><h3><?php esc_html_e( 'Werk in deze volgorde', 'publion' ); ?></h3></div></div>
                        <ol class="publion-workflow-list">
                            <li><span>1</span><div><strong><?php esc_html_e( 'Plan onderwerpen met zoekintentie', 'publion' ); ?></strong><p><?php esc_html_e( 'Kies een categorie en controleer de SEO-brief voordat je onderwerpen bewaart.', 'publion' ); ?></p></div><button type="button" class="button-link" data-publion-tab="publion-generate"><?php esc_html_e( 'Plannen', 'publion' ); ?></button></li>
                            <li><span>2</span><div><strong><?php esc_html_e( 'Maak en review concepten', 'publion' ); ?></strong><p><?php esc_html_e( 'Controleer feiten, bronnen, merktoon, afbeeldingen en interne links voor publicatie.', 'publion' ); ?></p></div><button type="button" class="button-link" data-publion-tab="publion-queue"><?php esc_html_e( 'Reviewen', 'publion' ); ?></button></li>
                            <li><span>3</span><div><strong><?php esc_html_e( 'Meet wat echt werkt', 'publion' ); ?></strong><p><?php esc_html_e( 'Gebruik Search Console voor vertoningen, klikken, CTR en gemiddelde positie; verbeter eerst pagina’s met veel vertoningen en lage CTR.', 'publion' ); ?></p></div><button type="button" class="button-link" data-publion-tab="publion-help"><?php esc_html_e( 'Lees uitleg', 'publion' ); ?></button></li>
                        </ol>
                    </section>
                    <aside class="publion-dashboard-panel publion-performance-panel">
                        <p class="publion-eyebrow"><?php esc_html_e( 'PRESTATIES', 'publion' ); ?></p>
                        <h3><?php esc_html_e( 'Analytics blijft de bron van waarheid', 'publion' ); ?></h3>
                        <p><?php esc_html_e( 'Publion genereert geen verzonnen verkeerscijfers. Koppel hieronder je eigen dashboards om resultaten per artikel te beoordelen.', 'publion' ); ?></p>
                        <div class="publion-performance-links">
                            <a class="button" href="<?php echo esc_url( $dashboard_settings['search_console_url'] ?? 'https://search.google.com/search-console' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Search Console', 'publion' ); ?></a>
                            <a class="button" href="<?php echo esc_url( $dashboard_settings['ga4_url'] ?? 'https://analytics.google.com/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Google Analytics', 'publion' ); ?></a>
                        </div>
                        <p class="publion-panel-note"><?php esc_html_e( 'Sla eigen rapportlinks op bij Instellingen voor postcreatie om direct naar de juiste property te gaan.', 'publion' ); ?></p>
                    </aside>
                </div>
            </div>

            <div id="publion-quality-modal" class="publion-modal" hidden>
                <div class="publion-modal-backdrop" data-publion-modal-close="true"></div>
                <section class="publion-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="publion-quality-modal-title" aria-describedby="publion-quality-modal-description" tabindex="-1">
                    <header class="publion-modal-header">
                        <div><p class="publion-eyebrow"><?php esc_html_e( 'PUBLICATIECHECK', 'publion' ); ?></p><h2 id="publion-quality-modal-title"><?php esc_html_e( 'SEO, SEA en GEO in één review', 'publion' ); ?></h2></div>
                        <button type="button" class="button-link publion-modal-close" data-publion-modal-close="true" aria-label="<?php esc_attr_e( 'Sluit kwaliteitscheck', 'publion' ); ?>">×</button>
                    </header>
                    <p id="publion-quality-modal-description" class="publion-modal-intro"><?php esc_html_e( 'Gebruik deze korte controle vóór publicatie of voordat je een artikel als landingspagina voor een campagne inzet.', 'publion' ); ?></p>
                    <div class="publion-quality-grid">
                        <section><h3><?php esc_html_e( 'SEO — organisch vinden', 'publion' ); ?></h3><ul><li><?php esc_html_e( 'Beantwoordt de titel één duidelijke zoekvraag?', 'publion' ); ?></li><li><?php esc_html_e( 'Zijn titel, meta description en eerste alinea inhoudelijk consistent?', 'publion' ); ?></li><li><?php esc_html_e( 'Zijn interne links, relevante bronnen en afbeeldingen gecontroleerd?', 'publion' ); ?></li></ul></section>
                        <section><h3><?php esc_html_e( 'SEA — campagneklaar', 'publion' ); ?></h3><ul><li><?php esc_html_e( 'Sluit de landingspagina exact aan op de advertentiebelofte?', 'publion' ); ?></li><li><?php esc_html_e( 'Is er één heldere, meetbare conversieactie?', 'publion' ); ?></li><li><?php esc_html_e( 'Kun je meerdere unieke headlines en descriptions testen zonder claims te verzinnen?', 'publion' ); ?></li></ul></section>
                        <section><h3><?php esc_html_e( 'GEO/AEO — antwoordklaar', 'publion' ); ?></h3><ul><li><?php esc_html_e( 'Staat het directe antwoord vroeg in het artikel?', 'publion' ); ?></li><li><?php esc_html_e( 'Zijn feiten specifiek, controleerbaar en niet overdreven?', 'publion' ); ?></li><li><?php esc_html_e( 'Helpen koppen, FAQ en structured data de inhoud te duiden?', 'publion' ); ?></li></ul></section>
                    </div>
                    <footer class="publion-modal-footer"><button type="button" class="button button-primary publion-modal-close" data-publion-modal-close="true"><?php esc_html_e( 'Begrepen', 'publion' ); ?></button><button type="button" class="button" data-publion-tab="publion-help"><?php esc_html_e( 'Open handleiding', 'publion' ); ?></button></footer>
                </section>
            </div>

            <!-- Tab: Generate Topics & Queue Posts -->
            <div id="publion-generate" class="publion-tab-content" style="display:none;">

                <div class="publion-section-intro">
                    <p class="publion-eyebrow"><?php esc_html_e( '01 — ONDERZOEK', 'publion' ); ?></p>
                    <h2><?php esc_html_e( 'Kies een categorie. Publion bouwt vervolgens een SEO-brief per artikel.', 'publion' ); ?></h2>
                    <p><?php esc_html_e( 'Elke suggestie bevat een dynamisch focus-keyword, zoekintentie, invalshoek en FAQ-vragen.', 'publion' ); ?></p>
                </div>
                <select id="publion-category">
                    <option value=""><?php esc_html_e( 'Selecteer een categorie', 'publion' ); ?></option>
                    <?php
                    $categories = get_categories( [ 'hide_empty' => false ] );
                    foreach ( $categories as $cat ) {
                        if ( strtolower( $cat->name ) === 'uncategorized' ) {
                            continue;
                        }

                        $label = $cat->name;
                        if ( $cat->parent ) {
                            $parent = get_category( $cat->parent );
                            $label  = $parent->name . ' → ' . $cat->name;
                        }

                        echo '<option value="' . esc_attr( $cat->term_id ) . '">' . esc_html( $label ) . '</option>';
                    }
                    ?>
                </select>
                <button id="publion-suggest" class="button button-primary" style="margin-left:8px;"><?php esc_html_e( 'Onderwerpen voorstellen', 'publion' ); ?></button>
                <button id="publion-refresh" class="button" style="margin-left:8px; display:none;"><?php esc_html_e( 'Voorstellen vernieuwen', 'publion' ); ?></button>

                <h2 id="publion-suggestions-heading" style="display:none;"><?php esc_html_e( 'AI-onderwerpvoorstellen', 'publion' ); ?></h2>
                <div id="publion-loading" style="display:none; margin: 10px 0; align-items:center; gap:10px;">
                    <em><?php esc_html_e( 'Onderwerpvoorstellen laden...', 'publion' ); ?></em>
                    <span class="spinner is-active" style="float:none;"></span>
                </div>
                <ul id="publion-suggestions"></ul>

                <div id="publion-selected-topics" style="display: none;">
                    <h2><?php esc_html_e( 'Geselecteerde onderwerpen voor de wachtrij', 'publion' ); ?></h2>
                    <table id="publion-ai-queue" class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Actie', 'publion' ); ?></th>
                                <th><?php esc_html_e( 'Categorie', 'publion' ); ?></th>
                                <th><?php esc_html_e( 'Onderwerp', 'publion' ); ?></th>
                                <th><?php esc_html_e( 'SEO-brief', 'publion' ); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>

                    <br>
                    <button id="publion-save-queue" class="button button-primary"><?php esc_html_e( 'Toevoegen aan wachtrij voor postcreatie', 'publion' ); ?></button>
                </div>
            </div>

            <!-- Tab: Post Creation Queue -->
            <div id="publion-queue" class="publion-tab-content" style="display:none;">
            <?php
            // Fetch categories except "Uncategorized"
            $categories = get_categories(
                [
                    'hide_empty' => false,
                    'exclude'    => [ get_option( 'default_category' ) ],
                ]
            );
            ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=publion' ) ); ?>" id="publion-manual-form" style="display: flex; align-items: center; gap: 10px; margin:20px 0 15px 0; flex-wrap: wrap; max-width: 100%;">
                <input type="hidden" name="publion_manual_add_topic" value="1" />
                <?php wp_nonce_field( 'publion_manual_add_topic' ); ?>

                <label for="manual_topic" style="font-weight: 600; white-space: nowrap;"><?php esc_html_e( 'Voeg handmatig een onderwerp toe:', 'publion' ); ?></label>

                <select name="manual_category" required>
                    <option value=""><?php esc_html_e( 'Selecteer een categorie', 'publion' ); ?></option>
                    <?php
                    $categories = get_categories( [ 'hide_empty' => false ] );
                    foreach ( $categories as $cat ) {
                        if ( strtolower( $cat->name ) === 'uncategorized' ) {
                            continue;
                        }

                        $label = $cat->name;
                        if ( $cat->parent ) {
                            $parent = get_category( $cat->parent );
                            $label  = $parent->name . ' → ' . $cat->name;
                        }

                        echo '<option value="' . esc_attr( $cat->term_id ) . '">' . esc_html( $label ) . '</option>';
                    }
                    ?>
                </select>

                <input type="text" name="manual_topic" id="manual_topic" placeholder="<?php esc_attr_e( 'Onderwerp invoeren...', 'publion' ); ?>" style="flex-grow: 1; min-width: 500px; font-size: 14px;" />

                <button type="submit" class="publion-tab-button button button-primary" data-tab="post_creation"><?php esc_html_e( 'Toevoegen', 'publion' ); ?></button>
            </form>

                <h2 class="publion-accordion-heading active">
                  <span class="publion-heading-label"><?php esc_html_e( 'Wachtrij voor postcreatie', 'publion' ); ?></span>
                  <span class="publion-accordion-arrow">▲</span>
                </h2>
                <div class="publion-accordion-body" style="display:block;">
                    <div class="publion-bulk-actions" style="display:flex; align-items:center; gap:10px; margin:10px 0;">
                        <label style="display:flex; align-items:center; gap:6px; font-weight:600;">
                            <input type="checkbox" id="publion-select-all">
                            <?php esc_html_e( 'Alles selecteren', 'publion' ); ?>
                        </label>
                        <select id="publion-bulk-action">
                            <option value=""><?php esc_html_e( 'Bulkactie kiezen', 'publion' ); ?></option>
                            <option value="generate"><?php esc_html_e( 'Genereren', 'publion' ); ?></option>
                            <option value="delete"><?php esc_html_e( 'Verwijderen', 'publion' ); ?></option>
                        </select>
                        <button type="button" id="publion-bulk-apply" class="button"><?php esc_html_e( 'Toepassen', 'publion' ); ?></button>
                        <span id="publion-bulk-status" style="margin-left:6px;"></span>
                    </div>
                    <table class="widefat striped" id="publion-queue-table">
                        <thead>
                            <tr>
                                <th style="text-align:center; width:36px;"><?php esc_html_e( 'Selectie', 'publion' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Acties', 'publion' ); ?></th>
                                <th><?php esc_html_e( 'Onderwerp', 'publion' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Categorie', 'publion' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Gepland op', 'publion' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Dagen tot aanmaak', 'publion' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Onderwerp aangemaakt op', 'publion' ); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div style="text-align:center;margin-top:20px;">
                        <button id="publion-load-more" class="button"><?php esc_html_e( 'Meer laden...', 'publion' ); ?></button>
                    </div>
                </div>

                <h2 class="publion-accordion-heading">
                  <span class="publion-heading-label"><?php esc_html_e( 'Aangemaakte posts', 'publion' ); ?></span>
                  <span class="publion-accordion-arrow">▼</span>
                </h2>
                <div class="publion-accordion-body" style="display:none;">
                    <table class="widefat striped" id="publion-created-table">
                        <thead>
                            <tr>
                                <th style="text-align: center;"><?php esc_html_e( 'Acties', 'publion' ); ?></th>
                                <th><?php esc_html_e( 'Onderwerp', 'publion' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Categorie', 'publion' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Onderwerp aangemaakt op', 'publion' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Post aangemaakt op', 'publion' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Publicatiedatum', 'publion' ); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div style="text-align:center;margin-top:20px;">
                        <button id="publion-load-more-created" class="button"><?php esc_html_e( 'Meer laden...', 'publion' ); ?></button>
                    </div>
                </div>
            </div>

            <!-- Tab: Post Creation Settings -->
            <?php
            $settings    = get_option( 'publion_post_settings', [] );
            $cta_enabled = $settings['cta_enabled'] ?? 'no';
            $author_id   = isset( $settings['default_post_author'] ) ? (int) $settings['default_post_author'] : 0;
            $author_users = get_users(
                [
                    'orderby' => 'display_name',
                    'order'   => 'ASC',
                    'fields'  => [ 'ID', 'display_name', 'user_login' ],
                ]
            );
            ?>
            <div id="publion-post-settings" class="publion-tab-content" style="display:none;">
                <form id="publion-post-settings-form">
                    <?php wp_nonce_field( 'publion_nonce', 'publion_nonce' ); ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="publion_time_frame_days"><?php esc_html_e( 'Tijdvenster voor postcreatie (dagen)', 'publion' ); ?></label></th>
                            <td>
                                <input type="number" id="publion_time_frame_days" name="time_frame_days" style="width:60px;" min="1"
                                    value="<?php echo esc_attr( $settings['time_frame_days'] ?? 3 ); ?>" />
                            </td>
                        </tr>

                        <tr>
                            <th><label for="publion_post_creation_time"><?php esc_html_e( 'Standaardtijd voor postcreatie', 'publion' ); ?></label></th>
                            <td>
                                <input type="time" id="publion_post_creation_time" name="post_creation_time"
                                    value="<?php echo esc_attr( $settings['post_creation_time'] ?? '00:00' ); ?>" />
                                <p class="description" style="margin-top:6px; max-width: 600px;">
                                    <?php esc_html_e( 'Wordt gebruikt om de standaardplanning te bepalen (bijv. 00:00).', 'publion' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th><label for="publion_post_status"><?php esc_html_e( 'Poststatus bij aanmaken', 'publion' ); ?></label></th>
                            <td>
                                <select id="publion_post_status" name="post_status">
                                    <option value="draft" <?php selected( $settings['post_status'] ?? 'draft', 'draft' ); ?>><?php esc_html_e( 'Concept', 'publion' ); ?></option>
                                    <option value="publish" <?php selected( $settings['post_status'] ?? 'draft', 'publish' ); ?>><?php esc_html_e( 'Gepubliceerd', 'publion' ); ?></option>
                                </select>
                                <p class="description" style="margin-top:6px; max-width: 600px;">
                                    <strong><?php esc_html_e( 'Let op:', 'publion' ); ?></strong>
                                    <em><?php esc_html_e( 'Concept wordt aanbevolen. Als er geen AI-afbeeldingen gegenereerd kunnen worden, worden placeholders gebruikt. Kies je voor Gepubliceerd, dan kan de post live gaan met placeholders.', 'publion' ); ?></em>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th><label for="publion_default_post_author"><?php esc_html_e( 'Standaard auteur voor posts', 'publion' ); ?></label></th>
                            <td>
                                <select id="publion_default_post_author" name="default_post_author">
                                    <option value="0"><?php esc_html_e( 'Geen voorkeur (gebruik uitvoerende gebruiker)', 'publion' ); ?></option>
                                    <?php
                                    foreach ( $author_users as $user ) {
                                        if ( empty( $user->ID ) || ! user_can( (int) $user->ID, 'edit_posts' ) ) {
                                            continue;
                                        }
                                        $label = $user->display_name . ' (' . $user->user_login . ')';
                                        echo '<option value="' . esc_attr( $user->ID ) . '" ' . selected( $author_id, $user->ID, false ) . '>' . esc_html( $label ) . '</option>';
                                    }
                                    ?>
                                </select>
                                <p class="description" style="margin-top:6px; max-width: 600px;">
                                    <?php esc_html_e( 'Wordt gebruikt voor automatisch aangemaakte posts.', 'publion' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Posttitel verbergen', 'publion' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="hide_title" id="publion_hide_title" value="yes" <?php checked( $settings['hide_title'] ?? '', 'yes' ); ?> />
                                    <?php esc_html_e( 'Verberg de posttitel (voor thema\'s die deze automatisch tonen)', 'publion' ); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Automatisch onderwerp toevoegen', 'publion' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="auto_daily_topic" id="publion_auto_daily_topic" value="yes" <?php checked( $settings['auto_daily_topic'] ?? '', 'yes' ); ?> />
                                    <?php esc_html_e( 'Voeg automatisch een nieuw onderwerp toe (willekeurige categorie)', 'publion' ); ?>
                                </label>
                                <div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:14px; align-items:center;">
                                    <label for="publion_daily_topic_time">
                                        <?php esc_html_e( 'Tijdstip:', 'publion' ); ?>
                                        <input type="time" id="publion_daily_topic_time" name="daily_topic_time"
                                            value="<?php echo esc_attr( $settings['daily_topic_time'] ?? '00:00' ); ?>" style="margin-left:6px;" />
                                    </label>
                                    <label for="publion_daily_topic_interval_days">
                                        <?php esc_html_e( 'Elke', 'publion' ); ?>
                                        <input type="number" id="publion_daily_topic_interval_days" name="daily_topic_interval_days" min="1"
                                            value="<?php echo esc_attr( $settings['daily_topic_interval_days'] ?? 1 ); ?>" style="width:60px; margin:0 6px;" />
                                        <?php esc_html_e( 'dagen', 'publion' ); ?>
                                    </label>
                                </div>
                                <p class="description" style="margin-top:6px;">
                                    <?php
                                    $next_daily_ts = (int) wp_next_scheduled( 'publion_daily_topic_hook' );
                                    if ( ! $next_daily_ts && ( $settings['auto_daily_topic'] ?? 'no' ) === 'yes' ) {
                                        $next_daily_ts = publion_calculate_initial_daily_topic_timestamp( $settings );
                                    }
                                    $next_daily_label = $next_daily_ts ? wp_date( 'M d, Y H:i', $next_daily_ts ) : __( 'Niet gepland', 'publion' );
                                    ?>
                                    <?php esc_html_e( 'Volgende onderwerp generatie:', 'publion' ); ?>
                                    <span id="publion-next-daily-topic"><?php echo esc_html( $next_daily_label ); ?></span>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Voorkeurswebsite voor externe links', 'publion' ); ?></th>
                            <td>
                                <input type="text" id="publion_preferred_external_domain" name="preferred_external_domain" style="width:320px;"
                                       value="<?php echo esc_attr( $settings['preferred_external_domain'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Bijv. refacthor.nl', 'publion' ); ?>" />
                                <p class="description" style="margin-top:6px; max-width: 600px;">
                                    <?php esc_html_e( 'Deze website wordt altijd minimaal 1x gelinkt in elke post (prioriteit).', 'publion' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Specifieke URL\'s (optioneel)', 'publion' ); ?></th>
                            <td>
                                <textarea id="publion_preferred_external_urls" name="preferred_external_urls" rows="4" style="width:100%; max-width:600px;"><?php echo esc_textarea( $settings['preferred_external_urls'] ?? '' ); ?></textarea>
                                <p class="description" style="margin-top:6px; max-width: 600px;">
                                    <?php esc_html_e( 'Zet elke URL op een nieuwe regel. AI gebruikt deze pagina\'s als voorkeur.', 'publion' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Rank Math integratie', 'publion' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rank_math_integration" id="publion_rank_math_integration" value="yes" <?php checked( $settings['rank_math_integration'] ?? '', 'yes' ); ?> />
                                    <?php esc_html_e( 'Voeg automatisch focus keyword en meta description toe via Rank Math', 'publion' ); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Gestructureerde artikeldata', 'publion' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="structured_data" id="publion_structured_data" value="yes" <?php checked( $settings['structured_data'] ?? 'yes', 'yes' ); ?> />
                                    <?php esc_html_e( 'Voeg BlogPosting- en FAQ-data toe aan Publion-artikelen', 'publion' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'De data wordt dynamisch opgebouwd uit de definitieve titel, auteur, afbeeldingen en FAQ-sectie.', 'publion' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="publion_image_border_radius"><?php esc_html_e( 'Afronding van afbeeldingen', 'publion' ); ?></label></th>
                            <td>
                                <input type="number" id="publion_image_border_radius" name="image_border_radius" min="0" max="48" step="1" value="<?php echo esc_attr( $settings['image_border_radius'] ?? 8 ); ?>" /> px
                                <p class="description"><?php esc_html_e( 'Standaard 8px. Geldt voor alle afbeeldingen in Publion-artikelen, inclusief uitgelichte afbeeldingen waar het thema deze binnen het artikel toont.', 'publion' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="publion_article_style_mode"><?php esc_html_e( 'Artikelstijl', 'publion' ); ?></label></th>
                            <td>
                                <select id="publion_article_style_mode" name="article_style_mode">
                                    <option value="inherit" <?php selected( $settings['article_style_mode'] ?? 'inherit', 'inherit' ); ?>><?php esc_html_e( 'Thema volgen (aanbevolen)', 'publion' ); ?></option>
                                    <option value="refined" <?php selected( $settings['article_style_mode'] ?? 'inherit', 'refined' ); ?>><?php esc_html_e( 'Verfijnde Publion-leesstijl', 'publion' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Thema volgen behoudt de typografie en kleuren van je WordPress-thema. De verfijnde stijl voegt alleen een leesbreedte, nette links en consistente beeldweergave toe.', 'publion' ); ?></p>
                            </td>
                        </tr>

                        <tr class="publion-refined-style-control">
                            <th scope="row"><label for="publion_content_accent_color"><?php esc_html_e( 'Accentkleur voor links', 'publion' ); ?></label></th>
                            <td>
                                <input type="color" id="publion_content_accent_color" name="content_accent_color" value="<?php echo esc_attr( $settings['content_accent_color'] ?? '#4f46e5' ); ?>" />
                                <p class="description"><?php esc_html_e( 'Alleen gebruikt in de verfijnde Publion-leesstijl. Kies bij voorkeur een kleur die voldoende contrast heeft binnen je thema.', 'publion' ); ?></p>
                            </td>
                        </tr>

                        <tr class="publion-refined-style-control">
                            <th scope="row"><label for="publion_content_max_width"><?php esc_html_e( 'Maximale leesbreedte', 'publion' ); ?></label></th>
                            <td>
                                <input type="number" id="publion_content_max_width" name="content_max_width" min="560" max="1200" step="10" value="<?php echo esc_attr( $settings['content_max_width'] ?? 760 ); ?>" /> px
                                <p class="description"><?php esc_html_e( 'Alleen gebruikt in de verfijnde stijl. 720–800px is voor de meeste long-form artikelen prettig leesbaar.', 'publion' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="publion_custom_article_css"><?php esc_html_e( 'Eigen CSS voor Publion-artikelen', 'publion' ); ?></label></th>
                            <td>
                                <textarea id="publion_custom_article_css" name="custom_article_css" rows="7" style="width:100%; max-width:700px; font-family:monospace;" placeholder=".publion-generated-post .entry-content h2 { letter-spacing: -0.02em; }"><?php echo esc_textarea( $settings['custom_article_css'] ?? '' ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Optioneel voor beheerders. Scope elke selector met .publion-generated-post, zodat je alleen door Publion gemaakte artikelen aanpast. @import en &lt;style&gt;-tags worden verwijderd.', 'publion' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="publion_search_console_url"><?php esc_html_e( 'Google Search Console-rapport', 'publion' ); ?></label></th>
                            <td>
                                <input type="url" id="publion_search_console_url" name="search_console_url" style="width:100%; max-width:600px;" value="<?php echo esc_url( $settings['search_console_url'] ?? '' ); ?>" placeholder="https://search.google.com/search-console/..." />
                                <p class="description"><?php esc_html_e( 'Optioneel. Plak de link naar de juiste property of een opgeslagen rapport. Het dashboard opent die link rechtstreeks.', 'publion' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="publion_ga4_url"><?php esc_html_e( 'Google Analytics-rapport', 'publion' ); ?></label></th>
                            <td>
                                <input type="url" id="publion_ga4_url" name="ga4_url" style="width:100%; max-width:600px;" value="<?php echo esc_url( $settings['ga4_url'] ?? '' ); ?>" placeholder="https://analytics.google.com/..." />
                                <p class="description"><?php esc_html_e( 'Optioneel. Hiermee houd je Publion eenvoudig verbonden met je eigen GA4-overzicht, zonder extra toegangsrechten in deze plugin.', 'publion' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th><label for="publion_cta_enabled"><?php esc_html_e( 'Call-to-action in footer toevoegen?', 'publion' ); ?></label></th>
                            <td>
                                <select id="publion_cta_enabled" name="cta_enabled">
                                    <option value="no" <?php selected( $cta_enabled, 'no' ); ?>><?php esc_html_e( 'Nee', 'publion' ); ?></option>
                                    <option value="yes" <?php selected( $cta_enabled, 'yes' ); ?>><?php esc_html_e( 'Ja', 'publion' ); ?></option>
                                </select>
                            </td>
                        </tr>

                        <tr id="publion_cta_fields" style="<?php echo ( $cta_enabled === 'yes' ) ? '' : 'display:none;'; ?>">
                            <th><label for="publion_cta_text"><?php esc_html_e( 'Call-to-action tekst', 'publion' ); ?></label></th>
                            <td>
                                <input type="text" id="publion_cta_text" name="cta_text" style="width:100%;"
                                    value="<?php echo esc_attr( $settings['cta_text'] ?? '' ); ?>" />
                            </td>
                        </tr>

                        <tr class="publion_cta_link_row" style="<?php echo ( $cta_enabled === 'yes' ) ? '' : 'display:none;'; ?>">
                            <th><label for="publion_cta_link"><?php esc_html_e( 'Call-to-action link (URL)', 'publion' ); ?></label></th>
                            <td>
                                <input type="url" id="publion_cta_link" name="cta_link" style="width:100%;"
                                    value="<?php echo esc_url( $settings['cta_link'] ?? '' ); ?>" />
                            </td>
                        </tr>

                        <tr>
                            <th><label for="publion_notification_email"><?php esc_html_e( 'E-mailadres voor meldingen', 'publion' ); ?></label></th>
                            <td>
                                <input type="email" id="publion_notification_email" name="notification_email" style="width:250px;"
                                       value="<?php echo esc_attr( $settings['notification_email'] ?? '' ); ?>" />
                                <p class="description" style="margin-top:6px;">
                                    <?php esc_html_e( 'Optioneel: ontvang een e-mail wanneer automatisch een post is aangemaakt.', 'publion' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <button type="submit" id="publion-save-button" class="button button-primary"><?php esc_html_e( 'Instellingen opslaan', 'publion' ); ?></button>
                    <span id="publion-save-status" style="margin-left:10px;"></span>
                </form>
            </div>

            <!-- OpenAI/ChatGPT instellingen -->
            <div id="publion-settings" class="publion-tab-content" style="display:none;margin-top:20px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                    <h3 style="margin:0;font-size:1.09em;"><?php esc_html_e( 'OpenAI API-sleutel', 'publion' ); ?></h3>
                    <a href="https://platform.openai.com/api-keys" target="_blank" class="button"><?php esc_html_e( 'API-sleutel aanmaken', 'publion' ); ?></a>
                    <span class="publion-api-key-warning" style="color:#d90000; margin-left:10px; font-weight:bold; <?php echo esc_html( $warning_display ); ?>">
                        <?php esc_html_e( 'Voer een API-sleutel in om de AI-functie te laten werken.', 'publion' ); ?><br/>
                        <span style="color:#000000; font-weight:normal; font-size:12px;">
                            <strong><?php esc_html_e( 'Let op:', 'publion' ); ?></strong>
                            <?php esc_html_e( 'Vereist een Plus- of Pro-account bij OpenAI', 'publion' ); ?>
                        </span>
                    </span>
                </div>

                <div style="display:flex; align-items:flex-start; gap:18px; margin-top:22px;">
                    <!-- API Key Field -->
                    <div style="max-width:600px; flex:0 0 600px;">
                        <input type="text" name="publion_api_key" id="publion_api_key"
                            value="<?php echo esc_attr( $openai_api_key ); ?>"
                            style="width:100%;" autocomplete="off">
                        <button type="button" id="publion-save-api-key" class="button button-primary" style="margin-top:8px;">
                            <?php esc_html_e( 'Sleutel opslaan', 'publion' ); ?>
                        </button>
                        <span id="publion-api-key-status" class="publion-save-status" style="position:relative; top:8px;"></span>

                        <div style="margin-top:16px;">
                            <label for="publion_openai_model" style="display:block; font-weight:600; margin-bottom:6px;">
                                <?php esc_html_e( 'OpenAI-model', 'publion' ); ?>
                            </label>
                            <select id="publion_openai_model" style="width:100%; max-width:320px;">
                                <?php foreach ( $model_options as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $openai_model, $value ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="publion-save-model" class="button" style="margin-left:8px;">
                                <?php esc_html_e( 'Model opslaan', 'publion' ); ?>
                            </button>
                            <span id="publion-model-status" class="publion-save-status" style="position:relative; top:2px; margin-left:6px;"></span>
                        </div>
                    </div>

                    <!-- Instructions Accordion -->
                    <div class="publion-openai-instructions-accordion-wrap" style="min-width:230px;max-width:320px;margin:0;">
                        <div class="publion-openai-instructions-header" style="cursor:pointer;user-select:none;display:flex;align-items:center;gap:8px;font-size:1.07em;font-weight:600;">
                            <span><?php esc_html_e( 'Instructies', 'publion' ); ?></span>
                            <span class="dashicons dashicons-arrow-down publion-accordion-arrow" style="font-size:1.25em;transition:transform .2s;"></span>
                        </div>
                        <div class="publion-openai-instructions-body" style="display:none;padding:13px 16px 10px 8px;background:#f8f8fc;border-radius:6px;border:1px solid #eee;margin-top:5px;">
                            <ol style="margin:0 0 0 16px;padding:0;line-height:1.7;">
                                <li><?php esc_html_e( 'Klik op "API-sleutel aanmaken"', 'publion' ); ?></li>
                                <li><?php esc_html_e( 'Klik op de OpenAI-pagina op "Create new secret key"', 'publion' ); ?></li>
                                <li><?php esc_html_e( 'Voer een naam in, kies "Default Project", zet Permissions op All en klik op "Create secret key"', 'publion' ); ?></li>
                                <li><?php esc_html_e( 'Kopieer de sleutel, kom terug, plak in het veld en klik op "Sleutel opslaan"', 'publion' ); ?></li>
                                <li><strong><em><?php esc_html_e( 'Zorg dat facturatie is ingeschakeld en je OpenAI-account is opgewaardeerd, anders werkt je sleutel niet!', 'publion' ); ?></em></strong></li>
                            </ol>
                        </div>
                    </div>
                </div>

                <h3 style="margin-bottom:4px; font-size:1.09em; margin-top:20px;">
                    <?php esc_html_e( 'ChatGPT-voorprompt', 'publion' ); ?>
                    <?php if ( $openai_prompt === $default_prompt ) echo '(Standaard)'; ?>
                </h3>
                <p style="margin-top:0;">
                    <?php
                    esc_html_e( 'Deze prompt helpt ChatGPT bij het genereren van onderwerp-ideeën en volledige postinhoud.', 'publion' );
                    echo '<br>';
                    esc_html_e( 'Gebruik dit om het doel van je website of bedrijf te bepalen, plus tone-of-voice, doelgroep en merkpersoonlijkheid.', 'publion' );
                    echo '<br>';
                    ?>
                    <em><strong><?php esc_html_e( 'Tip:', 'publion' ); ?></strong> <?php esc_html_e( 'Weet je niet wat je moet schrijven? Vraag ChatGPT om hulp, plak deze tekst in een chat en gebruik het resultaat.', 'publion' ); ?></em>
                </p>

                <div style="width:600px; display:inline-block;">
                    <textarea name="publion_prompt" id="publion_prompt" style="width:100%;height:365px;"><?php echo esc_textarea( stripslashes( $openai_prompt ) ); ?></textarea>
                    <button type="button" id="publion-save-prompt" class="button button-primary" style="margin-top:8px;"><?php esc_html_e( 'Prompt opslaan', 'publion' ); ?></button>
                    <span id="publion-prompt-status" class="publion-save-status" style="position:relative; top:8px;"></span>
                </div>

                <?php if ( $openai_prompt !== $default_prompt ) : ?>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field( 'publion_reset_prompt' ); ?>
                        <button type="submit" name="publion_reset_prompt" class="button"><?php esc_html_e( 'Terugzetten naar standaard', 'publion' ); ?></button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Help and diagnostics -->
            <div id="publion-help" class="publion-tab-content" style="display:none;">
                <div class="publion-section-intro">
                    <p class="publion-eyebrow"><?php esc_html_e( 'HANDLEIDING', 'publion' ); ?></p>
                    <h2><?php esc_html_e( 'Een veilige, herhaalbare contentworkflow.', 'publion' ); ?></h2>
                    <p><?php esc_html_e( 'Gebruik Publion om sneller te werken, maar review iedere conceptpost altijd inhoudelijk vóór publicatie.', 'publion' ); ?></p>
                </div>
                <div class="publion-help-grid">
                    <section class="publion-dashboard-panel">
                        <h3><?php esc_html_e( 'Snel starten', 'publion' ); ?></h3>
                        <ol class="publion-guide-list">
                            <li><strong><?php esc_html_e( 'Verbind OpenAI.', 'publion' ); ?></strong> <?php esc_html_e( 'Voeg in AI-instellingen een API-sleutel toe en sla die op.', 'publion' ); ?></li>
                            <li><strong><?php esc_html_e( 'Kies een categorie.', 'publion' ); ?></strong> <?php esc_html_e( 'Laat Publion vijf invalshoeken maken en lees per voorstel de zoekintentie en FAQ-vragen.', 'publion' ); ?></li>
                            <li><strong><?php esc_html_e( 'Plan met aandacht.', 'publion' ); ?></strong> <?php esc_html_e( 'Zet alleen relevante onderwerpen in de wachtrij en kies een veilig publicatieritme.', 'publion' ); ?></li>
                            <li><strong><?php esc_html_e( 'Review elk concept.', 'publion' ); ?></strong> <?php esc_html_e( 'Controleer feiten, links, auteursrecht, merktoon, titel, meta description en afbeeldingen.', 'publion' ); ?></li>
                            <li><strong><?php esc_html_e( 'Meet en verbeter.', 'publion' ); ?></strong> <?php esc_html_e( 'Kijk na publicatie in Search Console naar vertoningen, klikken, CTR en positie. Start met pagina’s die veel vertoningen maar weinig klikken krijgen.', 'publion' ); ?></li>
                        </ol>
                    </section>
                    <section class="publion-dashboard-panel">
                        <h3><?php esc_html_e( 'Problemen oplossen', 'publion' ); ?></h3>
                        <dl class="publion-troubleshooting-list">
                            <div><dt><?php esc_html_e( 'Geen AI-antwoord', 'publion' ); ?></dt><dd><?php esc_html_e( 'Controleer de API-sleutel, facturatie, netwerkverbinding en het gekozen model. Probeer daarna opnieuw met één onderwerp.', 'publion' ); ?></dd></div>
                            <div><dt><?php esc_html_e( 'Afbeelding ontbreekt', 'publion' ); ?></dt><dd><?php esc_html_e( 'Publion gebruikt een placeholder wanneer beeldgeneratie faalt. Vervang die vóór publicatie en controleer de foutmelding in Overzicht.', 'publion' ); ?></dd></div>
                            <div><dt><?php esc_html_e( 'Geplande post verschijnt niet', 'publion' ); ?></dt><dd><?php esc_html_e( 'WordPress Cron draait bij websitebezoek. Controleer eerst de planning en gebruik zo nodig een echte servercron.', 'publion' ); ?></dd></div>
                            <div><dt><?php esc_html_e( 'Resultaten blijven uit', 'publion' ); ?></dt><dd><?php esc_html_e( 'Verbeter de zoekintentie, titel en inhoud op basis van Search Console-data. Publiceer geen ongecontroleerde of vergelijkbare artikelen in bulk.', 'publion' ); ?></dd></div>
                        </dl>
                    </section>
                </div>
                <div class="publion-resource-bar">
                    <a class="button button-primary" href="<?php echo esc_url( PUBLION_URL . 'DASHBOARD-HANDLEIDING.md' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open dashboardhandleiding', 'publion' ); ?></a>
                    <a class="button button-primary" href="<?php echo esc_url( PUBLION_URL . 'publion-documentation.pdf' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open volledige pluginhandleiding', 'publion' ); ?></a>
                    <a class="button" href="https://support.google.com/webmasters/answer/7576553" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Lees Search Console-metrics', 'publion' ); ?></a>
                    <a class="button" href="https://support.google.com/webmasters/answer/17010961" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Vind kansen met lage CTR', 'publion' ); ?></a>
                </div>
            </div>

            <p style="margin-top: 40px; font-size: 12px; color: #666;">
                <?php esc_html_e( 'Afbeeldingen in posts worden gegenereerd door OpenAI (gpt-image-1.5).', 'publion' ); ?>
                <?php
                $image_error = get_option( 'publion_last_image_error', '' );
                if ( ! empty( $image_error ) ) {
                    echo '<br><span style="color:#b91c1c;">' . esc_html( $image_error ) . '</span>';
                }
                ?>
            </p>
        </div>
        <?php
    }
}
