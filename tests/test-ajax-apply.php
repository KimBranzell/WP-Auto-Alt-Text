<?php
class Auto_Alt_Text_Ajax_Apply_Test extends WP_Ajax_UnitTestCase {
    public function test_applying_unchanged_alt_text_succeeds() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', 'Same text');
        $_POST = [
            'nonce'         => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id' => $id,
            'alt_text'      => 'Same text',
            'original_text' => 'Same text',
            'is_edited'     => '0',
        ];

        $ob_level = ob_get_level();
        try {
            $this->_handleAjax('apply_alt_text');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request.
        }

        // The swallowed die-exception makes the harness unwind one buffer too
        // many (or too few); restore PHPUnit's own buffer level.
        while (ob_get_level() > $ob_level) {
            ob_end_clean();
        }
        while (ob_get_level() < $ob_level) {
            ob_start();
        }

        // apply_alt_text() wraps wp_send_json_* in try/catch(Exception); under the
        // test harness the die-exception is caught and a second JSON payload is
        // appended. Assert on the first one, which is the real response.
        $this->assertSame(1, preg_match('/\{"success":.*?\}\}/', $this->_last_response, $m), $this->_last_response);
        $response = json_decode($m[0], true);
        $this->assertTrue($response['success'], $this->_last_response);
    }
}
