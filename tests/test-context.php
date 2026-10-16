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

    private function simple_product($name, $image = 0, $gallery = []) {
        $product = new WC_Product_Simple();
        $product->set_name($name);
        if ($image) {
            $product->set_image_id($image);
        }
        if ($gallery) {
            $product->set_gallery_image_ids($gallery);
        }
        $product->save();
        return $product->get_id();
    }

    /** @return int[] [parent product ID, variation ID], both using $image. */
    private function variable_product_sharing_image($image) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_name('Color');
        $attribute->set_options(['Blue', 'Red']);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $parent = new WC_Product_Variable();
        $parent->set_name('Nordic Mug');
        $parent->set_attributes([$attribute]);
        $parent->set_image_id($image);
        $parent->save();
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent->get_id());
        $variation->set_attributes(['color' => 'Blue']);
        $variation->set_image_id($image);
        $variation->save();
        return [$parent->get_id(), $variation->get_id()];
    }

    public function test_image_uploaded_to_one_product_and_used_by_another_is_ambiguous() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
        $a = $this->simple_product('Nordic Mug');
        $image = $this->image($a);
        $product = wc_get_product($a);
        $product->set_image_id($image);
        $product->save();
        $this->simple_product('Nordic Bowl', 0, [$image]);

        $this->assertNull(Auto_Alt_Text_Context::for_attachment($image));
    }

    public function test_hint_does_not_override_shared_product_usage() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
        $a = $this->simple_product('Nordic Mug');
        $image = $this->image($a);
        $product = wc_get_product($a);
        $product->set_image_id($image);
        $product->save();
        $b = $this->simple_product('Nordic Bowl', 0, [$image]);

        $this->assertNull(Auto_Alt_Text_Context::for_attachment($image, $b));
    }

    public function test_ambiguous_products_do_not_fall_through_to_a_featured_post() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
        $image = $this->image();
        $this->simple_product('Nordic Mug', $image);
        $this->simple_product('Nordic Bowl', $image);
        set_post_thumbnail(self::factory()->post->create(['post_title' => 'Autumn lookbook']), $image);

        $this->assertNull(Auto_Alt_Text_Context::for_attachment($image));
    }

    public function test_many_variations_cannot_hide_a_second_product() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
        $image = $this->image();
        $sizes = [];
        for ($i = 0; $i < 22; $i++) {
            $sizes[] = 'S' . $i;
        }
        $attribute = new WC_Product_Attribute();
        $attribute->set_name('Size');
        $attribute->set_options($sizes);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $parent = new WC_Product_Variable();
        $parent->set_name('Nordic Mug');
        $parent->set_attributes([$attribute]);
        $parent->set_image_id($image);
        $parent->save();
        foreach ($sizes as $size) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent->get_id());
            $variation->set_attributes(['size' => $size]);
            $variation->set_image_id($image);
            $variation->save();
        }
        $this->simple_product('Nordic Bowl', $image);

        $this->assertNull(Auto_Alt_Text_Context::for_attachment($image));
    }

    public function test_variation_hint_is_kept_when_parent_and_variation_share_the_image() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
        $image = $this->image();
        [, $variation] = $this->variable_product_sharing_image($image);

        $context = Auto_Alt_Text_Context::for_attachment($image, $variation);

        $this->assertSame('variation', $context['type']);
        $this->assertSame($variation, $context['post_id']);
    }

    public function test_parent_and_variation_sharing_the_image_resolve_to_the_product() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
        $image = $this->image();
        [$parent] = $this->variable_product_sharing_image($image);

        $context = Auto_Alt_Text_Context::for_attachment($image);

        $this->assertSame(['title' => 'Nordic Mug', 'type' => 'product', 'post_id' => $parent], $context);
    }

    public function test_trashed_hint_is_ignored() {
        $hint = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Old campaign']);
        wp_trash_post($hint);
        $parent = self::factory()->post->create(['post_title' => 'Parent post']);

        $this->assertSame('Parent post', Auto_Alt_Text_Context::for_attachment($this->image($parent), $hint)['title']);
    }

    public function test_trashed_wpml_translation_falls_back_to_the_original() {
        $english = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'About us']);
        $swedish = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Gammal sida']);
        wp_trash_post($swedish);
        $image = $this->image($english);
        add_filter('wpml_post_language_details', function ($details, $post_id) use ($image) {
            return $post_id === $image ? ['language_code' => 'sv'] : $details;
        }, 10, 2);
        add_filter('wpml_object_id', function ($post_id, $type, $return_original, $language) use ($english, $swedish) {
            return ($post_id === $english && 'sv' === $language) ? $swedish : $post_id;
        }, 10, 4);

        $this->assertSame('About us', Auto_Alt_Text_Context::for_attachment($image)['title']);
    }

    public function test_non_content_parent_is_ignored() {
        $block = self::factory()->post->create(['post_type' => 'wp_block', 'post_title' => 'Footer CTA pattern', 'post_status' => 'publish']);

        $this->assertNull(Auto_Alt_Text_Context::for_attachment($this->image($block)));
    }

    public function test_draft_featuring_the_image_is_not_used() {
        $image = $this->image();
        set_post_thumbnail(self::factory()->post->create(['post_title' => 'Unpublished draft', 'post_status' => 'draft']), $image);

        $this->assertNull(Auto_Alt_Text_Context::for_attachment($image));
    }

    public function test_parent_page_image_featured_on_another_post_is_ambiguous() {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'About us']);
        $image = $this->image($page);
        set_post_thumbnail(self::factory()->post->create(['post_title' => 'Team news']), $image);

        $this->assertNull(Auto_Alt_Text_Context::for_attachment($image));
    }

    public function test_title_is_decoded_single_line_without_double_quotes_and_capped() {
        $title = 'Salt &amp; Pepper "Classic"' . "\n" . 'Mill ' . str_repeat('x', 300);
        $parent = self::factory()->post->create(['post_title' => $title]);

        $clean = Auto_Alt_Text_Context::for_attachment($this->image($parent))['title'];

        $this->assertStringStartsWith("Salt & Pepper 'Classic' Mill x", $clean);
        $this->assertStringNotContainsString('"', $clean);
        $this->assertStringNotContainsString("\n", $clean);
        $this->assertLessThanOrEqual(150, mb_strlen($clean));
    }
}
