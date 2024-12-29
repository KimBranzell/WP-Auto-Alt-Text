<?php

class Auto_Alt_Text_REST_API {
    private $openai;
    private $namespace = 'wp-auto-alt-text/v1';

    public function __construct() {
        $this->openai = new Auto_Alt_Text_OpenAI();
        add_action('rest_api_init', [$this, 'register_routes']);
    }

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

    public function check_permission() {
        return current_user_can('upload_files');
    }

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

    public function process_batch($request) {
        $attachment_ids = $request->get_param('attachment_ids');
        $results = [];

        foreach ($attachment_ids as $id) {
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
