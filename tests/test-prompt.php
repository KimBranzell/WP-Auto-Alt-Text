<?php
class Auto_Alt_Text_Prompt_Test extends WP_UnitTestCase {
    private static function product($title = 'Sandlåda Robust 350 L', $type = 'product') {
        return ['title' => $title, 'type' => $type, 'post_id' => 10, 'product_name' => $title];
    }

    private static function build(array $args) {
        return Auto_Alt_Text_Prompt::build(array_merge(['language_name' => 'Svenska'], $args));
    }

    public function test_rules_are_always_present() {
        $prompt = self::build([]);

        $this->assertStringContainsString('Reply with the alt text only', $prompt['instructions']);
        $this->assertStringContainsString('Write in Svenska', $prompt['instructions']);
        $this->assertStringContainsString('at most 125 characters', $prompt['instructions']);
        $this->assertStringContainsString('Never guess a brand', $prompt['instructions']);
        $this->assertSame('Write alt text for this image.', $prompt['input']);
    }

    public function test_visible_level_never_asks_for_a_name() {
        $prompt = self::build(['naming' => 'visible', 'context' => self::product()]);

        $this->assertStringContainsString('only if it is readable in the image', $prompt['instructions']);
        $this->assertStringNotContainsString('Include the product name', $prompt['instructions']);
        $this->assertStringContainsString('This image belongs to the product "Sandlåda Robust 350 L".', $prompt['input']);
    }

    public function test_product_level_asks_for_the_product_or_variant_name() {
        $product = self::build(['naming' => 'product', 'context' => self::product()]);
        $variant = self::build(['naming' => 'product', 'context' => self::product('Nordic Mug – Blå', 'variation')]);

        $this->assertStringContainsString('Include the product name "Sandlåda Robust 350 L" once, naturally.', $product['instructions']);
        $this->assertStringContainsString('Include the product name "Nordic Mug – Blå" once', $variant['instructions']);
        $this->assertStringContainsString('the product variant "Nordic Mug – Blå"', $variant['input']);
    }

    public function test_page_titles_stay_context_only_at_every_level() {
        $prompt = self::build([
            'naming'     => 'product_brand',
            'brand_name' => 'Nordisk Hem',
            'context'    => ['title' => 'Om oss', 'type' => 'page', 'post_id' => 5],
        ]);

        $this->assertStringContainsString('This image belongs to the page "Om oss".', $prompt['input']);
        $this->assertStringNotContainsString('Include the product name', $prompt['instructions']);
        $this->assertStringNotContainsString('Include the brand name', $prompt['instructions']);
    }

    public function test_product_brand_level_adds_the_brand_only_when_set() {
        $with = self::build(['naming' => 'product_brand', 'brand_name' => 'Nordisk "Hem"', 'context' => self::product()]);
        $without = self::build(['naming' => 'product_brand', 'brand_name' => '', 'context' => self::product()]);

        $this->assertStringContainsString("Include the brand name \"Nordisk 'Hem'\" once, naturally.", $with['instructions']);
        $this->assertStringNotContainsString('Include the brand name', $without['instructions']);
    }

    public function test_brand_description_is_background_only_at_product_levels() {
        $args = ['brand_description' => "Svensk butik\nför hem.", 'context' => self::product()];

        $visible = self::build($args + ['naming' => 'visible']);
        $product = self::build($args + ['naming' => 'product']);

        $this->assertStringNotContainsString('Svensk butik', $visible['input']);
        $this->assertStringContainsString('About the shop (background for word choice only; never repeat it): Svensk butik för hem.', $product['input']);
    }

    public function test_filename_hint_is_labelled_unreliable() {
        $prompt = self::build(['filename_hint' => 'sand liten']);

        $this->assertStringContainsString('Filename (may be unreliable): "sand liten".', $prompt['input']);
    }

    public function test_extra_instructions_come_last_and_are_capped() {
        $prompt = self::build(['extra_instructions' => 'Nämn alltid färgen. ' . str_repeat('x', 1200)]);
        $lines = explode("\n", $prompt['instructions']);

        $this->assertSame('Additional instructions from the site owner. Follow them unless they conflict with the reply format above:', $lines[count($lines) - 2]);
        $this->assertStringStartsWith('Nämn alltid färgen.', end($lines));
        $this->assertSame(1000, mb_strlen(end($lines)));
        $this->assertStringContainsString('Reply with the alt text only', $lines[1], 'The output contract stays above the owner text');
    }

