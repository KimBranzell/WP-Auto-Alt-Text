<?php
class Auto_Alt_Text_Admin {
    private const SCRIPT_HANDLE = 'auto-alt-text-admin-js';
    private const SCRIPT_PATH = 'js/app.js';
    private const STYLE_HANDLE = 'auto_alt_text_css';

    /**
     * Constructor for the admin class.
    */
    public function __construct() {
        add_action('bulk_actions-upload', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_actions'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAutoAltTextScript']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);
        add_filter('manage_media_columns', [$this, 'add_alt_status_column']);
        add_action('manage_media_custom_column', [$this, 'render_alt_status_column'], 10, 2);
    }

    /**
     * Adds an "Alt text" column to the Media Library list view.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public function add_alt_status_column($columns) {
        $columns['aat_alt_status'] = __('Alt text', 'wp-auto-alt-text');
        return $columns;
    }

    /**
     * Renders the Alt text status for a media item: —, Missing, Auto, or Manual.
     * Missing items get a "Generate" link to the edit screen.
     *
     * @param string $column_name Column name.
     * @param int    $post_id     Attachment ID.
     */
    public function render_alt_status_column($column_name, $post_id) {
        if ($column_name !== 'aat_alt_status') {
            return;
        }
        if (!wp_attachment_is_image($post_id)) {
            echo '—';
            return;
        }
        $alt = get_post_meta($post_id, '_wp_attachment_image_alt', true);
        if (trim((string) $alt) === '') {
            $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
            echo '<span class="aat-status aat-status-missing">' . esc_html__('Missing', 'wp-auto-alt-text') . '</span>';
            echo ' <a href="' . esc_url($edit_url) . '" class="aat-generate-link">' . esc_html__('Generate', 'wp-auto-alt-text') . '</a>';
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'auto_alt_text_stats';
        $has_auto = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE image_id = %d AND generation_type IN ('upload','batch','api','api_batch','woocommerce','elementor','divi','beaver_builder','wpbakery') LIMIT 1",
            $post_id
        ));
        if ($has_auto) {
            echo '<span class="aat-status aat-status-auto">' . esc_html__('Auto', 'wp-auto-alt-text') . '</span>';
        } else {
            echo '<span class="aat-status aat-status-manual">' . esc_html__('Manual', 'wp-auto-alt-text') . '</span>';
        }
    }

    /**
     * Adds a 'Generate Alt Text' bulk action to the media library.
     *
     * @param array $bulk_actions The existing bulk actions.
     * @return array The updated bulk actions array.
     */
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['generate_alt_text'] = __('Generate Alt Text', 'wp-auto-alt-text');
        return $bulk_actions;
    }

    /**
     * Handles the bulk action for generating alt text for selected media items.
     *
     * This method is called when the 'Generate Alt Text' bulk action is selected in the media library.
     * It checks if the selected action is 'generate_alt_text', enqueues the necessary script, and
     * returns a redirect URL with query parameters to indicate the number of items to be processed
     * and the IDs of the selected media items.
     *
     * @param string $redirect_to The original redirect URL.
     * @param string $doaction The selected bulk action.
     * @param array $post_ids The IDs of the selected media items.
     * @return string The updated redirect URL with query parameters.
     */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'generate_alt_text') {
            return $redirect_to;
        }

        $this->enqueueAutoAltTextScript();

        return add_query_arg([
            'bulk_generate_alt_text' => count($post_ids),
            'bulk_ids' => implode(',', $post_ids),
            'processed' => 0,
            'failed' => 0
        ], $redirect_to);
    }

    /**
     * Enqueues the plugin's CSS styles for the admin area.
     *
     * This method is responsible for registering and enqueuing the plugin's CSS styles,
     * which are used to style the admin interface. It sets the handle and the URL of the
     * CSS file to be loaded.
     */
    public function enqueueStyles(): void {
        wp_enqueue_style(
            self::STYLE_HANDLE,
            plugin_dir_url(dirname(__FILE__)) . 'css/style.css'
        );
    }

    /**
     * Enqueues the Auto Alt Text script for the admin area.
     *
     * This method is responsible for registering and enqueuing the plugin's JavaScript
     * script, which is used for handling the batch processing of images for automatic
     * alt text generation. It sets up the necessary script dependencies, version, and
     * module type. It also localizes the script with AJAX-related data, such as the
     * AJAX URL and a nonce for security.
     */
    public function enqueueAutoAltTextScript(): void {
        $scriptUrl = plugin_dir_url(__FILE__) . self::SCRIPT_PATH;
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $scriptUrl,
            [],
            '1.0.0',
            true
        );

        wp_script_add_data(self::SCRIPT_HANDLE, 'type', 'module');

        wp_localize_script(self::SCRIPT_HANDLE, 'autoAltTextData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('auto_alt_text_batch_nonce'),
            'brandTonalityEnabled' => (bool) get_option('wp_auto_alt_text_enable_brand_tonality', false),
            'strings' => [
                'previewTitle' => __('Preview alt text', 'wp-auto-alt-text'),
                'cachedBadge' => __('Cached', 'wp-auto-alt-text'),
                'notSatisfied' => __('Not satisfied? Improve this alt text', 'wp-auto-alt-text'),
                'feedbackNote' => __('This will count as a new request and may incur cost.', 'wp-auto-alt-text'),
                'moreDescriptive' => __('More descriptive', 'wp-auto-alt-text'),
                'moreConcise' => __('More concise', 'wp-auto-alt-text'),
                'moreAccessible' => __('More accessible', 'wp-auto-alt-text'),
                'betterSeo' => __('SEO-friendly', 'wp-auto-alt-text'),
                'technicalAccuracy' => __('Technical accuracy', 'wp-auto-alt-text'),
                'brandVoice' => __('Brand voice', 'wp-auto-alt-text'),
                'customFeedbackPlaceholder' => __('Or enter your own feedback...', 'wp-auto-alt-text'),
                'sendCustomFeedback' => __('Send custom feedback', 'wp-auto-alt-text'),
                'apply' => __('Apply', 'wp-auto-alt-text'),
                'regenerateAltText' => __('Generate new alt text', 'wp-auto-alt-text'),
                'cancel' => __('Cancel', 'wp-auto-alt-text'),
                'applying' => __('Applying...', 'wp-auto-alt-text'),
                'confirmCleanup' => __('Are you sure you want to remove all generation records for deleted images?', 'wp-auto-alt-text'),
                'generating' => __('Generating...', 'wp-auto-alt-text'),
                'generateAltTextForSelected' => __('Generate Alt Text for Selected', 'wp-auto-alt-text'),
                'selectImagesFirst' => __('Please select images first', 'wp-auto-alt-text'),
                'regenerating' => __('Generating alt text from feedback...', 'wp-auto-alt-text'),
                'newAltTextGenerated' => __('New alt text has been generated', 'wp-auto-alt-text'),
                'improveFailed' => __('Failed to improve alt text:', 'wp-auto-alt-text'),
                'errorProcessingFeedback' => __('Error processing your feedback. Please try again.', 'wp-auto-alt-text'),
                'enterFeedback' => __('Please enter your feedback before submitting.', 'wp-auto-alt-text'),
            ],
        ]);
    }

    /**
     * Registers the AJAX handler for processing image batches.
     *
     * This method adds an action hook for the 'wp_ajax_process_image_batch' AJAX
     * action, which will call the 'handle_batch_processing' method when the AJAX
     * request is made. This allows the plugin to handle the processing of a batch
     * of images for automatic alt text generation.
     */
    public function register_ajax_handlers() {
        add_action('wp_ajax_process_image_batch', [$this, 'handle_batch_processing']);
    }

    /**
     * Handles the processing of a batch of images for automatic alt text generation.
     *
     * This method is called via AJAX when the user initiates a batch processing operation.
     * It checks the AJAX nonce, retrieves the attachment IDs from the request, creates a
     * new Auto_Alt_Text_Batch_Processor instance, and processes the batch. The results
     * are then sent back to the client as a JSON response.
     */
    public function handle_batch_processing() {
        check_ajax_referer('auto_alt_text_batch_nonce', 'nonce');

        $attachment_ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
        $openai = new Auto_Alt_Text_OpenAI();
        $batch_processor = new Auto_Alt_Text_Batch_Processor($openai, 10);
        $results = $batch_processor->process_batch($attachment_ids);

        wp_send_json_success($results);
    }
}
