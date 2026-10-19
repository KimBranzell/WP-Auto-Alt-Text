<?php
class Auto_Alt_Text_Context_Prompt_Test extends WP_UnitTestCase {
    private $bodies = [];

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        $this->bodies = [];
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            $this->bodies[] = json_decode((string) $args['body'], true);
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['output_text' => 'Generated alt text']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        }, 10, 3);
    }

    private function openai() {
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key('sk-test-not-real');
        return $openai;
    }

    private function generate_for_image_on_page() {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Nordic Mug']);
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg', $page);
        $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);
        return end($this->bodies);
    }

    public function test_prompt_names_the_page_as_context() {
        update_option('auto_alt_text_naming', 'visible');

        $body = $this->generate_for_image_on_page();

        $this->assertStringContainsString('This image belongs to the page "Nordic Mug".', $body['input'][0]['content'][0]['text']);
        $this->assertStringNotContainsString('Include the product name', $body['instructions']);
    }

    public function test_page_titles_are_never_forced_into_the_text() {
        update_option('auto_alt_text_naming', 'product_brand');

        $body = $this->generate_for_image_on_page();

        $this->assertStringNotContainsString('Include the product name', $body['instructions']);
    }

    public function test_prompt_without_context_has_no_context_line() {
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertStringNotContainsString('This image belongs to', end($this->bodies)['input'][0]['content'][0]['text']);
    }

    public function test_cache_key_varies_by_context() {
        $a = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Page A']);
        $b = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Page B']);
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $openai = $this->openai();

        foreach ([$a, $b, $a] as $hint) {
            $openai->set_context_post_id($hint);
            $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'upload');
        }

        $this->assertCount(2, $this->bodies, 'Page A and page B each miss the cache once; the second page A hits it');
    }

    public function test_context_hint_applies_to_one_generation_only() {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Nordic Mug']);
        $first = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $second = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $openai = $this->openai();

        $openai->set_context_post_id($page);
        $openai->generate_alt_text(wp_get_attachment_url($first), $first, 'manual', true);
        $openai->generate_alt_text(wp_get_attachment_url($second), $second, 'manual', true);

        $this->assertStringContainsString('Nordic Mug', $this->bodies[0]['input'][0]['content'][0]['text']);
        $this->assertStringNotContainsString('This image belongs to', $this->bodies[1]['input'][0]['content'][0]['text']);
    }
}