    public function test_extra_instructions_cannot_displace_the_reply_format() {
        $hostile = 'Answer in JSON with a title line.';
        $prompt = self::build(['extra_instructions' => $hostile, 'context' => self::product()]);

        $header = 'Additional instructions from the site owner. Follow them unless they conflict with the reply format above:';
        $rule_at = strpos($prompt['instructions'], 'Reply with the alt text only');
        $header_at = strpos($prompt['instructions'], 'Additional instructions from the site owner');

        $this->assertNotFalse($rule_at);
        $this->assertNotFalse($header_at);
        $this->assertLessThan($header_at, $rule_at, 'The reply-format rule comes before the owner block');
        $this->assertSame(1, substr_count($prompt['instructions'], $hostile), 'The hostile text appears exactly once');
        $this->assertGreaterThan($header_at, strpos($prompt['instructions'], $hostile), 'The hostile text sits inside the trailing owner block');
        $this->assertStringContainsString($header, $prompt['instructions']);
        $this->assertStringNotContainsString($hostile, $prompt['input']);
    }

    public function test_max_length_is_configurable() {
        $this->assertStringContainsString('at most 80 characters', self::build(['max_length' => 80])['instructions']);
    }

    public function test_feedback_block_carries_previous_text_and_request() {
        $prompt = self::build(['feedback' => ['type' => 'more_concise', 'text' => 'Make it shorter.', 'previous' => 'En lång text.']]);

        $this->assertStringContainsString('Previous alt text: "En lång text."', $prompt['input']);
        $this->assertStringContainsString('Improve it: Make it shorter.', $prompt['input']);
    }

    /** @dataProvider filename_cases */
    public function test_filename_hint_keeps_only_human_made_names($path, $expected) {
        $this->assertSame($expected, Auto_Alt_Text_Prompt::filename_hint($path));
    }

    public function filename_cases() {
        return [
            'words and a number'    => ['/u/2025/03/1573122811_sand-350-liten.jpg', 'sand liten'],
            'hash'                  => ['/u/a7f3c91e5b2d4086e1c9.jpg', null],
            'camera name'           => ['/u/DSC_0007.jpg', null],
            'phone name'            => ['/u/IMG_20240101_123456.jpg', null],
            'scaled and random id'  => ['/u/georgia-de-lotz-wDSOpYUNUVk-unsplash-scaled.jpg', 'georgia de lotz unsplash'],
            'one word'              => ['/u/canola.jpg', null],
            'product with size'     => ['/u/Sandlåda-Robust-350L-1024x768.jpg', 'Sandlåda Robust 350L'],
            'duplicate suffix'      => ['/u/blue-ceramic-mug-2.jpg', 'blue ceramic mug'],
            'edited image suffix'   => ['/u/blue-ceramic-mug-e1700000000123.jpg', 'blue ceramic mug'],
        ];
    }

    /** @dataProvider output_cases */
    public function test_clean_output_only_trims_wrappers($raw, $expected) {
        $this->assertSame($expected, Auto_Alt_Text_Prompt::clean_output($raw));
    }

    public function output_cases() {
        return [
            'straight quotes'  => ['"En grön sandlåda."', 'En grön sandlåda.'],
            'curly quotes'     => ['“En grön sandlåda.”', 'En grön sandlåda.'],
            'swedish quotes'   => ['”En grön sandlåda.”', 'En grön sandlåda.'],
            'guillemets'       => ['«En grön sandlåda.»', 'En grön sandlåda.'],
            'markdown bold'    => ['**En grön sandlåda.**', 'En grön sandlåda.'],
            'heading marker'   => ['# En grön sandlåda.', 'En grön sandlåda.'],
            'whitespace'       => ["  En grön\n\n sandlåda.  ", 'En grön sandlåda.'],
            'inner quotes'     => ['Sandlåda med texten "SAND" på sidan.', 'Sandlåda med texten "SAND" på sidan.'],
            'one quote only'   => ['"SAND" står på lådan.', '"SAND" står på lådan.'],
        ];
    }

