<?php
class Auto_Alt_Text_Batch_Processor {
    private $batch_size = 10;
    private $openai;

    public function __construct() {
        $this->openai = new Auto_Alt_Text_OpenAI();
    }

    /**
     * Processes a batch of attachment IDs to generate and update the alt text for the corresponding images.
     *
     * @param array $attachment_ids The IDs of the attachments to process.
     * @return array The generated alt text for each processed attachment.
     */
    public function process_batch($attachment_ids) {
        $results = [];
        $chunks = array_chunk($attachment_ids, $this->batch_size);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $attachment_id) {
                $image_url = wp_get_attachment_url($attachment_id);
                $alt_text = $this->openai->generate_alt_text($image_url);

                if ($alt_text) {
                    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
                    $results[$attachment_id] = $alt_text;
                }
            }
            // Add a small delay between chunks to prevent API rate limits
            sleep(2);
        }
        return $results;
    }
}
