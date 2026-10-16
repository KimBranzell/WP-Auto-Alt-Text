<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce integration: queues product images that lack alt text when a
 * product is saved in wp-admin, and adds a products-list bulk action.
 *
 * Rendering is left to WooCommerce and core, which already print the
 * attachment's alt text and respect alt values passed by themes.
 */
class Auto_Alt_Text_WooCommerce {
    public const BULK_ACTION = 'generate_alt_texts';
    public const QUEUED_QUERY_ARG = 'aat_wc_queued';

    private $queue;

    public function __construct() {
        $this->queue = new Auto_Alt_Text_Queue();

        // Admin product edit screen only. woocommerce_update_product also fires on
        // checkout stock reduction, REST, CSV import and variation sync, so it is
        // deliberately not used. Priority 30 runs after WooCommerce saves the
        // product data (10) and the gallery (20).
        add_action('woocommerce_process_product_meta', [$this, 'process_product_images'], 30);

        // Variations saved from the variations panel (admin AJAX).
        add_action('woocommerce_save_product_variation', [$this, 'process_variation_image'], 10, 2);

        add_filter('bulk_actions-edit-product', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle_bulk_action'], 10, 3);
        add_filter('removable_query_args', [$this, 'add_removable_query_args']);
        add_action('admin_notices', [$this, 'render_bulk_notice']);
    }

    /**
     * Enqueues the product's images that still lack alt text.
     *
     * @param int $product_id
     */
    public function process_product_images($product_id) {
        foreach ($this->get_product_image_ids($product_id) as $attachment_id) {
            $this->enqueue_if_missing_alt($attachment_id, 'woocommerce');
        }
    }

    public function process_variation_image($variation_id, $loop) {
        $variation = wc_get_product($variation_id);
        if ($variation && $variation->get_image_id()) {
            $this->enqueue_if_missing_alt($variation->get_image_id(), 'woocommerce_variation');
        }
    }

    public function add_bulk_actions($bulk_actions) {
        $bulk_actions[self::BULK_ACTION] = __('Generate Alt Texts', 'WP-Auto-Alt-Text');
        return $bulk_actions;
    }

    /**
     * Products-list bulk action. Core verifies the bulk-posts nonce and the
     * edit_posts capability before applying this filter (wp-admin/edit.php).
     * Explicit requests are queued even when automatic generation is off.
     *
     * @return string Redirect URL.
     */
    public function handle_bulk_action($redirect_to, $doaction, $post_ids) {
        if (self::BULK_ACTION !== $doaction) {
            return $redirect_to;
        }

        $policy = Auto_Alt_Text_Provenance::default_policy();
        $queued = 0;
        foreach ((array) $post_ids as $product_id) {
            if (!current_user_can('edit_post', $product_id)) {
                continue;
            }
            foreach ($this->get_product_image_ids($product_id) as $attachment_id) {
                if (!Auto_Alt_Text_Provenance::allows_overwrite($attachment_id, $policy)) {
                    continue;
                }
                // An existing pending job (e.g. an 'upload' job paused by the
                // auto-generate toggle) is promoted so this request runs.
                if ($this->queue->enqueue($attachment_id, 'woocommerce_bulk')
                    || $this->queue->promote_pending($attachment_id, 'woocommerce_bulk')) {
                    $queued++;
                }
            }
        }

        return add_query_arg(self::QUEUED_QUERY_ARG, $queued, $redirect_to);
    }

    public function add_removable_query_args($args) {
        $args[] = self::QUEUED_QUERY_ARG;
        return $args;
    }

    public function render_bulk_notice() {
        if (!isset($_GET[self::QUEUED_QUERY_ARG])) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || 'edit-product' !== $screen->id) {
            return;
        }
        $count = absint($_GET[self::QUEUED_QUERY_ARG]);
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: %d: number of product images queued */
                _n('%d product image queued for alt text generation.', '%d product images queued for alt text generation.', $count, 'WP-Auto-Alt-Text'),
                $count
            ))
        );
    }

    /**
     * @return int[] Unique attachment IDs: main image, gallery and variation images.
     */
    private function get_product_image_ids($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return [];
        }

        $image_ids = array_merge([$product->get_image_id()], $product->get_gallery_image_ids());
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $image_ids[] = $variation->get_image_id();
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $image_ids))));
    }

    private function enqueue_if_missing_alt($attachment_id, $generation_type) {
        if (!get_option('auto_alt_text_auto_generate', true)) {
            return;
        }
        if (get_post_meta($attachment_id, '_wp_attachment_image_alt', true)) {
            return;
        }
        $this->queue->enqueue($attachment_id, $generation_type);
    }
}
