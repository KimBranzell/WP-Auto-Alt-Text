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
}
