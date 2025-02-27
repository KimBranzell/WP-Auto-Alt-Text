<?php
class Auto_Alt_Text_Ajax_Handler {
    private const NONCE_ACTION = 'auto_alt_text_nonce';
    private const LOG_TYPE = 'debug';
    private const BATCH_NONCE_ACTION = 'auto_alt_text_batch_nonce';

    private const ERROR_MISSING_ID = 'Missing attachment ID';
    private const ERROR_INVALID_ATTACHMENT = 'Invalid attachment ID';
    private const ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    /**
     * Registers AJAX actions for generating alt text for attachments and processing image batches.
     */
    public function __construct() {
        add_action('wp_ajax_generate_alt_text_for_attachment', [$this, 'generate_alt_text_for_attachment']);
        add_action('wp_ajax_process_image_batch', [$this, 'process_image_batch']);
        add_action('wp_ajax_regenerate_alt_text_with_feedback', [$this, 'regenerate_alt_text_with_feedback']);
    }

    /**
     * Generates alt text for an attachment using the OpenAI API.
     *
     * This function is called via an AJAX request to generate alt text for a specific attachment.
     * It checks the user's permissions, retrieves the attachment's image URL, and then uses the
     * Auto_Alt_Text_OpenAI class to generate the alt text. The generated alt text is then
     * returned as a JSON response.
     *
     * @return void
     */
    public function generate_alt_text_for_attachment(): void {
        if (!isset($_POST['attachment_id'])) {
            wp_send_json_error(['message' => self::ERROR_MISSING_ID]);
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => self::ERROR_INSUFFICIENT_PERMISSIONS]);
            return;
        }

        Auto_Alt_Text_Logger::log("AJAX request received", self::LOG_TYPE, [
            'attachment_id' => $_POST['attachment_id'] ?? null
        ]);

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $attachment_id = intval($_POST['attachment_id']);
        $preview_mode = isset($_POST['preview']) && $_POST['preview'] === 'true';
        $image_url = wp_get_attachment_url($attachment_id);

        if (!$image_url) {
            wp_send_json_error(['message' => self::ERROR_INVALID_ATTACHMENT]);
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

    /**
     * Processes a batch of image attachments and generates alt text for them using the OpenAI API.
     *
     * This function is called via an AJAX request to generate alt text for a batch of image attachments.
     * It first checks the user's permissions to ensure they have the necessary privileges to upload files.
     * It then retrieves the IDs of the images to be processed from the request, and for each image, it
     * generates the alt text using the Auto_Alt_Text_OpenAI class. The generated alt text is then
     * returned as a JSON response.
     */
    public function process_image_batch() {
        check_ajax_referer(self::BATCH_NONCE_ACTION, 'nonce');

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
    /**
     * Applies the generated alt text to the specified image attachment.
     *
     * This function is called via an AJAX request to update the alt text for a specific image attachment.
     * It first checks the security token to ensure the request is valid. It then retrieves the necessary
     * parameters from the request, including the attachment ID, the generated alt text, and whether the
     * text was manually edited. It updates the alt text for the attachment and also updates the statistics
     * record for the generated text. Finally, it returns a success response with the updated alt text.
     */
    public function apply_alt_text() {
        try {
            if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
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

    /**
     * Regenerates alt text based on user feedback.
     *
     * This method takes the original alt text and user feedback to generate
     * an improved version using more specific instructions to OpenAI.
     */
    public function regenerate_alt_text_with_feedback() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => self::ERROR_INSUFFICIENT_PERMISSIONS]);
            return;
        }

        if (!isset($_POST['attachment_id']) || !isset($_POST['improvement_type'])) {
            wp_send_json_error(['message' => 'Missing required parameters']);
            return;
        }

        $attachment_id = intval($_POST['attachment_id']);
        $improvement_type = sanitize_text_field($_POST['improvement_type']);
        $custom_feedback = isset($_POST['custom_feedback']) ? sanitize_textarea_field($_POST['custom_feedback']) : '';
        $original_alt_text = isset($_POST['original_alt_text']) ? sanitize_textarea_field($_POST['original_alt_text']) : '';

        $image_url = wp_get_attachment_url($attachment_id);

        if (!$image_url) {
            wp_send_json_error(['message' => self::ERROR_INVALID_ATTACHMENT]);
            return;
        }

        Auto_Alt_Text_Logger::log("Alt text regeneration requested", "info", [
            'attachment_id' => $attachment_id,
            'improvement_type' => $improvement_type,
            'custom_feedback' => $custom_feedback,
            'original_text' => $original_alt_text
        ]);

        $openai = new Auto_Alt_Text_OpenAI();
        $improved_alt_text = $openai->regenerate_alt_text_with_feedback(
            $image_url,
            $attachment_id,
            $improvement_type,
            $custom_feedback,
            $original_alt_text
        );

        if ($improved_alt_text) {
            wp_send_json_success(['alt_text' => $improved_alt_text]);
        } else {
            wp_send_json_error(['message' => $openai->get_last_error()]);
        }
    }
}
