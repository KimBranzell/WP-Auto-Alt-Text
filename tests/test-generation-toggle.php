<?php
class Auto_Alt_Text_Generation_Toggle_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        // Two-step set: update_option($opt, false) on a missing row is a no-op.
        update_option('auto_alt_text_auto_generate', true);
        update_option('auto_alt_text_auto_generate', false);
    }

    public function tearDown(): void {
        update_option('auto_alt_text_auto_generate', true);
        parent::tearDown();
    }

    public function test_manual_generation_works_when_auto_generate_disabled() {
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key('sk-test-not-real');
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['output_text' => 'Yellow canola field under a blue sky']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        }, 10, 3);
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $alt = $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertSame('Yellow canola field under a blue sky', $alt);
    }
}
