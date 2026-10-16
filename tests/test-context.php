<?php
if (!defined('ICL_SITEPRESS_VERSION')) {
    define('ICL_SITEPRESS_VERSION', '4.9.2');
}

class Auto_Alt_Text_Context_Test extends WP_UnitTestCase {
    private function image($parent = 0) {
        return self::factory()->attachment->create(['post_mime_type' => 'image/jpeg', 'post_parent' => $parent, 'file' => 'context-test.jpg']);
    }

    public function test_hint_wins_over_parent() {
        $hinted = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hinted page']);
        $parent = self::factory()->post->create(['post_title' => 'Parent post']);
        $image = $this->image($parent);

        $context = Auto_Alt_Text_Context::for_attachment($image, $hinted);

        $this->assertSame(['title' => 'Hinted page', 'type' => 'page', 'post_id' => $hinted], $context);
    }

    public function test_parent_is_used_without_hint() {
        $parent = self::factory()->post->create(['post_title' => 'Summer sale']);

        $context = Auto_Alt_Text_Context::for_attachment($this->image($parent));

        $this->assertSame('Summer sale', $context['title']);
        $this->assertSame('post', $context['type']);
    }

    public function test_trashed_parent_gives_no_context() {
        $parent = self::factory()->post->create(['post_title' => 'Old product page']);
        $image = $this->image($parent);
        wp_trash_post($parent);

        $this->assertNull(Auto_Alt_Text_Context::for_attachment($image));
    }

    public function test_unique_featured_post_is_used() {
        $image = $this->image();
        $post = self::factory()->post->create(['post_title' => 'Featured story']);
        set_post_thumbnail($post, $image);

        $this->assertSame('Featured story', Auto_Alt_Text_Context::for_attachment($image)['title']);
    }

    public function test_image_featured_on_two_posts_is_ambiguous() {
        $image = $this->image();
        set_post_thumbnail(self::factory()->post->create(), $image);
        set_post_thumbnail(self::factory()->post->create(), $image);

        $this->assertNull(Auto_Alt_Text_Context::for_attachment($image));
    }

    public function test_unattached_unused_image_has_no_context() {
        $this->assertNull(Auto_Alt_Text_Context::for_attachment($this->image()));
    }

    public function test_unique_product_main_image() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
        $image = $this->image();
        $product = new WC_Product_Simple();
        $product->set_name('Nordic Mug');
        $product->set_image_id($image);
        $product->save();

        $context = Auto_Alt_Text_Context::for_attachment($image);

        $this->assertSame('Nordic Mug', $context['title']);
        $this->assertSame('product', $context['type']);
    }

    public function test_gallery_image_shared_by_two_products_is_ambiguous() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
        $image = $this->image();
        foreach (['Nordic Mug', 'Nordic Bowl'] as $name) {
            $product = new WC_Product_Simple();
            $product->set_name($name);
            $product->set_gallery_image_ids([$image]);
            $product->save();
        }

        $this->assertNull(Auto_Alt_Text_Context::for_attachment($image));
    }

    public function test_variation_image_uses_the_variation_name_with_attributes() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
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

        $context = Auto_Alt_Text_Context::for_attachment($image);

        $this->assertSame('variation', $context['type']);
        $this->assertStringContainsString('Nordic Mug', $context['title']);
        $this->assertStringContainsString('Blue', $context['title']);
    }

    public function test_wpml_translation_of_the_context_post_is_used() {
        $english = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'About us']);
        $swedish = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Om oss']);
        $image = $this->image($english);
        add_filter('wpml_post_language_details', function ($details, $post_id) use ($image) {
            return $post_id === $image ? ['language_code' => 'sv'] : $details;
        }, 10, 2);
        add_filter('wpml_object_id', function ($post_id, $type, $return_original, $language) use ($english, $swedish) {
            return ($post_id === $english && 'sv' === $language) ? $swedish : $post_id;
        }, 10, 4);

        $this->assertSame('Om oss', Auto_Alt_Text_Context::for_attachment($image)['title']);
    }
}
