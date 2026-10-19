<?php
class Auto_Alt_Text_OpenAI_Errors_Test extends WP_UnitTestCase {
    private $openai;
    private $request_count = 0;

    public function setUp(): void {
        parent::setUp();
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        add_filter('pre_wp_mail', '__return_true');
        $this->openai = new Auto_Alt_Text_OpenAI();
        $this->openai->save_api_key('sk-test-not-real');
        $this->request_count = 0;
    }

    /** Helper: make every OpenAI HTTP call return the given mock response. */
    private function mock_api_response($http_code, $body_array) {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($http_code, $body_array) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            $this->request_count++;
            return [
                'headers'  => [],
                'body'     => wp_json_encode($body_array),
                'response' => ['code' => $http_code, 'message' => ''],
                'cookies'  => [],
            ];
        }, 10, 3);
    }

    private function generate() {
        $id = self::factory()->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );
        return [$this->openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual'), $id];
    }

    public function test_insufficient_quota_is_terminal_and_records_incident() {
        $this->mock_api_response(429, ['error' => [
            'message' => 'You exceeded your current quota, please check your plan and billing details.',
            'type' => 'insufficient_quota', 'code' => 'insufficient_quota',
        ]]);
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame('quota', $this->openai->get_last_error_class());
        $this->assertSame(1, $this->request_count, 'Quota errors must never be retried');
        $this->assertTrue(Auto_Alt_Text_Quota_Monitor::is_active());
    }

    public function test_billing_hard_limit_is_terminal() {
        $this->mock_api_response(429, ['error' => [
            'message' => 'Billing hard limit has been reached',
            'type' => 'insufficient_quota', 'code' => 'billing_hard_limit_reached',
        ]]);
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame('quota', $this->openai->get_last_error_class());
        $this->assertSame(1, $this->request_count);
    }

    public function test_server_error_is_transient_with_two_sync_attempts() {
        $this->mock_api_response(500, ['error' => ['message' => 'server error', 'type' => 'server_error']]);
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame('transient', $this->openai->get_last_error_class());
        $this->assertSame(2, $this->request_count, 'Sync path retries once (2 attempts total)');
    }

    public function test_malformed_200_is_transient_and_retried() {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            $this->request_count++;
            return [
                'headers'  => [],
                'body'     => '', // 200 with empty body: proxy/transport artifact
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        }, 10, 3);
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame('transient', $this->openai->get_last_error_class(), 'A malformed 200 must not be classified permanent');
        $this->assertSame(2, $this->request_count, 'Sync path retries a malformed 200 once');
    }

    public function test_max_attempts_one_makes_single_request() {
        $this->mock_api_response(500, ['error' => ['message' => 'server error', 'type' => 'server_error']]);
        $this->openai->set_max_api_attempts(1);
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame(1, $this->request_count);
    }

    public function test_auth_error_has_its_own_class_and_is_not_a_quota_incident() {
        $this->mock_api_response(401, ['error' => ['message' => 'Incorrect API key provided', 'code' => 'invalid_api_key']]);
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame('auth', $this->openai->get_last_error_class());
        $this->assertSame(1, $this->request_count);
        $this->assertFalse(Auto_Alt_Text_Quota_Monitor::is_active());
    }

    public function test_model_the_key_cannot_use_is_named_in_a_permanent_error() {
        $this->mock_api_response(404, ['error' => [
            'message' => 'The model `gpt-6-luna` does not exist or you do not have access to it.',
            'type'    => 'invalid_request_error',
            'code'    => 'model_not_found',
        ]]);
        $this->openai->set_run_overrides(['model' => 'gpt-6-luna']);

        [$result, $id] = $this->generate();

        $this->assertNull($result);
        $this->assertSame('permanent', $this->openai->get_last_error_class());
        $this->assertSame(
            'The model gpt-6-luna is not available to this OpenAI API key. Choose another model under Auto Alt Text → Settings.',
            $this->openai->get_last_error()
        );
        $this->assertSame(1, $this->request_count);
        $this->assertSame('', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame('', get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true));
        $this->assertFalse(get_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT));
    }

    public function test_any_404_from_the_responses_endpoint_names_the_requested_model() {
        $this->mock_api_response(404, ['error' => ['message' => 'Not found']]);

        $this->generate();

        $this->assertSame('permanent', $this->openai->get_last_error_class());
        $this->assertStringContainsString('The model ' . Auto_Alt_Text_Models::current_id() . ' is not available', $this->openai->get_last_error());
    }

    public function test_success_lifts_the_queue_auth_pause() {
        set_transient('aat_queue_auth_pause', 1, 15 * MINUTE_IN_SECONDS);
        $this->mock_api_response(200, [
            'output' => [[ 'type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A yellow canola field.']] ]],
            'usage' => ['total_tokens' => 42],
        ]);
        [$result] = $this->generate();
        $this->assertSame('A yellow canola field.', $result);
        $this->assertFalse(get_transient('aat_queue_auth_pause'));
    }

    public function test_success_clears_quota_incident() {
        Auto_Alt_Text_Quota_Monitor::record_failure('previous incident');
        $this->mock_api_response(200, [
            'output' => [[ 'type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A yellow canola field.']] ]],
            'usage' => ['total_tokens' => 42],
        ]);
        [$result] = $this->generate();
        $this->assertSame('A yellow canola field.', $result);
        $this->assertFalse(Auto_Alt_Text_Quota_Monitor::is_active());
    }

    /**
     * Fix 1 regression test. Chosen at the OpenAI level (rather than
     * tests/test-queue.php) because reproducing generate_alt_text()'s exact
     * cache-context (resolved language + instruction) from outside the class
     * is brittle; priming the cache via a real, mocked-success
     * generate_alt_text() call guarantees the context matches exactly what
     * the cache-hit branch under test will compute.
     */
    public function test_cache_hit_persists_alt_text() {
        $this->mock_api_response(200, [
            'output' => [[ 'type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A yellow canola field.']] ]],
            'usage' => ['total_tokens' => 42],
        ]);
        $id = self::factory()->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );

        // Prime the cache with a real API call, then clear the meta it wrote
        // so the second call can only pass via the cache-hit branch.
        $first = $this->openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');
        $this->assertSame('A yellow canola field.', $first);
        $this->assertSame(1, $this->request_count);
        delete_post_meta($id, '_wp_attachment_image_alt');

        $cached = $this->openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');

        $this->assertSame('A yellow canola field.', $cached);
        $this->assertSame(1, $this->request_count, 'Second call must be served from cache, not the API');
        $this->assertSame(
            'A yellow canola field.',
            get_post_meta($id, '_wp_attachment_image_alt', true),
            'Cache-hit path must persist alt text to post meta'
        );
    }

    public function test_missing_api_key_is_permanent_error_class() {
        delete_option('auto_alt_text_api_key');
        $openai = new Auto_Alt_Text_OpenAI();
        $id = self::factory()->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );

        $result = $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');

        $this->assertNull($result);
        $this->assertSame('permanent', $openai->get_last_error_class());
    }
}
