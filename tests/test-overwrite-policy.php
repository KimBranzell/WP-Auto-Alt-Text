<?php
class Auto_Alt_Text_Overwrite_Policy_Rest_Test extends WP_UnitTestCase {
    public function test_rest_batch_keeps_human_alt_text_under_default_policy() {
        delete_option(Auto_Alt_Text_Provenance::POLICY_OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') !== false) {
                $this->fail('OpenAI must not be called for a skipped image');
            }
            return $pre;
        }, 10, 3);
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', 'Written by an editor');

        $request = new WP_REST_Request('POST', '/wp-auto-alt-text/v1/batch');
        $request->set_param('attachment_ids', [$id]);
        $data = rest_do_request($request)->get_data();

        $this->assertTrue($data['results'][$id]['skipped']);
        $this->assertSame('Written by an editor', get_post_meta($id, '_wp_attachment_image_alt', true));
    }
}

class Auto_Alt_Text_Overwrite_Policy_Ajax_Test extends WP_Ajax_UnitTestCase {
    public function test_media_bulk_keeps_human_alt_text_under_default_policy() {
        delete_option(Auto_Alt_Text_Provenance::POLICY_OPTION);
        $this->_setRole('administrator');
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') !== false) {
                $this->fail('OpenAI must not be called for a skipped image');
            }
            return $pre;
        }, 10, 3);
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', 'Written by an editor');
        $_POST = [
            'nonce' => wp_create_nonce('auto_alt_text_batch_nonce'),
            'ids'   => wp_json_encode([$id]),
        ];

        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        try {
            $this->_handleAjax('process_image_batch');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request.
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        $response = json_decode($this->_last_response, true);

        $this->assertTrue($response['data'][$id]['skipped'], $this->_last_response);
    }

    public function test_media_folder_batch_keeps_human_alt_text_under_default_policy() {
        delete_option(Auto_Alt_Text_Provenance::POLICY_OPTION);
        $this->_setRole('administrator');
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        $openai_calls = 0;
        add_filter('pre_http_request', function ($pre, $args, $url) use (&$openai_calls) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            $openai_calls++;
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
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', 'Written by an editor');
        // Registered on init only in wp-admin; register it for this request.
        new Auto_Alt_Text_WP_Media_Folder();
        $_POST = [
            'nonce' => wp_create_nonce('auto_alt_text_wpmf_nonce'),
            'step'  => 'process',
            'ids'   => wp_json_encode([$id]),
        ];

        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        try {
            $this->_handleAjax('wpmf_process_alt_text_batch');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request.
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        $response = json_decode($this->_last_response, true);

        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertArrayNotHasKey($id, $response['data']['results']);
        $this->assertSame(0, $openai_calls);
        $this->assertSame('Written by an editor', get_post_meta($id, '_wp_attachment_image_alt', true));
    }
}
