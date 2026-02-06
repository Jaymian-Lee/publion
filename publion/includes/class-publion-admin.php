<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Publion_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'handle_form_submissions' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_styles' ] );
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

        if ( ! empty( $settings['hide_title'] ) && $settings['hide_title'] === 'yes' ) {
            wp_register_style( 'publion-hide-title', false ); // No file, just inline
            wp_enqueue_style( 'publion-hide-title' );
            wp_add_inline_style( 'publion-hide-title', '.publion-title { display: none !important; }' );
        }
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
        $topic_suggestions_prompt = get_option( 'publion_topic_suggestions_prompt', '' );
        $default_post_prompt = function_exists( 'publion_get_default_post_prompt_template' )
            ? publion_get_default_post_prompt_template()
            : '';
        $openai_post_prompt = get_option( 'publion_post_prompt', $default_post_prompt );

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

        <div class="wrap">
            <h1><?php esc_html_e( 'Publion - Blogposts genereren met ChatGPT', 'publion' ); ?></h1>

            <?php settings_errors( 'publion_messages' ); ?>

            <h2 class="nav-tab-wrapper">
                <a href="javascript:void(0)" class="nav-tab nav-tab-active" data-tab="publion-generate"><?php esc_html_e( 'Onderwerpen genereren & in wachtrij zetten', 'publion' ); ?></a>
                <a href="javascript:void(0)" class="nav-tab" data-tab="publion-queue"><?php esc_html_e( 'Postcreatie', 'publion' ); ?></a>
                <a href="javascript:void(0)" class="nav-tab" data-tab="publion-post-settings"><?php esc_html_e( 'Instellingen voor postcreatie', 'publion' ); ?></a>
                <a href="javascript:void(0)" class="nav-tab" data-tab="publion-settings"><?php esc_html_e( 'OpenAI/ChatGPT instellingen', 'publion' ); ?></a>
            </h2>

            <!-- Tab: Generate Topics & Queue Posts -->
            <div id="publion-generate" class="publion-tab-content" style="display:block;">

                <h2><?php esc_html_e( 'Selecteer een postcategorie voor onderwerp-generatie', 'publion' ); ?></h2>
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
                                    <?php esc_html_e( 'Als er een externe bron nodig is, krijgt deze website prioriteit.', 'publion' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Specifieke URL\'s (optioneel)', 'publion' ); ?></th>
                            <td>
                                <textarea id="publion_preferred_external_urls" name="preferred_external_urls" rows="4" style="width:100%; max-width:600px;"><?php echo esc_textarea( $settings['preferred_external_urls'] ?? '' ); ?></textarea>
                                <p class="description" style="margin-top:6px; max-width: 600px;">
                                    <?php esc_html_e( 'Zet elke URL op een nieuwe regel. Alleen gebruiken als er echt een externe bron nodig is.', 'publion' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Pillar per categorie', 'publion' ); ?></th>
                            <td>
                                <?php
                                $pillar_links = is_array( $settings['pillar_links'] ?? null ) ? $settings['pillar_links'] : [];
                                $all_categories = get_categories( [ 'hide_empty' => false ] );
                                if ( ! empty( $all_categories ) ) :
                                ?>
                                    <table class="widefat striped" style="max-width:700px;">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e( 'Categorie', 'publion' ); ?></th>
                                                <th><?php esc_html_e( 'Pillar URL of Post ID', 'publion' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $all_categories as $cat ) : ?>
                                                <tr>
                                                    <td><?php echo esc_html( $cat->name ); ?></td>
                                                    <td>
                                                        <input
                                                            type="text"
                                                            class="publion-pillar-link-input"
                                                            data-category-id="<?php echo esc_attr( $cat->term_id ); ?>"
                                                            name="pillar_links[<?php echo esc_attr( $cat->term_id ); ?>]"
                                                            style="width:100%;"
                                                            value="<?php echo esc_attr( $pillar_links[ $cat->term_id ] ?? '' ); ?>"
                                                            placeholder="<?php esc_attr_e( 'https://... of 123', 'publion' ); ?>"
                                                        />
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <p class="description" style="margin-top:6px; max-width: 700px;">
                                        <?php esc_html_e( 'Wordt gebruikt in de "Verder lezen" sectie. Laat leeg om alleen recente posts te tonen.', 'publion' ); ?>
                                    </p>
                                <?php else : ?>
                                    <p class="description"><?php esc_html_e( 'Geen categorieen gevonden.', 'publion' ); ?></p>
                                <?php endif; ?>
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
                            <th scope="row"><?php esc_html_e( 'Last updated footer', 'publion' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="last_updated_enabled" id="publion_last_updated_enabled" value="yes" <?php checked( $settings['last_updated_enabled'] ?? '', 'yes' ); ?> />
                                    <?php esc_html_e( 'Voeg "Laatst bijgewerkt" toe onderaan de content', 'publion' ); ?>
                                </label>
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
                    <?php esc_html_e( 'Topic-voorprompt', 'publion' ); ?>
                    <?php if ( $openai_prompt === $default_prompt ) echo '(Standaard)'; ?>
                </h3>
                <p style="margin-top:0;">
                    <?php
                    esc_html_e( 'Deze prompt helpt ChatGPT bij het genereren van onderwerp-ideeën.', 'publion' );
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

                <h3 style="margin-bottom:4px; font-size:1.09em; margin-top:24px;">
                    <?php esc_html_e( 'Onderwerpvoorstellen prompt', 'publion' ); ?>
                </h3>
                <p style="margin-top:0;">
                    <?php
                    esc_html_e( 'Gebruik dit om structuur of voorkeuren voor AI-onderwerpvoorstellen te sturen.', 'publion' );
                    echo '<br>';
                    esc_html_e( 'Bijv: "Gebruik altijd formaat: Probleem + Oplossing" of "Zet lokale plaatsnaam in elk onderwerp".', 'publion' );
                    ?>
                </p>

                <div style="width:600px; display:inline-block;">
                    <textarea name="publion_topic_suggestions_prompt" id="publion_topic_suggestions_prompt" style="width:100%;height:160px;"><?php echo esc_textarea( stripslashes( $topic_suggestions_prompt ) ); ?></textarea>
                    <button type="button" id="publion-save-topic-prompt" class="button" style="margin-top:8px;"><?php esc_html_e( 'Onderwerp prompt opslaan', 'publion' ); ?></button>
                    <span id="publion-topic-prompt-status" class="publion-save-status" style="position:relative; top:8px;"></span>
                </div>

                <h3 style="margin-bottom:4px; font-size:1.09em; margin-top:24px;">
                    <?php esc_html_e( 'Post Prompt Template', 'publion' ); ?>
                    <?php if ( $openai_post_prompt === $default_post_prompt ) echo '(Standaard)'; ?>
                </h3>
                <p style="margin-top:0;">
                    <?php
                    esc_html_e( 'Deze template bepaalt de structuur en SEO-output van volledige posts.', 'publion' );
                    echo '<br>';
                    esc_html_e( 'Gebruik {{topic}} en {{category}} als placeholders. Het SEO-blok bovenaan is verplicht.', 'publion' );
                    ?>
                </p>

                <div style="width:600px; display:inline-block;">
                    <textarea name="publion_post_prompt" id="publion_post_prompt" style="width:100%;height:365px;"><?php echo esc_textarea( stripslashes( $openai_post_prompt ) ); ?></textarea>
                    <button type="button" id="publion-save-post-prompt" class="button button-primary" style="margin-top:8px;"><?php esc_html_e( 'Post prompt opslaan', 'publion' ); ?></button>
                    <span id="publion-post-prompt-status" class="publion-save-status" style="position:relative; top:8px;"></span>
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
