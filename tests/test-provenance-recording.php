<?php
class Auto_Alt_Text_Provenance_Recording_Test extends WP_UnitTestCase {
    private $requests = 0;

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        $this->requests = 0;
    }

    private function mock_openai_text($text) {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($text) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            $this->requests++;
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['output_text' => $text]),
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

    public function test_generation_records_ai_provenance_with_its_source() {
        $openai = $this->openai();
        $this->mock_openai_text('Yellow canola field under a blue sky');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'cli');

        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($id));
        $this->assertSame('cli', get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true)['source']);
    }

    public function test_preview_generation_records_nothing() {
        $openai = $this->openai();
        $this->mock_openai_text('Yellow canola field under a blue sky');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertSame('', get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true));
    }

    public function test_cached_response_is_recorded_as_ai() {
        $openai = $this->openai();
        $this->mock_openai_text('Yellow canola field under a blue sky');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'upload');
        delete_post_meta($id, '_wp_attachment_image_alt');
        Auto_Alt_Text_Provenance::forget($id);

        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'batch');

        $this->assertSame(1, $this->requests, 'Second generation must come from the cache');
        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($id));
        $this->assertSame('batch', get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true)['source']);
    }
}
