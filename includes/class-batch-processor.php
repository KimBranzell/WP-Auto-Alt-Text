<?php
class Auto_Alt_Text_Batch_Processor {
    private const NONCE_ACTION = 'wp_auto_alt_text_nonce';
    private $batch_size;
    private $openai;

    /**
     * Initializes the batch processor with OpenAI service and configuration.
     *
     * @param Auto_Alt_Text_OpenAI $openai OpenAI service instance
     * @param int $batch_size Number of items to process in each batch
     */
    public function __construct(Auto_Alt_Text_OpenAI $openai, $batch_size = 10) {
        $this->openai = $openai;
        $this->setBatchSize($batch_size);
    }

    /**
     * Sets the batch size with validation
     *
     * @param int $size Batch size to set
     * @return void
     * @throws InvalidArgumentException
     */
    public function setBatchSize($size) {
        if (!is_int($size) || $size < 1) {
            throw new InvalidArgumentException('Batch size must be a positive integer');
        }
        $this->batch_size = $size;
    }

    /**
     * Processes a batch of attachment IDs, generating alternative text for each attachment.
     *
     * @param array $attachment_ids The IDs of the attachments to process.
     * @return array The generated alternative text for each attachment, keyed by attachment ID.
     * @throws InvalidArgumentException
     */
    public function process_batch($attachment_ids) {
        if (!is_array($attachment_ids) || empty($attachment_ids)) {
            throw new InvalidArgumentException('Attachment IDs must be a non-empty array');
        }

        Auto_Alt_Text_Logger::log("Starting batch processing", "info", [
            'batch_size' => count($attachment_ids)
        ]);

        // Verify nonce
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }

        // Verify user capabilities
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
        }

        try {
            return $this->processBatchItems($attachment_ids);
        } catch (Exception $e) {
            Auto_Alt_Text_Logger::log("Batch processing error", "error", [
                'message' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Internal method to process batch items
     *
     * @param array $attachment_ids
     * @return array
     */
    private function processBatchItems($attachment_ids) {
        $results = [];
        $chunks = array_chunk($attachment_ids, $this->batch_size);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $attachment_id) {
                Auto_Alt_Text_Logger::log("Processing attachment", "debug", ['id' => $attachment_id]);

                try {
                    $image_url = wp_get_attachment_url($attachment_id);
                    if ($image_url) {
                        $alt_text = $this->openai->generate_alt_text($image_url, $attachment_id);
                        if ($alt_text) {
                            $results[$attachment_id] = $alt_text;
                        }
                    }
                } catch (Exception $e) {
                    Auto_Alt_Text_Logger::log("Error processing attachment", "error", [
                        'id' => $attachment_id,
                        'message' => $e->getMessage()
                    ]);
                    continue;
                }
            }
            sleep(2);
        }
        return $results;
    }
}
