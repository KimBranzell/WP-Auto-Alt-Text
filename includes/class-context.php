<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Finds the product, variation or post an image belongs to, so generated alt
 * text can name it. Returns the most certain source or nothing: a wrong
 * product name in alt text is worse than none (design D3).
 */
class Auto_Alt_Text_Context {
    private const EXCLUDED_STATUSES = ['trash', 'auto-draft'];

    /**
     * @param int      $attachment_id
     * @param int|null $hint_post_id Post that queued the job (e.g. the saved product).
     * @return array{title:string,type:string,post_id:int}|null
     */
    public static function for_attachment($attachment_id, $hint_post_id = null) {
        $attachment_id = (int) $attachment_id;

        $post_id = self::usable((int) $hint_post_id)
            ?: self::usable((int) wp_get_post_parent_id($attachment_id))
            ?: self::unique_product_using($attachment_id)
            ?: self::unique_featured_post($attachment_id);
        if (!$post_id) {
            return null;
        }

        $post = get_post(self::translated($post_id, $attachment_id));
        $title = $post ? self::title_for($post) : '';
        if ('' === $title) {
            return null;
        }

        return [
            'title'   => $title,
            'type'    => 'product_variation' === $post->post_type ? 'variation' : $post->post_type,
            'post_id' => (int) $post->ID,
        ];
    }

    /** @return int The post ID when it exists and is not trashed, else 0. */
    private static function usable($post_id) {
        if ($post_id <= 0) {
            return 0;
        }
        $post = get_post($post_id);
        return ($post && !in_array($post->post_status, self::EXCLUDED_STATUSES, true)) ? $post_id : 0;
    }

    /**
     * The single product or variation using the image as main, gallery or
     * variation image. An image used by one variable product only (parent
     * and/or its variations) resolves to the variation when exactly one
     * variation uses it alone, otherwise to the parent product.
     */
    private static function unique_product_using($attachment_id) {
        if (!post_type_exists('product')) {
            return 0;
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_type, p.post_parent FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND ((m.meta_key = '_thumbnail_id' AND m.meta_value = %s)
                 OR (m.meta_key = '_product_image_gallery' AND FIND_IN_SET(%s, m.meta_value)))
             LIMIT 20",
            (string) $attachment_id,
            (string) $attachment_id
        ));
        if (empty($rows)) {
            return 0;
        }

        $products = [];
        foreach ($rows as $row) {
            $products[ 'product_variation' === $row->post_type ? (int) $row->post_parent : (int) $row->ID ] = true;
        }
        if (1 !== count($products)) {
            return 0;
        }

        return (1 === count($rows)) ? (int) $rows[0]->ID : (int) array_key_first($products);
    }

    /** The single post or page using the image as featured image. */
    private static function unique_featured_post($attachment_id) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = %s
               AND p.post_type NOT IN ('product', 'product_variation', 'revision', 'attachment')
               AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')
             LIMIT 2",
            (string) $attachment_id
        ));
        return 1 === count($ids) ? (int) $ids[0] : 0;
    }

    /** The context post's translation in the attachment's language, when one exists. */
    private static function translated($post_id, $attachment_id) {
        if (defined('ICL_SITEPRESS_VERSION')) {
            $details = apply_filters('wpml_post_language_details', null, $attachment_id);
            $language = is_array($details) ? (string) ($details['language_code'] ?? '') : '';
            if ('' !== $language) {
                $translated = (int) apply_filters('wpml_object_id', $post_id, get_post_type($post_id), false, $language);
                return $translated ?: $post_id;
            }
        } elseif (function_exists('pll_get_post_language') && function_exists('pll_get_post')) {
            $language = (string) pll_get_post_language($attachment_id);
            if ('' !== $language) {
                $translated = (int) pll_get_post($post_id, $language);
                return $translated ?: $post_id;
            }
        }
        return $post_id;
    }

    private static function title_for($post) {
        if ('product_variation' === $post->post_type && function_exists('wc_get_product')) {
            $variation = wc_get_product($post->ID);
            if ($variation) {
                return trim(wp_strip_all_tags($variation->get_name()));
            }
        }
        return trim(wp_strip_all_tags($post->post_title));
    }
}
