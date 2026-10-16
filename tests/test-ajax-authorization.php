<?php
/**
 * AJAX generation endpoints spend API budget (and the batch ones write alt
 * text) per attachment: each attachment must be editable by the user.
 */
class Auto_Alt_Text_Ajax_Authorization_Test extends WP_Ajax_UnitTestCase {
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
        $this->_setRole('author');
    }

    private function ajax($action, array $post) {
        $_POST = $post;

        global $wpdb;
        // WooCommerce's HPOS sync check on admin_init prints a temporary-table
        // DB error into the AJAX buffer in the test harness; keep the JSON clean.
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

    public function test_single_generate_rejects_an_attachment_the_user_cannot_edit() {
        $response = $this->ajax('generate_alt_text_for_attachment', [
            'nonce'         => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id' => $this->foreign_id,
            'preview'       => 'true',
        ]);

        $this->assertFalse($response['success'], $this->_last_response);
        $this->assertSame(0, $this->openai_calls);
    }

    public function test_regenerate_rejects_an_attachment_the_user_cannot_edit() {
        $response = $this->ajax('regenerate_alt_text_with_feedback', [
            'nonce'            => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id'    => $this->foreign_id,
            'improvement_type' => 'more_detailed',
        ]);

        $this->assertFalse($response['success'], $this->_last_response);
        $this->assertSame(0, $this->openai_calls);
    }

    public function test_batch_skips_attachments_the_user_cannot_edit() {
        $response = $this->ajax('process_image_batch', [
            'nonce' => wp_create_nonce('auto_alt_text_batch_nonce'),
            'ids'   => wp_json_encode([$this->foreign_id]),
        ]);

        $this->assertFalse($response['data'][$this->foreign_id]['success'], $this->_last_response);
        $this->assertSame(0, $this->openai_calls);
        $this->assertSame('', get_post_meta($this->foreign_id, '_wp_attachment_image_alt', true));
    }

    public function test_media_folder_batch_skips_attachments_the_user_cannot_edit() {
        // Registered on init only in wp-admin; register it for this request.
        new Auto_Alt_Text_WP_Media_Folder();
        $response = $this->ajax('wpmf_process_alt_text_batch', [
            'nonce' => wp_create_nonce('auto_alt_text_wpmf_nonce'),
            'step'  => 'process',
            'ids'   => wp_json_encode([$this->foreign_id]),
        ]);

        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertSame([], $response['data']['results']);
        $this->assertSame(0, $this->openai_calls);
        $this->assertSame('', get_post_meta($this->foreign_id, '_wp_attachment_image_alt', true));
    }
}
