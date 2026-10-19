<?php
class Auto_Alt_Text_Ajax_Context_Test extends WP_Ajax_UnitTestCase {
    private $foreign_id;

    public function setUp(): void {
        parent::setUp();
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['output_text' => 'Ett gult rapsfält.']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        }, 10, 3);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->foreign_id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
    }

    private function ajax($action, array $post) {
        $_POST = $post;
        global $wpdb;
        // WooCommerce's HPOS sync check prints a temporary-table DB error into the buffer in tests.
        $suppress = $wpdb->suppress_errors(true);
        try {
            $this->_handleAjax($action);
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request.
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        return json_decode($this->_last_response, true);
    }

    public function test_context_is_returned_for_an_editable_image() {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Om oss']);
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg', $page);

        $response = $this->ajax('aat_get_context', ['nonce' => wp_create_nonce('auto_alt_text_nonce'), 'attachment_id' => $id]);

        $this->assertTrue($response['success']);
        $this->assertSame('Sida: Om oss (uppladdad till)', $response['data']['text']);
        $this->assertSame('found', $response['data']['explanation']['reason']);
    }

    public function test_context_is_refused_for_an_image_the_user_cannot_edit() {
        $this->_setRole('author');

        $response = $this->ajax('aat_get_context', ['nonce' => wp_create_nonce('auto_alt_text_nonce'), 'attachment_id' => $this->foreign_id]);

        $this->assertFalse($response['success']);
    }

    public function test_generate_response_includes_the_context_line() {
        $response = $this->ajax('generate_alt_text_for_attachment', [
            'nonce'         => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id' => $this->foreign_id,
            'preview'       => 'true',
        ]);

        $this->assertTrue($response['success']);
        $this->assertSame('Ingen produkt eller sida hittades', $response['data']['context']);
    }
}
