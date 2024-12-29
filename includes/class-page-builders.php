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

    public function enhance_elementor_carousel($caption, $attachment_id) {
        $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if (empty($alt_text)) {
            $alt_text = $this->process_elementor_image($attachment_id);
        }
        return $alt_text ?: $caption;
    }

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

    public function process_beaver_image($data) {
        if (!empty($data['alt'])) {
            return $data;
        }

        $alt_text = $this->openai->generate_alt_text(
            $data['url'],
            $data['id'],
            'beaver_builder'
        );

        if ($alt_text) {
            $data['alt'] = $alt_text;
        }

        return $data;
    }

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
