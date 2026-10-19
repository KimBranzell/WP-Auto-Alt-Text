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
 *
 * explain() also reports how the answer was reached (generation-quality spec
 * §4a), so editors can see why a name is or is not used.
 */
class Auto_Alt_Text_Context {
    private const EXCLUDED_STATUSES = ['trash', 'auto-draft'];
    private const TITLE_MAX_LENGTH = 150;

    /**
     * @param int      $attachment_id
     * @param int|null $hint_post_id Post that queued the job (e.g. the saved product).
     * @return array{title:string,type:string,post_id:int,product_name:?string}|null
     */
    public static function for_attachment($attachment_id, $hint_post_id = null) {
        return self::explain($attachment_id, $hint_post_id)['context'];
    }

    /**
     * @return array{context:?array,source:?string,reason:string,count:int}
     *         source: hint|product_usage|parent|featured; reason: found|ambiguous_products|ambiguous_posts|none.
     */
    public static function explain($attachment_id, $hint_post_id = null) {
        $attachment_id = (int) $attachment_id;
        $hint = self::usable((int) $hint_post_id);
        $none = ['context' => null, 'source' => null, 'reason' => 'none', 'count' => 0];

        $rows = self::product_usage($attachment_id);
        if ($rows) {
            $products = self::products_in($rows);
            if (count($products) > 1) {
                return ['context' => null, 'source' => null, 'reason' => 'ambiguous_products', 'count' => count($products)];
            }
            $post_id = self::product_owner($rows, $hint, $products[0]);
            $source = ($hint && $post_id === $hint) ? 'hint' : 'product_usage';
        } else {
            $owner = self::post_owner($attachment_id, $hint);
            if (!$owner['post_id']) {
                return array_merge($none, ['reason' => $owner['reason'], 'count' => $owner['count']]);
            }
            $post_id = $owner['post_id'];
            $source = $owner['source'];
        }

        $context = self::context_for($post_id, $attachment_id);
        if (null === $context) {
            return $none;
        }
        return ['context' => $context, 'source' => $source, 'reason' => 'found', 'count' => 1];
    }

    /** One line for editors, in Swedish like the settings page. */
    public static function describe(array $explanation) {
        $reason = $explanation['reason'] ?? 'none';

        if ('found' === $reason && !empty($explanation['context'])) {
            $context = $explanation['context'];
            $is_product = in_array($context['type'], ['product', 'variation'], true);
            $labels = [
                'product'   => __('Produkt', 'WP-Auto-Alt-Text'),
                'variation' => __('Produktvariant', 'WP-Auto-Alt-Text'),
                'page'      => __('Sida', 'WP-Auto-Alt-Text'),
                'post'      => __('Inlägg', 'WP-Auto-Alt-Text'),
            ];
            $sources = [
                'hint'          => $is_product ? __('sparad produkt', 'WP-Auto-Alt-Text') : __('angiven sida', 'WP-Auto-Alt-Text'),
                'product_usage' => __('produktbild/galleri', 'WP-Auto-Alt-Text'),
                'parent'        => __('uppladdad till', 'WP-Auto-Alt-Text'),
                'featured'      => __('utvald bild', 'WP-Auto-Alt-Text'),
            ];
            return sprintf(
                /* translators: 1: content type, 2: title, 3: how it was found */
                __('%1$s: %2$s (%3$s)', 'WP-Auto-Alt-Text'),
                $labels[$context['type']] ?? __('Innehåll', 'WP-Auto-Alt-Text'),
                $context['title'],
                $sources[$explanation['source']] ?? ''
            );
        }
        if ('ambiguous_products' === $reason) {
            /* translators: %d: number of products using the image */
            return sprintf(__('Ingen produkt: bilden används av %d produkter', 'WP-Auto-Alt-Text'), (int) $explanation['count']);
        }
        if ('ambiguous_posts' === $reason) {
            return __('Inget namn: bilden används på flera sidor eller inlägg', 'WP-Auto-Alt-Text');
        }
        return __('Ingen produkt eller sida hittades', 'WP-Auto-Alt-Text');
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

    /** @return int[] The distinct products the usage rows belong to (a variation counts as its parent). */
    private static function products_in(array $rows) {
        $products = [];
        foreach ($rows as $row) {
            $products[ 'product_variation' === $row->post_type ? (int) $row->post_parent : (int) $row->ID ] = true;
        }
        return array_keys($products);
    }

    /**
     * Within the single product the rows belong to: the hint when it is that
     * product or one of its variations, the only matching row when there is
     * just one, and otherwise the product.
     */
    private static function product_owner(array $rows, $hint, $product_id) {
        if ($hint && ($hint === $product_id || ('product_variation' === get_post_type($hint) && $product_id === wp_get_post_parent_id($hint)))) {
            return $hint;
        }
        return (1 === count($rows)) ? (int) $rows[0]->ID : (int) $product_id;
    }

    /**
     * Without product usage: the single published post featuring the image,
     * or else the hint or parent; nothing when they disagree.
     *
     * @return array{post_id:int,source:?string,reason:string,count:int}
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
        $parent = $hint ? 0 : self::usable((int) wp_get_post_parent_id($attachment_id));
        $owner = $hint ?: $parent;
        $owner_source = $hint ? 'hint' : ($parent ? 'parent' : null);
        $ambiguous = ['post_id' => 0, 'source' => null, 'reason' => 'ambiguous_posts', 'count' => 2];

        if (count($featured) >= 2) {
            return $ambiguous;
        }
        if (1 === count($featured)) {
            $featured_id = (int) $featured[0];
            if ($owner && $owner !== $featured_id) {
                return $ambiguous;
            }
            $usable = self::usable($featured_id);
            return $usable
                ? ['post_id' => $usable, 'source' => $owner ? $owner_source : 'featured', 'reason' => 'found', 'count' => 1]
                : ['post_id' => 0, 'source' => null, 'reason' => 'none', 'count' => 0];
        }
        return $owner
            ? ['post_id' => $owner, 'source' => $owner_source, 'reason' => 'found', 'count' => 1]
            : ['post_id' => 0, 'source' => null, 'reason' => 'none', 'count' => 0];
    }

    /** @return array{title:string,type:string,post_id:int,product_name:?string}|null */
    private static function context_for($post_id, $attachment_id) {
        $post = get_post(self::translated($post_id, $attachment_id));
        $title = $post ? self::title_for($post) : '';
        if ('' === $title) {
            return null;
        }

        $product_name = null;
        if ('product' === $post->post_type) {
            $product_name = $title;
        } elseif ('product_variation' === $post->post_type) {
            $parent = get_post((int) $post->post_parent);
            $product_name = $parent ? self::prompt_safe($parent->post_title) : '';
            $product_name = '' !== $product_name ? $product_name : $title;
        }

        return [
            'title'        => $title,
            'type'         => 'product_variation' === $post->post_type ? 'variation' : $post->post_type,
            'post_id'      => (int) $post->ID,
            'product_name' => $product_name,
        ];
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
