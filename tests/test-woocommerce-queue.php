<?php
// tests/test-woocommerce-queue.php
class Auto_Alt_Text_WooCommerce_Queue_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded in test environment');
        }
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        // Any OpenAI HTTP call during a product hook is a failure.
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') !== false) {
                $this->fail('OpenAI must not be called during product hooks');
            }
            return $pre;
        }, 10, 3);
    }

    private function make_product_with_image(&$attachment_id) {
        $attachment_id = self::factory()->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );
        $product = new WC_Product_Simple();
        $product->set_name('Test product');
        $product->set_image_id($attachment_id);
        $product->save();
        return $product;
    }

    private function pending_count() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE status = %s',
            Auto_Alt_Text_Queue::STATUS_PENDING
        ));
    }

    public function test_product_update_enqueues_image_without_alt_text() {
        $wc = new Auto_Alt_Text_WooCommerce();
        $product = $this->make_product_with_image($attachment_id);
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        $wc->process_product_images($product->get_id());
        $this->assertSame(1, $this->pending_count());
    }

    public function test_product_update_skips_image_with_existing_alt_text() {
        $wc = new Auto_Alt_Text_WooCommerce();
        $product = $this->make_product_with_image($attachment_id);
        update_post_meta($attachment_id, '_wp_attachment_image_alt', 'Existing alt');
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        $wc->process_product_images($product->get_id());
        $this->assertSame(0, $this->pending_count(), 'Stock-sync style updates must enqueue nothing');
    }

    public function test_repeated_updates_enqueue_once() {
        $wc = new Auto_Alt_Text_WooCommerce();
        $product = $this->make_product_with_image($attachment_id);
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        $wc->process_product_images($product->get_id());
        $wc->process_product_images($product->get_id());
        $wc->process_product_images($product->get_id());
        $this->assertSame(1, $this->pending_count());
    }

    private function clear_queue() {
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
    }

    public function test_checkout_stock_save_enqueues_nothing() {
        $product = $this->make_product_with_image($attachment_id);
        $this->clear_queue();
        // wc_update_product_stock() during checkout ends in $product->save().
        $product->set_manage_stock(true);
        $product->set_stock_quantity(4);
        $product->save();
        $this->assertSame(0, $this->pending_count());
    }

    public function test_admin_product_save_enqueues_missing_alt_images() {
        $product = $this->make_product_with_image($attachment_id);
        $this->clear_queue();
        do_action('woocommerce_process_product_meta', $product->get_id(), get_post($product->get_id()));
        $this->assertSame(1, $this->pending_count());
    }

    public function test_admin_save_of_variable_product_enqueues_variation_images() {
        $attachment_id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $parent = new WC_Product_Variable();
        $parent->set_name('Variable product');
        $parent->save();
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent->get_id());
        $variation->set_image_id($attachment_id);
        $variation->save();
        $this->clear_queue();

        do_action('woocommerce_process_product_meta', $parent->get_id(), get_post($parent->get_id()));

        $this->assertSame(1, $this->pending_count());
    }

    public function test_explicit_alt_attribute_is_not_overridden() {
        $attachment_id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($attachment_id, '_wp_attachment_image_alt', 'Meta alt');

        $this->assertStringContainsString('alt=""', wp_get_attachment_image($attachment_id, 'thumbnail', false, ['alt' => '']));
        $this->assertStringContainsString('alt="Caller alt"', wp_get_attachment_image($attachment_id, 'thumbnail', false, ['alt' => 'Caller alt']));
        $this->assertStringContainsString('alt="Meta alt"', wp_get_attachment_image($attachment_id, 'thumbnail'));
    }

    public function test_products_list_bulk_action_queues_missing_alt_images() {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $product = $this->make_product_with_image($attachment_id);
        $this->clear_queue();

        $redirect = apply_filters(
            'handle_bulk_actions-edit-product',
            admin_url('edit.php?post_type=product'),
            'generate_alt_texts',
            [$product->get_id()]
        );

        $this->assertStringContainsString('aat_wc_queued=1', $redirect);
        global $wpdb;
        $this->assertSame('woocommerce_bulk', $wpdb->get_var($wpdb->prepare(
            'SELECT generation_type FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d',
            $attachment_id
        )));
    }

    public function test_unauthenticated_admin_post_bulk_endpoint_is_gone() {
        $this->assertFalse(has_action('admin_post_process_wc_product_images'));
    }
}
