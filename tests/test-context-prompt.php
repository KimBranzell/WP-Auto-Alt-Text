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
            $this->bodies[] = (string) $args['body'];
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['output_text' => 'Generated alt text']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        }, 10, 3);
    }

    public function tearDown(): void {
        delete_option('wp_auto_alt_text_enable_brand_tonality');
        parent::tearDown();
    }

    private function generate_for_image_on_page() {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Nordic Mug']);
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg', $page);
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key('sk-test-not-real');
        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);
        return end($this->bodies);
    }

    public function test_prompt_names_the_page_and_only_identifies_without_brand_tonality() {
        update_option('wp_auto_alt_text_enable_brand_tonality', false);

        $body = $this->generate_for_image_on_page();

        $this->assertStringContainsString('This image belongs to the page \"Nordic Mug\"', $body);
        $this->assertStringContainsString('describe what is visible', $body);
        $this->assertStringNotContainsString('naturally in the alt text', $body);
    }

    public function test_prompt_asks_to_include_the_name_with_brand_tonality() {
        update_option('wp_auto_alt_text_enable_brand_tonality', true);

        $body = $this->generate_for_image_on_page();

        $this->assertStringContainsString('Include \"Nordic Mug\" naturally in the alt text', $body);
    }

    public function test_prompt_without_context_has_no_context_line() {
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key('sk-test-not-real');
        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertStringNotContainsString('This image belongs to', end($this->bodies));
    }
}
