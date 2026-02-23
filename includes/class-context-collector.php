<?php

/**
 * Builds rich, but concise, WordPress-aware context strings for images.
 */
class Auto_Alt_Text_Context_Collector {
    /**
     * Public entry point used from the generation-context filter.
     *
     * @param string $context        Existing context (e.g. from REST caller).
     * @param int    $attachment_id  Attachment ID.
     * @param string $generation_type Generation type (manual, upload, batch, api, etc.).
     * @return string Merged context string.
     */
    public function filter_generation_context($context, $attachment_id, $generation_type) {
        // Master toggle – if disabled, never add context.
        if (!get_option('aat_context_enabled', true)) {
            return is_string($context) ? $context : '';
        }

        $existing = is_string($context) ? trim($context) : '';
        $wp_context = $this->build_context_for_attachment($attachment_id, $generation_type);

        // If we have no additional context, just return whatever came in.
        if ($wp_context === '') {
            return $existing;
        }

        // If there was no caller-supplied context, use our WP-derived context alone.
        if ($existing === '') {
            return $wp_context;
        }

        // Merge REST/caller context with WordPress-derived context, caller first.
        $merged = $existing . ' | ' . __('Additional WordPress context:', 'wp-auto-alt-text') . ' ' . $wp_context;

        return $this->truncate($merged);
    }

    /**
     * Build a concise context string for an attachment.
     *
     * @param int    $attachment_id
     * @param string $generation_type
     * @return string
     */
    public function build_context_for_attachment($attachment_id, $generation_type = 'manual') {
        $attachment_id = absint($attachment_id);
        if (!$attachment_id) {
            return '';
        }

        $pieces = [
            'generation' => [],
            'parent'     => [],
            'image'      => [],
            'content'    => [],
            'taxonomies' => [],
        ];

        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return '';
        }

        $include_titles_captions = get_option('aat_context_include_titles_captions', true);
        $include_surrounding     = get_option('aat_context_include_surrounding', true);
        $include_taxonomies      = get_option('aat_context_include_taxonomies', true);

        // Always record generation type for debugging / future filters.
        $pieces['generation'][] = sprintf(
            'Generation type: %s',
            sanitize_text_field($generation_type)
        );

        // Resolve parent object (post/page/product), if any.
        $parent = null;
        $parent_id = 0;

        if ($attachment->post_parent) {
            $parent_id = (int) $attachment->post_parent;
            $parent = get_post($parent_id);
        }

        if ($include_titles_captions && $parent instanceof WP_Post) {
            $parent_title = trim(get_the_title($parent));
            if ($parent_title !== '') {
                $pieces['parent'][] = sprintf(
                    'Used in %s titled "%s".',
                    $parent->post_type,
                    $parent_title
                );
            }

            $parent_excerpt = $this->normalize_text($parent->post_excerpt ?: $parent->post_content);
            if ($parent_excerpt !== '') {
                $pieces['parent'][] = sprintf(
                    'Page/product summary: %s',
                    $this->truncate($parent_excerpt, 300)
                );
            }
        }

        // Image-level context: caption + existing alt text.
        if ($include_titles_captions) {
            $caption = wp_get_attachment_caption($attachment_id);
            if ($caption) {
                $pieces['image'][] = sprintf(
                    'Image caption: %s',
                    $this->truncate($this->normalize_text($caption), 200)
                );
            }

            $existing_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            if ($existing_alt) {
                $pieces['image'][] = sprintf(
                    'Existing alt text: %s',
                    $this->truncate($this->normalize_text($existing_alt), 200)
                );
            }
        }

        // Surrounding content: for standard posts/pages we try to pull text near where the image is used.
        if ($include_surrounding && $parent instanceof WP_Post) {
            $surrounding = $this->extract_surrounding_content($parent, $attachment_id);
            if ($surrounding !== '') {
                $pieces['content'][] = sprintf(
                    'Text near the image: %s',
                    $this->truncate($surrounding, 400)
                );
            }
        }

        // WooCommerce/product-specific enrichment.
        if ($include_surrounding && function_exists('wc_get_product')) {
            if ($parent instanceof WP_Post && $parent->post_type === 'product') {
                $product = wc_get_product($parent->ID);
                if ($product) {
                    $product_desc = $this->normalize_text($product->get_short_description() ?: $product->get_description());
                    if ($product_desc !== '') {
                        $pieces['content'][] = sprintf(
                            'Product description: %s',
                            $this->truncate($product_desc, 400)
                        );
                    }
                }
            }
        }

