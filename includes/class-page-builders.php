<?php

class Auto_Alt_Text_Page_Builders {
    private $openai;
    private $statistics;

    public function __construct() {
        $this->openai = new Auto_Alt_Text_OpenAI();
        $this->statistics = new Auto_Alt_Text_Statistics();

        // Elementor
        add_action('elementor/images/before_insert_attachment', [$this, 'process_elementor_image']);
        add_filter('elementor/image_carousel/get_image_caption', [$this, 'enhance_elementor_carousel'], 10, 2);

        // Divi
        add_filter('et_pb_gallery_image_alt', [$this, 'process_divi_gallery_image'], 10, 2);
        add_filter('et_pb_image_alt', [$this, 'process_divi_image'], 10, 2);

        // Beaver Builder
        add_filter('fl_builder_photo_data', [$this, 'process_beaver_image']);

        // WPBakery
        add_filter('vc_single_image_html', [$this, 'process_wpbakery_image'], 10, 2);
    }

    /**
     * Generates and updates the alt text for an Elementor image attachment.
     *
     * @param int $attachment_id The ID of the image attachment.
     * @return string The generated alt text.
     */
    public function process_elementor_image($attachment_id) {
        $image_url = wp_get_attachment_url($attachment_id);
        $alt_text = $this->openai->generate_alt_text(
            $image_url,
            $attachment_id,
            'elementor'
        );

        if ($alt_text) {
            update_post_meta($attachment_id, '_elementor_alt_text', $alt_text);
        }

        return $alt_text;
    }

    /**
     * Enhances the alt text for an Elementor image carousel.
     *
     * If the image attachment has no alt text, this function generates and returns the alt text.
     * Otherwise, it returns the existing alt text or the image caption.
     *
     * @param string $caption The image caption.
     * @param int $attachment_id The ID of the image attachment.
     * @return string The enhanced alt text.
     */
    public function enhance_elementor_carousel($caption, $attachment_id) {
        $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if (empty($alt_text)) {
            $alt_text = $this->process_elementor_image($attachment_id);
        }
        return $alt_text ?: $caption;
    }

    /**
     * Generates and updates the alt text for a Divi image.
     *
     * If the image attachment already has alt text, this function returns the existing alt text.
     * Otherwise, it generates new alt text using the OpenAI API and returns the generated text.
     *
     * @param string $alt_text The existing alt text for the image.
     * @param int $attachment_id The ID of the image attachment.
     * @return string The generated or existing alt text.
     */
    public function process_divi_image($alt_text, $attachment_id) {
        if (!empty($alt_text)) {
            return $alt_text;
        }

        $image_url = wp_get_attachment_url($attachment_id);
        return $this->openai->generate_alt_text(
            $image_url,
            $attachment_id,
            'divi'
        );
    }

    /**
     * Processes the image data for a Beaver Builder image.
     *
     * If the image data does not have a valid URL or ID, the function returns the original data.
     * If the image data has an empty alt text and a valid ID, the function generates new alt text
     * using the OpenAI API and updates the image data with the generated alt text.
     *
     * @param array $data The image data, including the URL and ID.
     * @return array The processed image data, with the alt text updated if necessary.
     */
    public function process_beaver_image($data) {
        // Early return if data is not valid
        if (!is_array($data) || empty($data)) {
            return $data;
        }

        // Check if required fields exist
        if (!isset($data['url']) || !isset($data['id'])) {
            return $data;
        }

        // Only process if alt is empty and we have a valid ID
        if (empty($data['alt']) && !empty($data['id'])) {
            $alt_text = $this->openai->generate_alt_text(
                $data['url'],
                $data['id'],
                'beaver_builder'
            );

            if ($alt_text) {
                $data['alt'] = $alt_text;
            }
        }

        return $data;
    }

    /**
     * Processes the image data for a WPBakery image.
     *
     * If the image settings do not contain a valid image ID, the function returns the original HTML.
     * If the image does not have an alt text, the function generates new alt text using the OpenAI API
     * and updates the HTML with the generated alt text.
     *
     * @param string $html The HTML containing the image.
     * @param array $settings The image settings, including the ID.
     * @return string The processed HTML, with the alt text updated if necessary.
     */
    public function process_wpbakery_image($html, $settings) {
        if (empty($settings['image'])) {
            return $html;
        }

        $attachment_id = $settings['image'];
        $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

        if (empty($alt_text)) {
            $image_url = wp_get_attachment_url($attachment_id);
            $alt_text = $this->openai->generate_alt_text(
                $image_url,
                $attachment_id,
                'wpbakery'
            );
        }

        if ($alt_text) {
            $html = str_replace('alt=""', 'alt="' . esc_attr($alt_text) . '"', $html);
        }

        return $html;
    }
}
