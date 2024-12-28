<?php
class Auto_Alt_Text_Ajax_Handler {
    public function __construct() {
        add_action('wp_ajax_generate_alt_text_for_attachment', [$this, 'generate_alt_text_for_attachment']);
        add_action('wp_ajax_process_image_batch', [$this, 'process_image_batch']);
    }

    public function generate_alt_text_for_attachment() {
        check_ajax_referer('auto_alt_text_nonce', 'nonce');

        $attachment_id = intval($_POST['attachment_id']);
        $preview_mode = isset($_POST['preview']) && $_POST['preview'] === 'true';
        $image_url = wp_get_attachment_url($attachment_id);

        if (!$image_url) {
            wp_send_json_error(['message' => 'Invalid attachment ID']);
            return;
        }

        $openai = new Auto_Alt_Text_OpenAI();
        $alt_text = $openai->generate_alt_text($image_url, $attachment_id, 'manual', $preview_mode);

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
    public function apply_alt_text() {
        try {
            if (!check_ajax_referer('auto_alt_text_nonce', 'nonce', false)) {
                throw new Exception('Invalid security token');
            }

            $attachment_id = intval($_POST['attachment_id']);
            $alt_text = sanitize_text_field($_POST['alt_text']);
            $original_text = sanitize_text_field($_POST['original_text']);
            $is_edited = isset($_POST['is_edited']) && $_POST['is_edited'] === '1';
            $tokens_used = isset($_POST['tokens_used']) ? intval($_POST['tokens_used']) : 0;

            if (!$attachment_id || !$alt_text) {
                throw new Exception('Missing required parameters');
            }

            if (!current_user_can('edit_post', $attachment_id)) {
                throw new Exception('Permission denied');
            }

            $result = update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);

            if ($result === false) {
                throw new Exception('Failed to update alt text');
            }

            // Update the existing statistics record
            global $wpdb;
            $table_name = $wpdb->prefix . 'auto_alt_text_stats';

            $wpdb->query($wpdb->prepare(
                "UPDATE {$table_name}
                 SET is_applied = 1,
                     is_edited = %d,
                     edited_text = %s
                 WHERE image_id = %d
                 AND generated_text = %s
                 AND generation_time = (
                     SELECT max_time FROM (
                         SELECT MAX(generation_time) as max_time
                         FROM {$table_name}
                         WHERE image_id = %d
                     ) as sub
                 )",
                $is_edited,
                $is_edited ? $alt_text : null,
                $attachment_id,
                $original_text,
                $attachment_id
            ));

            wp_send_json_success([
                'message' => 'Alt text updated successfully',
                'alt_text' => $alt_text,
                'attachment_id' => $attachment_id
            ]);

        } catch (Exception $e) {
            error_log('[Auto Alt Text Error] ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
}
