<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AutoPostAI_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'handle_form_submissions' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_styles' ] );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php', // parent slug = Posts
            __( 'AutoPost AI', 'autopost-ai' ),
            __( 'AutoPost AI', 'autopost-ai' ),
            'manage_options',
            'autopost-ai',
            [ $this, 'render_admin_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'posts_page_autopost-ai' ) {
            return;
        }

        wp_enqueue_style( 'autopost-ai-style', AUTOPOST_AI_URL . 'assets/admin.css', [], AUTOPOST_AI_VERSION );
        wp_enqueue_script( 'autopost-ai-script', AUTOPOST_AI_URL . 'assets/admin.js', [ 'jquery' ], AUTOPOST_AI_VERSION, true );

        // Localize a reusable nonce for all AJAX requests that call check_ajax_referer( 'autopost_ai_nonce', 'nonce' ).
        wp_localize_script(
            'autopost-ai-script',
            'AutoPostAI',
            [
                'ajax_url'    => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'autopost_ai_nonce' ),
                'has_api_key' => ! empty( get_option( 'autopost_ai_api_key' ) ),
            ]
        );
    }

    public function enqueue_frontend_styles() {
        $settings = get_option( 'autopost_ai_post_settings', [] );

        if ( ! empty( $settings['hide_title'] ) && $settings['hide_title'] === 'yes' ) {
            wp_register_style( 'autopost-ai-hide-title', false ); // No file, just inline
            wp_enqueue_style( 'autopost-ai-hide-title' );
            wp_add_inline_style( 'autopost-ai-hide-title', '.autopost-title { display: none !important; }' );
        }
    }

    public function handle_form_submissions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['autopost_ai_save_openai_key'] ) && check_admin_referer( 'autopost_ai_save_openai_key' ) ) {
            $key = sanitize_text_field( wp_unslash( $_POST['autopost_ai_api_key'] ?? '' ) );
            update_option( 'autopost_ai_api_key', $key );
            add_settings_error( 'autopost_ai_messages', 'key_saved', __( 'API Key saved.', 'autopost-ai' ), 'updated' );
        }

        if ( isset( $_POST['autopost_ai_save_prompt'] ) && check_admin_referer( 'autopost_ai_save_prompt' ) ) {
            $prompt = wp_kses_post( wp_unslash( $_POST['autopost_ai_prompt'] ?? '' ) );
            update_option( 'autopost_ai_prompt', $prompt );
            add_settings_error( 'autopost_ai_messages', 'prompt_saved', __( 'Pre-prompt saved.', 'autopost-ai' ), 'updated' );
        }

        if ( isset( $_POST['autopost_ai_reset_prompt'] ) && check_admin_referer( 'autopost_ai_reset_prompt' ) ) {
            delete_option( 'autopost_ai_prompt' );
            add_settings_error( 'autopost_ai_messages', 'prompt_reset', __( 'Pre-prompt reset to default.', 'autopost-ai' ), 'updated' );
        }
    }

    public function render_admin_page() {
        if ( ! get_option( 'autopost_ai_last_post_created_at' ) ) {
            update_option( 'autopost_ai_last_post_created_at', current_time( 'mysql' ) );
        }

        $openai_api_key   = get_option( 'autopost_ai_api_key', '' );
        $warning_display  = empty( $openai_api_key ) ? 'display:inline-block;' : 'display:none;';
        $default_prompt   = "You are an expert blog writer creating high-value, SEO-optimized content for [YOUR BUSINESS NAME, IF APPLICABLE, AND WEBSITE URL], [WHAT YOUR BUSINESS/WEBSITE PROVIDES]. The goal is to [YOUR BUSINESS/WEBITE GOALS]. Match the tone of the brand: [THE TONE YOU WANT TO PORTRAY - example:professional yet approachable, knowledgeable but easy to understand]. Every topic should reflect [YOUR BUSINESS/WEBSITE NAME]'s mission to help [BUSINESS or PEOPLE] with [HOW YOU HELP BUSISESSES or PEOPLE].\n\n(Replace this prompt with your own to better reflect your goals.)";
        $openai_prompt    = get_option( 'autopost_ai_prompt', $default_prompt );

        // --- Handle manual topic form submission (with nonce verification) ---
        if (
            isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] &&
            isset( $_POST['autopost_ai_manual_add_topic'] ) &&
            check_admin_referer( 'autopost_ai_manual_add_topic' )
        ) {
            $manual_topic    = sanitize_text_field( wp_unslash( $_POST['manual_topic'] ?? '' ) );
            $manual_category = isset( $_POST['manual_category'] ) ? absint( wp_unslash( $_POST['manual_category'] ) ) : 0;

            if ( ! empty( $manual_topic ) && $manual_category ) {
                $category_obj = get_category( $manual_category );

                if ( $category_obj && ! is_wp_error( $category_obj ) ) {
                    global $wpdb;
                    $table = $wpdb->prefix . 'autopost_ai_queue';

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

                    // Redirect to preserve tab and show success.
                    wp_redirect( admin_url( 'admin.php?page=autopost-ai&autopost_ai_active_tab=autopost-queue&topic_added=1' ) );
                    exit;
                } else {
                    wp_redirect( admin_url( 'admin.php?page=autopost-ai&autopost_ai_active_tab=autopost-queue&topic_error=invalid_category' ) );
                    exit;
                }
            } else {
                wp_redirect( admin_url( 'admin.php?page=autopost-ai&autopost_ai_active_tab=autopost-queue&topic_error=missing_fields' ) );
                exit;
            }
        }
        ?>

        <div class="wrap">
            <h1><?php esc_html_e( 'AutoPost AI – Generate Blog Posts with ChatGPT', 'autopost-ai' ); ?></h1>

		<div class="autopost-ai-pro-cta" style="margin:12px 0 14px 0;padding:10px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc;font-size:12.3px;line-height:1.35;">
		  <strong>Want more?</strong> Unlock AI-powered images, a manual AI image tool, smarter scheduling, a streamlined approval flow, boosted SEO, Facebook auto-posting, and a refined multi-step creation process that keeps going even when things slow down - delivering better content and a premium user experience.
		  <ul style="margin:6px 0 0 16px;list-style:disc;">
		    <li>6 context-aware AI images per post (5 with your selected style rotation in the content and a featured image)</li>
		    <li>Manual AI image creation tool for user-driven prompt and negative tweaks (auto-saved in the WP media library)</li>
		    <li>Smarter post-creation scheduling + extra auto-publish scheduling</li>
		    <li>Streamlined post approval workflow (allowing review before auto-publishing)</li>
		    <li>Boosted SEO: better external links, intelligent internal links, relevant image names and alt text, optional Yoast integration + MORE!</li>
		    <li>Optional Facebook auto-posting on publication (to any page you control)</li>
		    <li>Refined multi-step "Create Now" process (no timeouts) + reliable scheduled auto-processing for post creation and publication</li>
		  </ul>
		  <p style="margin:8px 0 0 0;">
		    <strong>Ready for next-level content that puts you on top?</strong>
		    <a href="https://plugins.guru-is.com/product/autopost-ai-pro/?utm_source=plugin-free&utm_medium=admin-cta&utm_campaign=pro-upgrade"
		       target="_blank" rel="noopener noreferrer"
		       style="display:inline-block;margin-left:8px;padding:8px 14px;background:#2271b1;color:#fff;border-radius:6px;text-decoration:none;font-weight:700;">
		      Learn More → Go Pro!
		    </a>
		  </p>
		</div>
		  <p>
		  	  <strong>Love this plugin? <a href="https://wordpress.org/support/plugin/autopost-ai/reviews/#new-post" target="_blank" rel="noopener noreferrer"
		       style="display:inline-block;margin-left:8px;padding:8px 14px;background:#2271b1;color:#fff;border-radius:6px;text-decoration:none;font-weight:700;">Give us a review!</a></strong>
		  </p>
            <?php settings_errors( 'autopost_ai_messages' ); ?>

            <h2 class="nav-tab-wrapper">
                <a href="#" class="nav-tab nav-tab-active" data-tab="autopost-generate"><?php esc_html_e( 'Generate Topics & Queue Posts', 'autopost-ai' ); ?></a>
                <a href="#" class="nav-tab" data-tab="autopost-queue"><?php esc_html_e( 'Post Creation', 'autopost-ai' ); ?></a>
                <a href="#" class="nav-tab" data-tab="autopost-post-settings"><?php esc_html_e( 'Post Creation Settings', 'autopost-ai' ); ?></a>
                <a href="#" class="nav-tab" data-tab="autopost-settings"><?php esc_html_e( 'OpenAI/ChatGPT Settings', 'autopost-ai' ); ?></a>
            </h2>

            <!-- Tab: Generate Topics & Queue Posts -->
            <div id="autopost-generate" class="autopost-tab-content" style="display:block;">

                <h2><?php esc_html_e( 'Select a Post Category for Topic Generation', 'autopost-ai' ); ?></h2>
                <select id="autopost-ai-category">
                    <option value=""><?php esc_html_e( 'Select a category', 'autopost-ai' ); ?></option>
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
                <button id="autopost-ai-suggest" class="button button-primary" style="margin-left:8px;"><?php esc_html_e( 'Suggest Topics', 'autopost-ai' ); ?></button>
                <button id="autopost-ai-refresh" class="button" style="margin-left:8px; display:none;"><?php esc_html_e( 'Refresh Suggestions', 'autopost-ai' ); ?></button>

                <h2 id="autopost-ai-suggestions-heading" style="display:none;"><?php esc_html_e( 'AI Topic Suggestions', 'autopost-ai' ); ?></h2>
                <div id="autopost-ai-loading" style="display:none; margin: 10px 0; align-items:center; gap:10px;">
                    <em><?php esc_html_e( 'Loading topic suggestions...', 'autopost-ai' ); ?></em>
                    <span class="spinner is-active" style="float:none;"></span>
                </div>
                <ul id="autopost-ai-suggestions"></ul>

                <div id="autopost-selected-topics" style="display: none;">
                    <h2><?php esc_html_e( 'Selected Topics for Post Creation Queue', 'autopost-ai' ); ?></h2>
                    <table id="autopost-ai-queue" class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Action', 'autopost-ai' ); ?></th>
                                <th><?php esc_html_e( 'Category', 'autopost-ai' ); ?></th>
                                <th><?php esc_html_e( 'Topic', 'autopost-ai' ); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>

                    <br>
                    <button id="autopost-ai-save-queue" class="button button-primary"><?php esc_html_e( 'Add to Post Creation Queue', 'autopost-ai' ); ?></button>
                </div>
            </div>

            <!-- Tab: Post Creation Queue -->
            <div id="autopost-queue" class="autopost-tab-content" style="display:none;">
            <?php
            // Fetch categories except "Uncategorized"
            $categories = get_categories(
                [
                    'hide_empty' => false,
                    'exclude'    => [ get_option( 'default_category' ) ],
                ]
            );
            ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=autopost-ai' ) ); ?>" id="autopost-ai-manual-form" style="display: flex; align-items: center; gap: 10px; margin:20px 0 15px 0; flex-wrap: wrap; max-width: 100%;">
                <input type="hidden" name="autopost_ai_manual_add_topic" value="1" />
                <?php wp_nonce_field( 'autopost_ai_manual_add_topic' ); ?>

                <label for="manual_topic" style="font-weight: 600; white-space: nowrap;"><?php esc_html_e( 'Add a topic manually:', 'autopost-ai' ); ?></label>

                <select name="manual_category" required>
                    <option value=""><?php esc_html_e( 'Select a category', 'autopost-ai' ); ?></option>
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

                <input type="text" name="manual_topic" id="manual_topic" placeholder="<?php esc_attr_e( 'Enter topic...', 'autopost-ai' ); ?>" style="flex-grow: 1; min-width: 500px; font-size: 14px;" />

                <button type="submit" class="autopost-ai-tab-button button button-primary" data-tab="post_creation"><?php esc_html_e( 'Add', 'autopost-ai' ); ?></button>
            </form>

                <h2 class="autopost-accordion-heading active">
                  <span class="autopost-heading-label"><?php esc_html_e( 'Post Creation Queue', 'autopost-ai' ); ?></span>
                  <span class="autopost-accordion-arrow">▲</span>
                </h2>
                <div class="autopost-accordion-body" style="display:block;">
                    <table class="widefat striped" id="autopost-queue-table">
                        <thead>
                            <tr>
                                <th style="text-align: center;"><?php esc_html_e( 'Actions', 'autopost-ai' ); ?></th>
                                <th><?php esc_html_e( 'Topic', 'autopost-ai' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Category', 'autopost-ai' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Days Until Creation', 'autopost-ai' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Topic Creation Date', 'autopost-ai' ); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div style="text-align:center;margin-top:20px;">
                        <button id="autopost-load-more" class="button"><?php esc_html_e( 'Load More...', 'autopost-ai' ); ?></button>
                    </div>
                </div>

                <h2 class="autopost-accordion-heading">
                  <span class="autopost-heading-label"><?php esc_html_e( 'Created Posts', 'autopost-ai' ); ?></span>
                  <span class="autopost-accordion-arrow">▼</span>
                </h2>
                <div class="autopost-accordion-body" style="display:none;">
                    <table class="widefat striped" id="autopost-created-table">
                        <thead>
                            <tr>
                                <th style="text-align: center;"><?php esc_html_e( 'Actions', 'autopost-ai' ); ?></th>
                                <th><?php esc_html_e( 'Topic', 'autopost-ai' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Category', 'autopost-ai' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Topic Creation Date', 'autopost-ai' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Post Creation Date', 'autopost-ai' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Published Date', 'autopost-ai' ); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div style="text-align:center;margin-top:20px;">
                        <button id="autopost-load-more-created" class="button"><?php esc_html_e( 'Load More...', 'autopost-ai' ); ?></button>
                    </div>
                </div>
            </div>

            <!-- Tab: Post Creation Settings -->
            <?php
            $settings    = get_option( 'autopost_ai_post_settings', [] );
            $cta_enabled = $settings['cta_enabled'] ?? 'no';
            ?>
            <div id="autopost-post-settings" class="autopost-tab-content" style="display:none;">
                <form id="autopost-post-settings-form">
                    <?php wp_nonce_field( 'autopost_ai_nonce', 'autopost_ai_nonce' ); ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="autopost_time_frame_days"><?php esc_html_e( 'Post Creation Time Frame (days)', 'autopost-ai' ); ?></label></th>
                            <td>
                                <input type="number" id="autopost_time_frame_days" name="time_frame_days" style="width:60px;" min="1"
                                    value="<?php echo esc_attr( $settings['time_frame_days'] ?? 3 ); ?>" />
                            </td>
                        </tr>

                        <tr>
                            <th><label for="autopost_post_status"><?php esc_html_e( 'Post Status Upon Creation', 'autopost-ai' ); ?></label></th>
                            <td>
                                <select id="autopost_post_status" name="post_status">
                                    <option value="draft" <?php selected( $settings['post_status'] ?? 'draft', 'draft' ); ?>><?php esc_html_e( 'Draft', 'autopost-ai' ); ?></option>
                                    <option value="publish" <?php selected( $settings['post_status'] ?? 'draft', 'publish' ); ?>><?php esc_html_e( 'Published', 'autopost-ai' ); ?></option>
                                </select>
                                <p class="description" style="margin-top:6px; max-width: 600px;">
                                    <strong><?php esc_html_e( 'Note:', 'autopost-ai' ); ?></strong>
                                    <em><?php esc_html_e( 'Draft is recommended. If Pixabay does not return enough images, placeholders will be used. If you select Published, be vigilant—your post may go live with placeholders.', 'autopost-ai' ); ?></em>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Hide Post Title', 'autopost-ai' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="hide_title" id="autopost_hide_title" value="yes" <?php checked( $settings['hide_title'] ?? '', 'yes' ); ?> />
                                    <?php esc_html_e( 'Hide the post title (for themes that show it automatically)', 'autopost-ai' ); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th><label for="autopost_cta_enabled"><?php esc_html_e( 'Add Post Footer Call to Action?', 'autopost-ai' ); ?></label></th>
                            <td>
                                <select id="autopost_cta_enabled" name="cta_enabled">
                                    <option value="no" <?php selected( $cta_enabled, 'no' ); ?>><?php esc_html_e( 'No', 'autopost-ai' ); ?></option>
                                    <option value="yes" <?php selected( $cta_enabled, 'yes' ); ?>><?php esc_html_e( 'Yes', 'autopost-ai' ); ?></option>
                                </select>
                            </td>
                        </tr>

                        <tr id="autopost_cta_fields" style="<?php echo ( $cta_enabled === 'yes' ) ? '' : 'display:none;'; ?>">
                            <th><label for="autopost_cta_text"><?php esc_html_e( 'Call to Action Text', 'autopost-ai' ); ?></label></th>
                            <td>
                                <input type="text" id="autopost_cta_text" name="cta_text" style="width:100%;"
                                    value="<?php echo esc_attr( $settings['cta_text'] ?? '' ); ?>" />
                            </td>
                        </tr>

                        <tr class="autopost_cta_link_row" style="<?php echo ( $cta_enabled === 'yes' ) ? '' : 'display:none;'; ?>">
                            <th><label for="autopost_cta_link"><?php esc_html_e( 'Call to Action Link (URL)', 'autopost-ai' ); ?></label></th>
                            <td>
                                <input type="url" id="autopost_cta_link" name="cta_link" style="width:100%;"
                                    value="<?php echo esc_url( $settings['cta_link'] ?? '' ); ?>" />
                            </td>
                        </tr>

                        <tr>
                            <th><label for="autopost_notification_email"><?php esc_html_e( 'Email Address for Notifications', 'autopost-ai' ); ?></label></th>
                            <td>
                                <input type="email" id="autopost_notification_email" name="notification_email" style="width:250px;"
                                       value="<?php echo esc_attr( $settings['notification_email'] ?? '' ); ?>" />
                                <p class="description" style="margin-top:6px;">
                                    <?php esc_html_e( 'Optional: Receive an email whenever a post is automatically created.', 'autopost-ai' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <button type="submit" id="autopost-save-button" class="button button-primary"><?php esc_html_e( 'Save Settings', 'autopost-ai' ); ?></button>
                    <span id="autopost-save-status" style="margin-left:10px;"></span>
                </form>
            </div>

            <!-- OpenAI/ChatGPT Settings -->
            <div id="autopost-settings" class="autopost-tab-content" style="display:none;margin-top:20px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                    <h3 style="margin:0;font-size:1.09em;"><?php esc_html_e( 'OpenAI API Key', 'autopost-ai' ); ?></h3>
                    <a href="https://platform.openai.com/api-keys" target="_blank" class="button"><?php esc_html_e( 'Create API Key', 'autopost-ai' ); ?></a>
                    <span class="autopost-api-key-warning" style="color:#d90000; margin-left:10px; font-weight:bold; <?php echo esc_html( $warning_display ); ?>">
                        <?php esc_html_e( 'You must enter an API key for the AI function to work.', 'autopost-ai' ); ?><br/>
                        <span style="color:#000000; font-weight:normal; font-size:12px;">
                            <strong><?php esc_html_e( 'Note:', 'autopost-ai' ); ?></strong>
                            <?php esc_html_e( 'Requires a Plus or Pro OpenAI Account', 'autopost-ai' ); ?>
                        </span>
                    </span>
                </div>

                <div style="display:flex; align-items:flex-start; gap:18px; margin-top:22px;">
                    <!-- API Key Field -->
                    <div style="max-width:600px; flex:0 0 600px;">
                        <input type="text" name="autopost_ai_api_key" id="autopost_ai_api_key"
                            value="<?php echo esc_attr( $openai_api_key ); ?>"
                            style="width:100%;" autocomplete="off">
                        <button type="button" id="autopost-save-api-key" class="button button-primary" style="margin-top:8px;">
                            <?php esc_html_e( 'Save Key', 'autopost-ai' ); ?>
                        </button>
                        <span id="autopost-api-key-status" class="autopost-save-status" style="position:relative; top:8px;"></span>
                    </div>

                    <!-- Instructions Accordion -->
                    <div class="autopost-openai-instructions-accordion-wrap" style="min-width:230px;max-width:320px;margin:0;">
                        <div class="autopost-openai-instructions-header" style="cursor:pointer;user-select:none;display:flex;align-items:center;gap:8px;font-size:1.07em;font-weight:600;">
                            <span><?php esc_html_e( 'Instructions', 'autopost-ai' ); ?></span>
                            <span class="dashicons dashicons-arrow-down autopost-accordion-arrow" style="font-size:1.25em;transition:transform .2s;"></span>
                        </div>
                        <div class="autopost-openai-instructions-body" style="display:none;padding:13px 16px 10px 8px;background:#f8f8fc;border-radius:6px;border:1px solid #eee;margin-top:5px;">
                            <ol style="margin:0 0 0 16px;padding:0;line-height:1.7;">
                                <li><?php esc_html_e( 'Click "Create API Key"', 'autopost-ai' ); ?></li>
                                <li><?php esc_html_e( 'On the OpenAI page, click "Create new secret key"', 'autopost-ai' ); ?></li>
                                <li><?php esc_html_e( 'Enter a name, choose "Default Project," set Permissions to All, and click "Create secret key"', 'autopost-ai' ); ?></li>
                                <li><?php esc_html_e( 'Copy it, return here, paste into the field, and click "Save Key"', 'autopost-ai' ); ?></li>
                                <li><strong><em><?php esc_html_e( 'Make sure billing is enabled and your OpenAI account is funded or your API key will not work!', 'autopost-ai' ); ?></em></strong></li>
                            </ol>
                        </div>
                    </div>
                </div>

                <h3 style="margin-bottom:4px; font-size:1.09em; margin-top:20px;">
                    <?php esc_html_e( 'ChatGPT Pre-prompt', 'autopost-ai' ); ?>
                    <?php if ( $openai_prompt === $default_prompt ) echo '(Default)'; ?>
                </h3>
                <p style="margin-top:0;">
                    <?php
                    esc_html_e( 'This prompt helps guide ChatGPT when generating post topic ideas and full post content.', 'autopost-ai' );
                    echo '<br>';
                    esc_html_e( 'Use it to define the purpose of your website or business, as well as its tone, target audience, and brand personality.', 'autopost-ai' );
                    echo '<br>';
                    ?>
                    <em><strong><?php esc_html_e( 'Tip:', 'autopost-ai' ); ?></strong> <?php esc_html_e( 'Not sure what to write? Ask ChatGPT for help—just paste this into a chat and use what it gives you.', 'autopost-ai' ); ?></em>
                </p>

                <div style="width:600px; display:inline-block;">
                    <textarea name="autopost_ai_prompt" id="autopost_ai_prompt" style="width:100%;height:365px;"><?php echo esc_textarea( stripslashes( $openai_prompt ) ); ?></textarea>
                    <button type="button" id="autopost-save-prompt" class="button button-primary" style="margin-top:8px;"><?php esc_html_e( 'Save Prompt', 'autopost-ai' ); ?></button>
                    <span id="autopost-prompt-status" class="autopost-save-status" style="position:relative; top:8px;"></span>
                </div>

                <?php if ( $openai_prompt !== $default_prompt ) : ?>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field( 'autopost_ai_reset_prompt' ); ?>
                        <button type="submit" name="autopost_ai_reset_prompt" class="button"><?php esc_html_e( 'Reset to Default', 'autopost-ai' ); ?></button>
                    </form>
                <?php endif; ?>
            </div>

            <p style="margin-top: 40px; font-size: 12px; color: #666;">
                <?php esc_html_e( 'Images used in posts are provided by', 'autopost-ai' ); ?>
                <a href="https://pixabay.com" target="_blank" rel="noopener noreferrer"><strong><em>Pixabay</em></strong></a>
            </p>
        </div>
        <?php
    }
}
