<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Finds the product, variation or post an image belongs to, so generated alt
 * text can name it. Alt text is stored per attachment, so a name is used only
 * when the image has one owner: a wrong product name in alt text is worse
 * than none (design D3).
 *
 * 1. Products using the image (main, gallery or variation image) decide
 *    first: two or more products give no context, whatever the hint or
 *    parent says. With one product, a hint that is that product or one of
 *    its variations is kept, as is a single variation matching on its own.
 * 2. Without product usage, a published post featuring the image is weighed
 *    against the hint or parent: any disagreement gives no context.
 */
class Auto_Alt_Text_Context {
    private const EXCLUDED_STATUSES = ['trash', 'auto-draft'];
    private const TITLE_MAX_LENGTH = 150;

    /**
     * @param int      $attachment_id
     * @param int|null $hint_post_id Post that queued the job (e.g. the saved product).
     * @return array{title:string,type:string,post_id:int}|null
     */
    public static function for_attachment($attachment_id, $hint_post_id = null) {
        $attachment_id = (int) $attachment_id;
        $hint = self::usable((int) $hint_post_id);

        $rows = self::product_usage($attachment_id);
        $post_id = $rows
            ? self::product_owner($rows, $hint)
            : self::post_owner($attachment_id, $hint);
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

    /**
     * @return int The post ID when it exists, is not trashed and is public
     *             content (or a variation), else 0.
     */
    private static function usable($post_id) {
        if ($post_id <= 0) {
            return 0;
        }
        $post = get_post($post_id);
        if (!$post || in_array($post->post_status, self::EXCLUDED_STATUSES, true)) {
            return 0;
        }
        return (is_post_type_viewable($post->post_type) || 'product_variation' === $post->post_type) ? $post_id : 0;
    }

    /** Every product and variation using the image as main, gallery or variation image. */
    private static function product_usage($attachment_id) {
        if (!post_type_exists('product')) {
            return [];
        }
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_type, p.post_parent FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND ((m.meta_key = '_thumbnail_id' AND m.meta_value = %s)
                 OR (m.meta_key = '_product_image_gallery' AND FIND_IN_SET(%s, m.meta_value)))",
            (string) $attachment_id,
            (string) $attachment_id
        ));
    }

    /**
     * The single product the usage rows belong to (a variation counts as its
     * parent), or 0 when they span several products. Resolves to the hint
     * when it is that product or one of its variations, to the only matching
     * row when there is just one, and otherwise to the product.
     */
    private static function product_owner(array $rows, $hint) {
        $products = [];
        foreach ($rows as $row) {
            $products[ 'product_variation' === $row->post_type ? (int) $row->post_parent : (int) $row->ID ] = true;
        }
        if (1 !== count($products)) {
            return 0;
        }
        $product_id = (int) array_key_first($products);

        if ($hint && ($hint === $product_id || ('product_variation' === get_post_type($hint) && $product_id === wp_get_post_parent_id($hint)))) {
            return $hint;
        }
        return (1 === count($rows)) ? (int) $rows[0]->ID : $product_id;
    }

    /**
     * Without product usage: the single published post featuring the image,
     * or else the hint or parent, and 0 when they disagree.
     */
    private static function post_owner($attachment_id, $hint) {
        global $wpdb;
        $featured = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = %s
               AND p.post_type NOT IN ('product', 'product_variation', 'revision', 'attachment')
               AND p.post_status IN ('publish', 'private', 'future')
             LIMIT 2",
            (string) $attachment_id
        ));
        $owner = $hint ?: self::usable((int) wp_get_post_parent_id($attachment_id));

        if (count($featured) >= 2) {
            return 0;
        }
        if (1 === count($featured)) {
            $featured_id = (int) $featured[0];
            return ($owner && $owner !== $featured_id) ? 0 : self::usable($featured_id);
        }
        return $owner;
    }

    /** The context post's translation in the attachment's language, when one exists. */
    private static function translated($post_id, $attachment_id) {
        if (defined('ICL_SITEPRESS_VERSION')) {
            $details = apply_filters('wpml_post_language_details', null, $attachment_id);
            $language = is_array($details) ? (string) ($details['language_code'] ?? '') : '';
            if ('' !== $language) {
                $translated = (int) apply_filters('wpml_object_id', $post_id, get_post_type($post_id), false, $language);
                return self::usable($translated) ?: $post_id;
            }
        } elseif (function_exists('pll_get_post_language') && function_exists('pll_get_post')) {
            $language = (string) pll_get_post_language($attachment_id);
            if ('' !== $language) {
                $translated = (int) pll_get_post($post_id, $language);
                return self::usable($translated) ?: $post_id;
            }
        }
        return $post_id;
    }

    private static function title_for($post) {
        $title = $post->post_title;
        if ('product_variation' === $post->post_type && function_exists('wc_get_product')) {
            $variation = wc_get_product($post->ID);
            if ($variation) {
                $title = $variation->get_name();
            }
        }
        return self::prompt_safe($title);
    }

    /**
     * Plain text on one line, without double quotes and capped in length:
     * the title is quoted inside the prompt.
     */
    private static function prompt_safe($title) {
        $title = html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = wp_strip_all_tags($title, true);
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim(str_replace('"', "'", $title));
        return trim(mb_substr($title, 0, self::TITLE_MAX_LENGTH));
    }
}
