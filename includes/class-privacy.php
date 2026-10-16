<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Privacy integration: policy text and a personal-data exporter for the alt
 * texts of attachments the requester uploaded.
 */
class Auto_Alt_Text_Privacy {
    public const EXPORTER_ID = 'wp-auto-alt-text';
    private const PER_PAGE = 100;

    public static function register() {
        add_action('admin_init', [self::class, 'add_policy_content']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
    }

    public static function add_policy_content() {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content('WP Auto Alt Text', wp_kses_post(wpautop(self::get_policy_text())));
        }
    }

    public static function get_policy_text() {
        return __('When alt text is generated, this site sends a copy of the image and its file name to OpenAI for analysis. Generated alt texts are stored with the image in the Media Library. Generation records (image ID, generated text, the user who triggered it and token usage) are stored in this site\'s database.', 'WP-Auto-Alt-Text');
    }

    public static function register_exporter($exporters) {
        $exporters[self::EXPORTER_ID] = [
            'exporter_friendly_name' => __('WP Auto Alt Text', 'WP-Auto-Alt-Text'),
            'callback'               => [self::class, 'export'],
        ];
        return $exporters;
    }

    /**
     * Core exporter callback. Receives ($email_address, $page) and must return
     * ['data' => array, 'done' => bool] (wp-admin/includes/ajax-actions.php).
     */
    public static function export($email_address, $page = 1) {
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }

        $attachment_ids = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'author'         => (int) $user->ID,
            'fields'         => 'ids',
            'posts_per_page' => self::PER_PAGE,
            'paged'          => max(1, (int) $page),
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'lang'           => '', // Polylang: every language, not just the current one.
        ]);

        $data = [];
        foreach ($attachment_ids as $attachment_id) {
            $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            if ('' === $alt_text) {
                continue;
            }
            $data[] = [
                'group_id'    => self::EXPORTER_ID,
                'group_label' => __('Image alt texts', 'WP-Auto-Alt-Text'),
                'item_id'     => 'aat-attachment-' . $attachment_id,
                'data'        => [
                    ['name' => __('Attachment ID', 'WP-Auto-Alt-Text'), 'value' => (string) $attachment_id],
                    ['name' => __('Alt text', 'WP-Auto-Alt-Text'), 'value' => $alt_text],
                ],
            ];
        }

        return ['data' => $data, 'done' => count($attachment_ids) < self::PER_PAGE];
    }
}
