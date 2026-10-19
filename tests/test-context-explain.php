<?php
class Auto_Alt_Text_Context_Explain_Test extends WP_UnitTestCase {
    private function image($parent = 0) {
        return self::factory()->attachment->create(['post_mime_type' => 'image/jpeg', 'post_parent' => $parent, 'file' => 'explain-test.jpg']);
    }

    private function requires_woocommerce() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
    }

    private function simple_product($name, $image) {
        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_image_id($image);
        $product->save();
        return $product->get_id();
    }

    public function test_product_image_is_found_through_product_usage() {
        $this->requires_woocommerce();
        $image = $this->image();
        $product = $this->simple_product('Sandlåda Robust 350 L', $image);

        $explanation = Auto_Alt_Text_Context::explain($image);

        $this->assertSame('found', $explanation['reason']);
        $this->assertSame('product_usage', $explanation['source']);
        $this->assertSame(1, $explanation['count']);
        $this->assertSame(['title' => 'Sandlåda Robust 350 L', 'type' => 'product', 'post_id' => $product, 'product_name' => 'Sandlåda Robust 350 L'], $explanation['context']);
        $this->assertSame('Produkt: Sandlåda Robust 350 L (produktbild/galleri)', Auto_Alt_Text_Context::describe($explanation));
    }

    public function test_hint_that_matches_the_product_is_reported_as_the_source() {
        $this->requires_woocommerce();
        $image = $this->image();
        $product = $this->simple_product('Sandlåda Robust 350 L', $image);

        $explanation = Auto_Alt_Text_Context::explain($image, $product);

        $this->assertSame('hint', $explanation['source']);
        $this->assertSame('Produkt: Sandlåda Robust 350 L (sparad produkt)', Auto_Alt_Text_Context::describe($explanation));
    }

    public function test_two_products_are_ambiguous_with_a_count() {
        $this->requires_woocommerce();
        $image = $this->image();
        $this->simple_product('Mug A', $image);
        $this->simple_product('Mug B', $image);
        $this->simple_product('Mug C', $image);

        $explanation = Auto_Alt_Text_Context::explain($image);

        $this->assertNull($explanation['context']);
        $this->assertSame('ambiguous_products', $explanation['reason']);
        $this->assertSame(3, $explanation['count']);
        $this->assertSame('Ingen produkt: bilden används av 3 produkter', Auto_Alt_Text_Context::describe($explanation));
    }

    public function test_variation_context_carries_the_parent_product_name() {
        $this->requires_woocommerce();
        $image = $this->image();
        $attribute = new WC_Product_Attribute();
        $attribute->set_name('Color');
        $attribute->set_options(['Blue', 'Red']);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $parent = new WC_Product_Variable();
        $parent->set_name('Nordic Mug');
        $parent->set_attributes([$attribute]);
        $parent->save();
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent->get_id());
        $variation->set_attributes(['color' => 'Blue']);
        $variation->set_image_id($image);
        $variation->save();

        $explanation = Auto_Alt_Text_Context::explain($image);

        $this->assertSame('variation', $explanation['context']['type']);
        $this->assertSame('Nordic Mug', $explanation['context']['product_name']);
        $this->assertStringStartsWith('Produktvariant: Nordic Mug', Auto_Alt_Text_Context::describe($explanation));
    }

    public function test_parent_page_is_reported_with_no_product_name() {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Om oss']);

        $explanation = Auto_Alt_Text_Context::explain($this->image($page));

        $this->assertSame('parent', $explanation['source']);
        $this->assertNull($explanation['context']['product_name']);
        $this->assertSame('Sida: Om oss (uppladdad till)', Auto_Alt_Text_Context::describe($explanation));
    }

    public function test_unique_featured_post_is_reported() {
        $image = $this->image();
        $post = self::factory()->post->create(['post_title' => 'Sommarrea']);
        set_post_thumbnail($post, $image);

        $explanation = Auto_Alt_Text_Context::explain($image);

        $this->assertSame('featured', $explanation['source']);
        $this->assertSame('Inlägg: Sommarrea (utvald bild)', Auto_Alt_Text_Context::describe($explanation));
    }

    public function test_conflicting_posts_are_ambiguous() {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Om oss']);
        $image = $this->image($page);
        set_post_thumbnail(self::factory()->post->create(['post_title' => 'Annat inlägg']), $image);

        $explanation = Auto_Alt_Text_Context::explain($image);

        $this->assertSame('ambiguous_posts', $explanation['reason']);
        $this->assertSame('Inget namn: bilden används på flera sidor eller inlägg', Auto_Alt_Text_Context::describe($explanation));
    }

    public function test_unused_image_has_no_context() {
        $explanation = Auto_Alt_Text_Context::explain($this->image());

        $this->assertSame(['context' => null, 'source' => null, 'reason' => 'none', 'count' => 0], $explanation);
        $this->assertSame('Ingen produkt eller sida hittades', Auto_Alt_Text_Context::describe($explanation));
    }

    public function test_for_attachment_returns_the_explained_context() {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Om oss']);
        $image = $this->image($page);

        $this->assertSame(Auto_Alt_Text_Context::explain($image)['context'], Auto_Alt_Text_Context::for_attachment($image));
    }
}
