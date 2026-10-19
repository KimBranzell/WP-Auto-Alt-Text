<?php
require_once dirname(__DIR__) . '/eval/lib/checks.php';

class Auto_Alt_Text_Eval_Checks_Test extends WP_UnitTestCase {
    private static function config(array $overrides = []) {
        return array_merge(['naming' => 'product', 'language' => 'sv', 'product_name' => 'Sandlåda Robust 350 L', 'brand_name' => 'Nordisk Hem', 'max_length' => 125], $overrides);
    }

    public function test_a_good_swedish_product_text_passes() {
        $text = 'Sandlåda Robust 350 L i grön plast med lock och texten SAND på sidan.';

        $checks = aat_eval_checks(['expected_text' => []], $text, $text, self::config());

        $this->assertTrue($checks['length_ok']);
        $this->assertTrue($checks['opening_ok']);
        $this->assertTrue($checks['language_ok']);
        $this->assertTrue($checks['name_first_ok']);
        $this->assertTrue($checks['name_final_ok']);
        $this->assertNull($checks['brand_ok'], 'Brand is checked only at product_brand');
        $this->assertNull($checks['text_recall']);
    }

    public function test_length_is_not_checked_when_the_image_has_text_to_transcribe() {
        $checks = aat_eval_checks(['expected_text' => ['SAND']], str_repeat('lång text ', 20), '', self::config());

        $this->assertNull($checks['length_ok']);
    }

    public function test_banned_openings_fail() {
        foreach (['Bild av en grön låda.', 'Image of a green box.', 'Bilden visar en låda.'] as $text) {
            $this->assertFalse(aat_eval_checks([], $text, $text, self::config())['opening_ok'], $text);
        }
    }

    public function test_english_text_fails_a_swedish_run() {
        $text = 'A green box with a lid on the ground.';

        $this->assertFalse(aat_eval_checks([], $text, $text, self::config())['language_ok']);
        $this->assertTrue(aat_eval_checks([], $text, $text, self::config(['language' => 'en']))['language_ok']);
    }

    public function test_name_first_measures_the_model_and_name_final_the_guarantee() {
        $first = 'En grön sandlåda med lock.';
        $final = 'Sandlåda Robust 350 L – En grön sandlåda med lock.';

        $checks = aat_eval_checks([], $final, $first, self::config());

        $this->assertFalse($checks['name_first_ok']);
        $this->assertTrue($checks['name_final_ok']);
    }

    public function test_brand_checks_by_level() {
        $with = 'Sandlåda Robust 350 L från Nordisk Hem, grön med lock.';
        $without = 'Sandlåda Robust 350 L, grön med lock.';

        $this->assertTrue(aat_eval_checks([], $with, $with, self::config(['naming' => 'product_brand']))['brand_ok']);
        $this->assertFalse(aat_eval_checks([], $without, $without, self::config(['naming' => 'product_brand']))['brand_ok']);
        $this->assertFalse(aat_eval_checks([], $with, $with, self::config(['naming' => 'visible']))['brand_absent_ok']);
        $this->assertNull(aat_eval_checks(['expected_text' => ['Nordisk Hem']], $with, $with, self::config(['naming' => 'visible']))['brand_absent_ok'], 'A brand printed in the image may be named');
    }

    public function test_name_is_not_checked_without_a_product() {
        $checks = aat_eval_checks([], 'Ett gult rapsfält.', 'Ett gult rapsfält.', self::config(['product_name' => null]));

        $this->assertNull($checks['name_first_ok']);
        $this->assertNull($checks['name_final_ok']);
    }

    public function test_text_recall_counts_transcribed_strings() {
        $item = ['expected_text' => ['FELIX', 'TOMATKETCHUP', 'ORIGINAL', '1KG']];
        $text = 'Felix Tomatketchup Original i en röd flaska.';

        $this->assertSame(0.75, aat_eval_checks($item, $text, $text, self::config())['text_recall']);
    }

    public function test_cost_and_estimate() {
        $luna = Auto_Alt_Text_Models::get('gpt-6-luna');

        $this->assertEqualsWithDelta(0.000115, aat_eval_cost($luna, 1000, 30), 0.0000001);
        $this->assertGreaterThan(aat_eval_estimate($luna, 'low'), aat_eval_estimate($luna, 'auto'));
    }

    public function test_a_swedish_shop_name_does_not_make_an_english_answer_swedish() {
        $text = 'Barnfåtölj LeatherSoft in light blue faux leather.';
        $config = ['product_name' => 'Barnfåtölj LeatherSoft'];

        $this->assertTrue(aat_eval_checks([], $text, $text, self::config($config + ['language' => 'en']))['language_ok']);
        $this->assertFalse(aat_eval_checks([], $text, $text, self::config($config + ['language' => 'sv']))['language_ok']);
    }

    public function test_language_is_not_applicable_without_stop_words() {
        $text = 'Black suede Chelsea boots Albert, side view.';

        $this->assertNull(aat_eval_checks([], $text, $text, self::config(['product_name' => 'Chelsea boots Albert']))['language_ok']);
    }

    public function test_recall_ignores_spaces_punctuation_case_and_accents() {
        $pairs = [['APELSIN JUICE', 'Apelsinjuice'], ['OAT-LY!', 'Oatly'], ['1KG', '1 kg'], ['100% frukt', '100 % frukt'], ['PAULÚNS', 'Pauluns']];
        foreach ($pairs as [$expected, $text]) {
            $this->assertSame(1.0, aat_eval_checks(['expected_text' => [$expected]], $text, $text, self::config())['text_recall'], $expected);
        }
        $this->assertSame(0.0, aat_eval_checks(['expected_text' => ['TOMATKETCHUP']], 'Felix Original', 'Felix Original', self::config())['text_recall']);
    }
}
