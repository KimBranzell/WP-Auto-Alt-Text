<?php
/**
 * REST endpoints write alt text and spend API budget per attachment, so
 * upload_files alone is not enough: the user must be able to edit each one.
 */
class Auto_Alt_Text_REST_Authorization_Test extends WP_UnitTestCase {
    private $openai_calls = 0;
    private $foreign_id;

    public function setUp(): void {
        parent::setUp();
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        $this->openai_calls = 0;
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            $this->openai_calls++;
            return [
                'headers'  => [],
                'body'     => wp_json_encode([
                    'output' => [[ 'type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A yellow canola field.']] ]],
                    'usage'  => ['total_tokens' => 42],
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        }, 10, 3);

        // An editor's image; the current user is an Author (upload_files, but
        // no edit_others_posts).
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->foreign_id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
    }

    public function test_generate_is_forbidden_for_an_attachment_the_user_cannot_edit() {
        $this->assertTrue(current_user_can('upload_files'));
        $request = new WP_REST_Request('POST', '/wp-auto-alt-text/v1/generate');
        $request->set_param('attachment_id', $this->foreign_id);

        $response = rest_do_request($request);

        $this->assertSame(403, $response->get_status());
        $this->assertSame(0, $this->openai_calls, 'No API budget may be spent on a forbidden attachment');
        $this->assertSame('', get_post_meta($this->foreign_id, '_wp_attachment_image_alt', true));
    }

    public function test_generate_still_works_for_the_users_own_attachment() {
        $own_id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $request = new WP_REST_Request('POST', '/wp-auto-alt-text/v1/generate');
        $request->set_param('attachment_id', $own_id);

        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $this->openai_calls);
    }

    public function test_batch_reports_permission_failure_per_attachment() {
        $own_id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $request = new WP_REST_Request('POST', '/wp-auto-alt-text/v1/batch');
        $request->set_param('attachment_ids', [$this->foreign_id, $own_id]);

        $data = rest_do_request($request)->get_data();

        $this->assertFalse($data['results'][$this->foreign_id]['success']);
        $this->assertSame('Permission denied', $data['results'][$this->foreign_id]['message']);
        $this->assertTrue($data['results'][$own_id]['success']);
        $this->assertSame(1, $this->openai_calls, 'Only the editable attachment may reach OpenAI');
        $this->assertSame('', get_post_meta($this->foreign_id, '_wp_attachment_image_alt', true));
    }
}
