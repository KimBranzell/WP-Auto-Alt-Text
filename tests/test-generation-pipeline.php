<?php
class Auto_Alt_Text_Generation_Pipeline_Test extends WP_UnitTestCase {
    private $requests = [];
    private $replies = [];

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        update_option('auto_alt_text_naming', 'visible');
        $this->requests = [];
        $this->replies = [];
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            $this->requests[] = json_decode((string) $args['body'], true);
            $reply = array_shift($this->replies) ?? 'Default alt text.';
            $body = is_array($reply) ? $reply : [
                'status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $reply]]]],
                'usage'  => ['input_tokens' => 1000, 'output_tokens' => 30, 'total_tokens' => 1030],
            ];
            return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        }, 10, 3);
    }

    private function openai() {
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key('sk-test-not-real');
        return $openai;
    }

    private function image($parent = 0) {
        return self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg', $parent);
    }

    private function product_image($title = 'Sandlåda Robust 350 L') {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
        $image = $this->image();
        $product = new WC_Product_Simple();
        $product->set_name($title);
        $product->set_image_id($image);
        $product->save();
        return $image;
    }

    /** A registry model that is not the current one; the default model changes over time. */
    private function other_model() {
        return array_values(array_diff(array_keys(Auto_Alt_Text_Models::all()), [Auto_Alt_Text_Models::current_id()]))[0];
    }

    private function content($index = 0) {
        return $this->requests[$index]['input'][0]['content'];
    }

    private function temp_count() {
        return count(glob(get_temp_dir() . 'aat-image*') ?: []);
    }

    private function cache_entries($attachment_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_aat_img_' . (int) $attachment_id . '_') . '%'
        ));
    }

    public function test_request_carries_rules_facts_and_a_base64_image() {
        $id = $this->image();

        $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertStringContainsString('Reply with the alt text only', $this->requests[0]['instructions']);
        $this->assertStringNotContainsString('25-40 words', $this->requests[0]['instructions']);
        $this->assertSame('Write alt text for this image.', $this->content()[0]['text']);
        $this->assertSame('auto', $this->content()[1]['detail']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $this->content()[1]['image_url']);
    }

    public function test_background_runs_send_auto_detail() {
        $id = $this->image();

        $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'upload');

        $this->assertSame('auto', $this->content()[1]['detail']);
    }

    public function test_output_is_cleaned_before_saving() {
        $this->replies[] = '"En gul rapsåker."';
        $id = $this->image();

        $this->assertSame('En gul rapsåker.', $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual'));
        $this->assertSame('En gul rapsåker.', get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    public function test_name_already_present_needs_no_extra_call() {
        update_option('auto_alt_text_naming', 'product');
        $this->replies[] = 'Sandlåda Robust 350 L i grön plast med lock.';
        $id = $this->product_image();

        $text = $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertSame('Sandlåda Robust 350 L i grön plast med lock.', $text);
        $this->assertCount(1, $this->requests);
    }

    public function test_missing_name_triggers_one_text_only_rewrite() {
        update_option('auto_alt_text_naming', 'product');
        $this->replies[] = 'En grön sandlåda med lock.';
        $this->replies[] = 'Sandlåda Robust 350 L, en grön låda med lock.';
        $id = $this->product_image();

        $text = $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertSame('Sandlåda Robust 350 L, en grön låda med lock.', $text);
        $this->assertCount(2, $this->requests);
        $this->assertCount(1, $this->content(1), 'The rewrite sends no image');
        $this->assertStringContainsString('includes the product name "Sandlåda Robust 350 L"', $this->content(1)[0]['text']);
    }

    public function test_name_is_prefixed_when_the_rewrite_still_misses_it() {
        update_option('auto_alt_text_naming', 'product');
        $this->replies[] = 'En grön sandlåda med lock.';
        $this->replies[] = 'En grön låda med lock.';
        $id = $this->product_image();

        $text = $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');

        $this->assertSame('Sandlåda Robust 350 L – En grön sandlåda med lock.', $text);
        $this->assertSame($text, get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($id));
    }

    public function test_visible_level_never_enforces_the_name() {
        $this->replies[] = 'En grön sandlåda med lock.';
        $id = $this->product_image();

        $this->assertSame('En grön sandlåda med lock.', $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true));
        $this->assertCount(1, $this->requests);
    }

    public function test_page_context_is_never_enforced() {
        update_option('auto_alt_text_naming', 'product_brand');
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Om oss']);
        $this->replies[] = 'Ett gult rapsfält.';
        $id = $this->image($page);

        $this->assertSame('Ett gult rapsfält.', $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true));
        $this->assertCount(1, $this->requests);
    }

    public function test_variation_passes_with_the_parent_product_name() {
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not loaded');
        }
        update_option('auto_alt_text_naming', 'product');
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
        $this->replies[] = 'Nordic Mug i blått på ett bord.';

        $this->assertSame('Nordic Mug i blått på ett bord.', $this->openai()->generate_alt_text(wp_get_attachment_url($image), $image, 'manual', true));
        $this->assertCount(1, $this->requests);
    }

    public function test_stats_row_records_model_and_tokens() {
        global $wpdb;
        $model = $this->other_model();
        update_option(Auto_Alt_Text_Models::OPTION, $model);
        $id = $this->image();

        $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');

        $row = $wpdb->get_row($wpdb->prepare("SELECT model, input_tokens, output_tokens, tokens_used FROM {$wpdb->prefix}auto_alt_text_stats WHERE image_id = %d", $id), ARRAY_A);
        $this->assertSame(['model' => $model, 'input_tokens' => '1000', 'output_tokens' => '30', 'tokens_used' => '1030'], $row);
    }

    public function test_cache_hit_serves_the_same_model_and_prompt_only() {
        $id = $this->image();
        $openai = $this->openai();

        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');
        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');
        $this->assertCount(1, $this->requests, 'Same image, model and prompt: served from the cache');

        update_option(Auto_Alt_Text_Models::OPTION, $this->other_model());
        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');
        $this->assertCount(2, $this->requests, 'A different model is a different cache entry');
    }

    public function test_failure_writes_nothing_and_removes_temp_files() {
        $before = $this->temp_count();
        $this->replies[] = ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No.']]]]];
        $id = $this->image();
        $openai = $this->openai();

        $this->assertNull($openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual'));
        $this->assertSame('permanent', $openai->get_last_error_class());
        $this->assertSame('', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame('', get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true));
        $this->assertSame(0, $this->cache_entries($id));
        $this->assertSame($before, $this->temp_count());
    }

    public function test_run_generation_reports_everything_and_writes_nothing() {
        global $wpdb;
        update_option('auto_alt_text_naming', 'product');
        $this->replies[] = 'En grön sandlåda med lock.';
        $this->replies[] = 'En grön låda.';
        $id = $this->product_image();
        $stats_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}auto_alt_text_stats");

        $model = $this->other_model();
        $result = $this->openai()->run_generation($id, 'batch', ['model' => $model, 'detail' => 'auto']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('En grön sandlåda med lock.', $result['first_text']);
        $this->assertSame('prefix', $result['name_fix']);
        $this->assertSame($model, $result['model']);
        $this->assertSame('auto', $result['detail']);
        $this->assertSame(2000, $result['input_tokens'], 'Usage includes the rewrite call');
        $this->assertSame('', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame($stats_before, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}auto_alt_text_stats"));
    }

    public function test_run_overrides_set_naming_and_model_without_touching_options() {
        update_option('auto_alt_text_naming', 'visible');
        $this->replies[] = 'Sandlåda Robust 350 L.';
        $id = $this->product_image();
        $openai = $this->openai();
        $model = $this->other_model();
        $openai->set_run_overrides(['naming' => 'product', 'model' => $model, 'detail' => 'low']);

        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'cli');

        $this->assertSame($model, $this->requests[0]['model']);
        $this->assertSame('low', $this->content()[1]['detail']);
        $this->assertStringContainsString('Include the product name', $this->requests[0]['instructions']);
        $this->assertSame('visible', get_option('auto_alt_text_naming'));
    }

    public function test_feedback_regeneration_sends_the_image_and_the_request() {
        $this->replies[] = 'En kortare text.';
        $id = $this->image();

        $text = $this->openai()->regenerate_alt_text_with_feedback(wp_get_attachment_url($id), $id, 'more_concise', '', 'En lång text om ett fält.');

        $this->assertSame('En kortare text.', $text);
        $this->assertSame('auto', $this->content()[1]['detail']);
        $this->assertStringContainsString('Previous alt text: "En lång text om ett fält."', $this->content()[0]['text']);
        $this->assertStringContainsString('shorter', $this->content()[0]['text']);
    }

    public function test_provenance_records_the_model_actually_used() {
        $model = $this->other_model();
        $id = $this->image();
        $openai = $this->openai();
        $openai->set_run_overrides(['model' => $model]);

        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'cli');

        $this->assertSame($model, get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true)['model']);
    }

    public function test_cache_key_changes_with_model_detail_and_file() {
        $id = $this->image();
        $parts = ['file' => Auto_Alt_Text_Image_Source::fingerprint($id), 'model' => 'a', 'detail' => 'auto', 'instructions' => 'x', 'input' => 'y'];
        $key = Auto_Alt_Text_Cache_Manager::get_cache_key($id, $parts);

        $this->assertNotSame($key, Auto_Alt_Text_Cache_Manager::get_cache_key($id, array_merge($parts, ['model' => 'b'])));
        $this->assertNotSame($key, Auto_Alt_Text_Cache_Manager::get_cache_key($id, array_merge($parts, ['detail' => 'low'])));

        touch(get_attached_file($id), time() + 3600);
        clearstatcache();
        $changed = Auto_Alt_Text_Image_Source::fingerprint($id);
        $this->assertNotSame($parts['file'], $changed, 'A new file mtime changes the fingerprint');
        $this->assertNotSame($key, Auto_Alt_Text_Cache_Manager::get_cache_key($id, array_merge($parts, ['file' => $changed])));
    }

    public function test_translations_are_exempt_from_the_name_guarantee() {
        update_option('auto_alt_text_naming', 'product');
        $this->replies[] = 'En grön låda med lock.';
        $this->product_image();

        $text = $this->openai()->translate_alt_text('A green box with a lid.', 'en', 'sv');

        $this->assertSame('En grön låda med lock.', $text);
        $this->assertCount(1, $this->requests, 'No rewrite request');
    }
}
