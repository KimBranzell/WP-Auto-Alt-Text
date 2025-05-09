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
