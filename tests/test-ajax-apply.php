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

        global $wpdb;
        // WooCommerce's HPOS sync check on admin_init prints a temporary-table
        // DB error into the AJAX buffer in the test harness; keep the JSON clean.
        $suppress = $wpdb->suppress_errors(true);
        try {
            $this->_handleAjax('apply_alt_text');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request.
        } finally {
            $wpdb->suppress_errors($suppress);
        }

        $response = json_decode($this->_last_response, true);
        $this->assertTrue($response['success'], $this->_last_response);
    }
}
