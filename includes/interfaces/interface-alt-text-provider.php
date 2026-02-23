<?php
/**
 * Interface for alt text generation providers (e.g. OpenAI, OpenAI-compatible endpoints).
 */
interface Auto_Alt_Text_Provider {
    /**
     * Generate alt text for an image.
     *
     * @param string $image_source    Image URL or path.
     * @param int    $attachment_id   Attachment post ID.
     * @param string $generation_type Type: manual, upload, batch, api, etc.
     * @param bool   $preview_mode    If true, do not save or cache.
     * @param string $context        Optional context (e.g. post title) for relevance.
     * @return string|null Generated alt text or null on failure.
     */
    public function generate_alt_text($image_source, $attachment_id, $generation_type = 'manual', $preview_mode = false, $context = '');

    /**
     * Test that the provider is configured and reachable.
     *
     * @return bool True if the connection test succeeds.
     */
    public function test_api_key();

    /**
     * Get the last error message from the provider.
     *
     * @return string Last error message or empty string.
     */
    public function get_last_error();
}
