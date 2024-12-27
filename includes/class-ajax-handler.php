<?php
class Auto_Alt_Text_Ajax_Handler {
    public function __construct() {
        add_action('wp_ajax_generate_alt_text_for_attachment', [$this, 'generate_alt_text_for_attachment']);
        add_action('wp_ajax_process_image_batch', [$this, 'process_image_batch']);
    }

    public function generate_alt_text_for_attachment() {
        check_ajax_referer('auto_alt_text_nonce', 'nonce');

        $attachment_id = intval($_POST['attachment_id']);
        $image_url = wp_get_attachment_url($attachment_id);

        if (!$image_url) {
            wp_send_json_error(['message' => 'Invalid attachment ID']);
            return;
        }

        $openai = new Auto_Alt_Text_OpenAI();
        $alt_text = $openai->generate_alt_text($image_url, $attachment_id);

        if ($alt_text) {
            wp_send_json_success(['alt_text' => $alt_text]);
        } else {
            wp_send_json_error(['message' => $openai->get_last_error()]);
        }
    }

    public function process_image_batch() {
        check_ajax_referer('auto_alt_text_batch_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $ids = json_decode(stripslashes($_POST['ids']));
        if (!is_array($ids)) {
            wp_send_json_error('Invalid input');
            return;
        }

        $openai = new Auto_Alt_Text_OpenAI();
        $results = [];

        foreach ($ids as $id) {
            $image_url = wp_get_attachment_url($id);
            if ($image_url) {
                $alt_text = $openai->generate_alt_text($image_url, $id);
                if ($alt_text) {
                    $results[$id] = $alt_text;
                }
            }
        }

        wp_send_json_success($results);
    }
}