        // Taxonomies and attributes.
        if ($include_taxonomies && $parent instanceof WP_Post) {
            $tax_context = $this->build_taxonomy_context($parent);
            if ($tax_context !== '') {
                $pieces['taxonomies'][] = $tax_context;
            }
        }

        $payload = [
            'generation_type' => $generation_type,
            'attachment_id'   => $attachment_id,
            'parent_id'       => $parent ? $parent->ID : 0,
            'sections'        => $pieces,
        ];

        /**
         * Allows customization of the structured context payload before it's serialized.
         *
         * @param array  $payload        Structured payload with sections.
         * @param int    $attachment_id  Attachment ID.
         * @param string $generation_type Generation type.
         */
        $payload = apply_filters('auto_alt_text_context_payload', $payload, $attachment_id, $generation_type);

        $lines = [];

        if (!empty($payload['sections']['generation'])) {
            $lines[] = implode(' ', array_map('trim', (array) $payload['sections']['generation']));
        }
        if (!empty($payload['sections']['parent'])) {
            $lines[] = implode(' ', array_map('trim', (array) $payload['sections']['parent']));
        }
        if (!empty($payload['sections']['image'])) {
            $lines[] = implode(' ', array_map('trim', (array) $payload['sections']['image']));
        }
        if (!empty($payload['sections']['content'])) {
            $lines[] = implode(' ', array_map('trim', (array) $payload['sections']['content']));
        }
        if (!empty($payload['sections']['taxonomies'])) {
            $lines[] = implode(' ', array_map('trim', (array) $payload['sections']['taxonomies']));
        }

        $text = trim(implode(' ', array_filter($lines)));

        return $this->truncate($text);
    }

    /**
     * Extract surrounding text from a parent post around where an attachment is used.
     *
     * @param WP_Post $parent
     * @param int     $attachment_id
     * @return string
     */
    private function extract_surrounding_content($parent, $attachment_id) {
        if (!($parent instanceof WP_Post)) {
            return '';
        }

        $content = (string) $parent->post_content;
        if ($content === '') {
            return '';
        }

        $needle_variants = [
            'wp-image-' . $attachment_id,
            'attachment_' . $attachment_id,
            'attachment-id="' . $attachment_id . '"',
            'data-id="' . $attachment_id . '"',
        ];

        $position = -1;
        foreach ($needle_variants as $needle) {
            $pos = strpos($content, $needle);
            if ($pos !== false) {
                $position = $pos;
                break;
            }
        }

        if ($position === -1) {
            return '';
        }

        $window = 800;
        $start = max(0, $position - (int) ($window / 2));
        $snippet = substr($content, $start, $window);

        return $this->normalize_text($snippet);
    }

    /**
     * Build human-readable taxonomy context for a post/product.
     *
     * @param WP_Post $parent
     * @return string
     */
    private function build_taxonomy_context($parent) {
        if (!($parent instanceof WP_Post)) {
            return '';
        }

        $post_type = $parent->post_type;
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        if (empty($taxonomies)) {
            return '';
        }

        $parts = [];

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($parent->ID, $taxonomy->name);
            if (empty($terms) || is_wp_error($terms)) {
                continue;
            }

            $names = wp_list_pluck($terms, 'name');
            $names = array_slice($names, 0, 5);

            if (!empty($names)) {
                $parts[] = sprintf(
                    '%s: %s',
                    $taxonomy->label,
                    implode(', ', $names)
                );
            }
        }

        if (empty($parts)) {
            return '';
        }

        return 'Taxonomy context: ' . implode(' | ', $parts);
    }

    /**
     * Normalize a blob of text to plain, single-spaced text.
     *
     * @param string $text
     * @return string
     */
    private function normalize_text($text) {
        $text = wp_strip_all_tags((string) $text, true);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Truncate a string to a maximum length, adding ellipsis if needed.
     *
     * @param string $text
     * @param int    $max_length
     * @return string
     */
    private function truncate($text, $max_length = 800) {
        $text = (string) $text;
        if (strlen($text) <= $max_length) {
            return $text;
        }

        $truncated = substr($text, 0, $max_length - 3);

        return rtrim($truncated) . '...';
    }
}