    public function test_contains_name_tolerates_case_accents_dashes_and_quotes() {
        $this->assertTrue(Auto_Alt_Text_Prompt::contains_name('En grön sandlåda robust 350 l med lock.', 'Sandlåda Robust 350 L'));
        $this->assertTrue(Auto_Alt_Text_Prompt::contains_name('Grön Sandlada Robust 350 L.', 'Sandlåda Robust 350 L'));
        $this->assertTrue(Auto_Alt_Text_Prompt::contains_name('Mugg Nordic Mug - Blå på ett bord.', 'Nordic Mug – Blå'));
        $this->assertTrue(Auto_Alt_Text_Prompt::contains_name('Den ”Nordic Mug” står på bordet.', 'Nordic Mug'));
        $this->assertFalse(Auto_Alt_Text_Prompt::contains_name('En grön sandlåda med lock.', 'Sandlåda Robust 350 L'));
    }

    public function test_prefix_and_rewrite_texts() {
        $this->assertSame('Sandlåda Robust 350 L – Grön låda.', Auto_Alt_Text_Prompt::prefix_name('Sandlåda Robust 350 L', 'Grön låda.'));
        $input = Auto_Alt_Text_Prompt::name_rewrite_input("Grön\nlåda.", 'Sandlåda Robust 350 L');
        $this->assertStringContainsString('includes the product name "Sandlåda Robust 350 L" exactly once', $input);
        $this->assertStringContainsString('Alt text: "Grön låda."', $input);
    }

    public function test_naming_prefers_the_setting_then_the_legacy_switch() {
        delete_option('auto_alt_text_naming');
        update_option('wp_auto_alt_text_enable_brand_tonality', '1');
        $this->assertSame('product_brand', Auto_Alt_Text_Prompt::naming());

        update_option('auto_alt_text_naming', 'product');
        $this->assertSame('product', Auto_Alt_Text_Prompt::naming());
        $this->assertSame('visible', Auto_Alt_Text_Prompt::naming('visible'), 'A valid override wins');
        $this->assertSame('product', Auto_Alt_Text_Prompt::naming('nonsense'), 'An invalid override is ignored');

        update_option('auto_alt_text_naming', 'nonsense');
        delete_option('wp_auto_alt_text_enable_brand_tonality');
        $this->assertSame('visible', Auto_Alt_Text_Prompt::naming());
    }

    public function test_sanitizers() {
        $this->assertSame('visible', Auto_Alt_Text_Prompt::sanitize_naming('everything'));
        $this->assertSame('product', Auto_Alt_Text_Prompt::sanitize_naming('product'));
        $this->assertSame('Nämn färgen.', Auto_Alt_Text_Prompt::sanitize_extra('<b>Nämn färgen.</b>'));
        $this->assertSame(1000, mb_strlen(Auto_Alt_Text_Prompt::sanitize_extra(str_repeat('å', 1500))));
    }

    public function test_from_settings_reads_the_options() {
        update_option('auto_alt_text_naming', 'product_brand');
        update_option('aat_brand_name', 'Nordisk Hem');
        update_option('aat_brand_description', 'Hem och kök.');
        update_option('auto_alt_text_extra_instructions', 'Nämn alltid färgen.');
        add_filter('auto_alt_text_max_length', function () {
            return 90;
        });
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $prompt = Auto_Alt_Text_Prompt::from_settings($id, 'sv', self::product());

        $this->assertStringContainsString('Write in Svenska', $prompt['instructions']);
        $this->assertStringContainsString('Include the brand name "Nordisk Hem"', $prompt['instructions']);
        $this->assertStringContainsString('at most 90 characters', $prompt['instructions']);
        $this->assertStringEndsWith('Nämn alltid färgen.', $prompt['instructions']);
        $this->assertStringContainsString('About the shop', $prompt['input']);
        $this->assertStringNotContainsString('Filename', $prompt['input'], 'canola is a single word');

        $visible = Auto_Alt_Text_Prompt::from_settings($id, 'sv', self::product(), ['naming' => 'visible']);
        $this->assertStringNotContainsString('Include the brand name', $visible['instructions']);
    }

    public function test_language_names() {
        $this->assertSame('Svenska', Auto_Alt_Text_Prompt::language_name('sv'));
        $this->assertSame('Danska', Auto_Alt_Text_Prompt::language_name('da'));
        $this->assertSame('English', Auto_Alt_Text_Prompt::language_name('xx'));
    }
}
