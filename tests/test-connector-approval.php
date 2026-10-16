<?php
class Auto_Alt_Text_Connector_Approval_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        Auto_Alt_Text_Connector_Status::clear();
        Auto_Alt_Text_Queue::clear_auth_pause();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
    }

    private function block_openai() {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            return new WP_Error('wpai_connector_not_approved', 'The "openai" AI connector has not been approved for use by "WP-Auto-Alt-Text/wp-auto-alt-text.php".');
        }, 10, 3);
    }

    private function log_messages() {
        global $wpdb;
        return $wpdb->get_col("SELECT CONCAT(message, ' ', context) FROM {$wpdb->prefix}auto_alt_text_logs");
    }

    public function test_blocked_connector_is_reported_and_classed_auth() {
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key('sk-test-not-real');
        $this->block_openai();
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $alt = $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertNull($alt);
        $this->assertSame('auth', $openai->get_last_error_class());
        $this->assertStringContainsString('AI connector approval', $openai->get_last_error());
        $this->assertTrue(Auto_Alt_Text_Connector_Status::is_blocked());
    }

    public function test_blocked_connector_pauses_queue_without_consuming_attempts() {
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        $this->block_openai();
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg'); // add_attachment enqueues it

        (new Auto_Alt_Text_Queue())->run();

        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d', $id
        ));
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $job->status);
        $this->assertSame('0', $job->attempts);
        $this->assertNotFalse(get_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT));
    }

    public function test_transport_errors_are_logged_with_their_real_message() {
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key('sk-test-not-real');
        $openai->set_max_api_attempts(1);
        add_filter('pre_http_request', function ($pre, $args, $url) {
            return strpos($url, 'openai.com') === false ? $pre : new WP_Error('http_request_failed', 'Example transport failure xyz123');
        }, 10, 3);
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertNotEmpty(array_filter($this->log_messages(), function ($line) {
            return false !== strpos($line, 'xyz123');
        }));
    }

    public function test_successful_call_clears_the_blocked_flag() {
        Auto_Alt_Text_Connector_Status::mark_blocked('blocked earlier');
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key('sk-test-not-real');
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['output_text' => 'Yellow canola field']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        }, 10, 3);
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertFalse(Auto_Alt_Text_Connector_Status::is_blocked());
    }

    public function test_notice_links_to_approval_page_only_on_plugin_and_media_screens() {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Auto_Alt_Text_Connector_Status::mark_blocked('blocked');

        set_current_screen('upload');
        ob_start();
        Auto_Alt_Text_Connector_Status::render_admin_notice();
        $html = ob_get_clean();
        $this->assertStringContainsString('tools.php?page=ai-connector-approval', $html);

        set_current_screen('dashboard');
        ob_start();
        Auto_Alt_Text_Connector_Status::render_admin_notice();
        $this->assertSame('', ob_get_clean());
    }

    public function test_notice_is_registered() {
        $this->assertNotFalse(has_action('admin_notices', ['Auto_Alt_Text_Connector_Status', 'render_admin_notice']));
    }
}
