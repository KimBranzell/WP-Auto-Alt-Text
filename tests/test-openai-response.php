<?php
class Auto_Alt_Text_OpenAI_Response_Test extends WP_UnitTestCase {
    private $requests = [];
    private $responses = [];

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        $this->requests = [];
        $this->responses = [];
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            $this->requests[] = [
                'url'    => $url,
                'method' => $args['method'] ?? 'POST',
                'body'   => isset($args['body']) ? json_decode((string) $args['body'], true) : null,
            ];
            $next = array_shift($this->responses) ?? [200, ['output_text' => 'Fallback text']];
            return [
                'headers'  => [],
                'body'     => wp_json_encode($next[1]),
                'response' => ['code' => $next[0], 'message' => ''],
                'cookies'  => [],
            ];
        }, 10, 3);
    }

    private function queue_response($code, array $body) {
        $this->responses[] = [$code, $body];
    }

    private static function message($text) {
        return [
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]],
            'usage'  => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120],
        ];
    }

    private static function incomplete() {
        return ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => []];
    }

    private function openai() {
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key('sk-test-not-real');
        return $openai;
    }

    private function image() {
        return self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
    }

    public function test_request_uses_the_registry_model_and_is_not_stored() {
        $this->queue_response(200, self::message('A canola field.'));
        $id = $this->image();

        $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $body = $this->requests[0]['body'];
        $this->assertSame('gpt-5.2', $body['model']);
        $this->assertFalse($body['store']);
        $this->assertSame(300, $body['max_output_tokens']);
        $this->assertArrayNotHasKey('reasoning', $body, 'gpt-5.2 has no effort in the registry');
    }

    public function test_reasoning_effort_is_sent_when_the_model_has_one() {
        update_option(Auto_Alt_Text_Models::OPTION, 'gpt-6-luna');
        $this->queue_response(200, self::message('A canola field.'));
        $id = $this->image();

        $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertSame(['effort' => 'none'], $this->requests[0]['body']['reasoning']);
    }

    public function test_text_is_read_only_from_output_text_items() {
        $this->queue_response(200, [
            'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => [['type' => 'summary_text', 'text' => 'Thinking about the field']], 'content' => [['type' => 'reasoning_text', 'text' => 'Thinking about the field']]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A canola field.']]],
            ],
        ]);
        $id = $this->image();

        $text = $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertSame('A canola field.', $text);
    }

    public function test_incomplete_response_is_retried_once_with_twice_the_limit() {
        $this->queue_response(200, self::incomplete());
        $this->queue_response(200, self::message('A canola field.'));
        $id = $this->image();

        $text = $this->openai()->generate_alt_text(wp_get_attachment_url($id), $id, 'manual', true);

        $this->assertSame('A canola field.', $text);
        $this->assertCount(2, $this->requests);
        $this->assertSame(600, $this->requests[1]['body']['max_output_tokens']);
    }

    public function test_incomplete_twice_fails_permanently_without_writing() {
        $this->queue_response(200, self::incomplete());
        $this->queue_response(200, self::incomplete());
        $openai = $this->openai();
        $id = $this->image();

        $this->assertNull($openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual'));
        $this->assertSame('permanent', $openai->get_last_error_class());
        $this->assertStringContainsString('ran out of output tokens', $openai->get_last_error());
        $this->assertSame('', get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    public function test_refusal_fails_permanently_without_retry() {
        $this->queue_response(200, [
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that.']]]],
        ]);
        $openai = $this->openai();
        $id = $this->image();

        $this->assertNull($openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual'));
        $this->assertSame('permanent', $openai->get_last_error_class());
        $this->assertStringContainsString('declined', $openai->get_last_error());
        $this->assertCount(1, $this->requests);
        $this->assertSame('', get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    public function test_empty_response_is_transient() {
        $this->queue_response(200, ['status' => 'completed', 'output' => []]);
        $openai = $this->openai();
        $id = $this->image();

        $this->assertNull($openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual'));
        $this->assertSame('transient', $openai->get_last_error_class());
    }

    public function test_translation_puts_its_rules_in_the_instructions_field() {
        $this->queue_response(200, self::message('Ett rapsfält.'));

        $text = $this->openai()->translate_alt_text('A canola field.', 'en', 'sv');

        $this->assertSame('Ett rapsfält.', $text);
        $body = $this->requests[0]['body'];
        $this->assertStringContainsString('professional translator', $body['instructions']);
        $this->assertCount(1, $body['input']);
        $this->assertSame('user', $body['input'][0]['role']);
    }

    public function test_api_key_check_lists_models_without_spending_tokens() {
        $this->queue_response(200, ['data' => []]);

        $this->assertTrue($this->openai()->test_api_key());
        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertStringEndsWith('/v1/models', $this->requests[0]['url']);
    }

    public function test_api_key_check_fails_on_401() {
        $this->queue_response(401, ['error' => ['message' => 'Incorrect API key provided']]);

        $this->assertFalse($this->openai()->test_api_key());
    }

    public function test_model_availability_is_checked_per_model() {
        $this->queue_response(200, ['id' => 'gpt-6-luna']);
        $this->queue_response(404, ['error' => ['message' => 'The model does not exist']]);
        $openai = $this->openai();

        $this->assertTrue($openai->is_model_available('gpt-6-luna'));
        $this->assertFalse($openai->is_model_available('gpt-retired'));
        $this->assertStringEndsWith('/v1/models/gpt-retired', $this->requests[1]['url']);
    }

    public function test_model_availability_is_unknown_without_a_key() {
        delete_option('auto_alt_text_api_key');

        $this->assertNull((new Auto_Alt_Text_OpenAI())->is_model_available('gpt-6-luna'));
        $this->assertCount(0, $this->requests);
    }

    public function test_low_detail_images_are_estimated_far_cheaper_than_auto() {
        // Adopted from the WIP tests/test-openai.php, which passed only by accident (string lengths).
        $method = new ReflectionMethod(Auto_Alt_Text_OpenAI::class, 'estimate_request_tokens');
        $method->setAccessible(true);
        $request = function ($detail) {
            return [
                'input' => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => 'Describe this product image in concise alt text.'],
                        ['type' => 'input_image', 'image_url' => 'https://cdn.example.com/product.jpg', 'detail' => $detail],
                    ],
                ]],
                'max_output_tokens' => 120,
            ];
        };
        $openai = $this->openai();

        $low = $method->invoke($openai, $request('low'));
        $auto = $method->invoke($openai, $request('auto'));

        $this->assertLessThan($auto - 500, $low);
    }
}
