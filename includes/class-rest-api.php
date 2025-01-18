<?php

class Auto_Alt_Text_REST_API {
    private $openai;
    private $namespace = 'wp-auto-alt-text/v1';

    public function __construct() {
        $this->openai = new Auto_Alt_Text_OpenAI();
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Registers the REST API routes for the Auto Alt Text plugin.
     *
     * Includes two routes:
     * - '/generate': Generates alt text for a single image attachment.
     * - '/batch': Generates alt text for a batch of image attachments.
     *
     * Both routes require the 'upload_files' capability to access.
     */
    public function register_routes() {
        register_rest_route($this->namespace, '/generate', [
            'methods' => 'POST',
            'callback' => [$this, 'generate_alt_text'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'attachment_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && wp_attachment_is_image($param);
                    }
                ]
            ]
        ]);

        register_rest_route($this->namespace, '/batch', [
            'methods' => 'POST',
            'callback' => [$this, 'process_batch'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'attachment_ids' => [
                    'required' => true,
                    'type' => 'array'
                ]
            ]
        ]);
    }

    /**
     * Checks if the current user has the 'upload_files' capability, which is required to access the REST API routes.
     *
     * @return bool True if the current user has the 'upload_files' capability, false otherwise.
     */
    public function check_permission() {
        return current_user_can('upload_files');
    }

    /**
     * Generates alt text for a single image attachment using the OpenAI API.
     *
     * @param WP_REST_Request $request The REST API request object, containing the 'attachment_id' parameter.
     * @return WP_REST_Response A response object with the generated alt text or an error message.
     */
    public function generate_alt_text($request) {
        $attachment_id = $request->get_param('attachment_id');
        $image_url = wp_get_attachment_url($attachment_id);

        $alt_text = $this->openai->generate_alt_text($image_url, $attachment_id, 'api');

        if ($alt_text) {
            return new WP_REST_Response([
                'success' => true,
                'alt_text' => $alt_text,
                'attachment_id' => $attachment_id
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => $this->openai->get_last_error()
        ], 400);
    }

    /**
     * Processes a batch of image attachment IDs, generates alt text for each image using the OpenAI API, and returns the results.
     *
     * @param WP_REST_Request $request The REST API request object, containing the 'attachment_ids' parameter.
     * @return WP_REST_Response A response object with the generated alt text for each image or an error message.
     */
    public function process_batch($request) {
        $attachment_ids = $request->get_param('attachment_ids');
        $results = [];

        foreach ($attachment_ids as $id) {
            $image_path = get_attached_file($id);

            // Check cache first
            $cached_text = Auto_Alt_Text_Cache_Manager::get_cached_response($image_path);
            if ($cached_text !== false) {
                $results[$id] = [
                    'success' => true,
                    'alt_text' => $cached_text,
                    'cached' => true
                ];
                continue;
            }

            if (wp_attachment_is_image($id)) {
                $image_url = wp_get_attachment_url($id);
                $alt_text = $this->openai->generate_alt_text($image_url, $id, 'api_batch');
                $results[$id] = $alt_text;
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'results' => $results
        ], 200);
    }
}
