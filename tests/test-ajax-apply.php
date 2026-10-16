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

    /** Runs apply_alt_text with $_POST slashed the way core's wp_magic_quotes() does. */
    private function apply_slashed(array $post) {
        $_POST = wp_slash($post);

        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        try {
            $this->_handleAjax('apply_alt_text');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request.
        } finally {
            $wpdb->suppress_errors($suppress);
        }

        return json_decode($this->_last_response, true);
    }

    public function test_applying_unchanged_alt_text_with_apostrophe_succeeds() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', "It's a test");

        $response = $this->apply_slashed([
            'nonce'         => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id' => $id,
            'alt_text'      => "It's a test",
            'original_text' => "It's a test",
            'is_edited'     => '0',
        ]);

        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertSame("It's a test", get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    public function test_applying_edited_alt_text_with_apostrophe_updates_meta_and_stats_unslashed() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        global $wpdb;
        $stats_table = $wpdb->prefix . 'auto_alt_text_stats';
        $wpdb->insert($stats_table, [
            'image_id'        => $id,
            'user_id'         => get_current_user_id(),
            'generated_text'  => "A dog's bowl",
            'tokens_used'     => 10,
            'generation_type' => 'manual',
            'generation_time' => current_time('mysql'),
        ]);
        $stats_id = $wpdb->insert_id;

        $response = $this->apply_slashed([
            'nonce'         => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id' => $id,
            'alt_text'      => "A dog's red bowl",
            'original_text' => "A dog's bowl",
            'is_edited'     => '1',
        ]);

        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertSame("A dog's red bowl", get_post_meta($id, '_wp_attachment_image_alt', true));
        $row = $wpdb->get_row($wpdb->prepare("SELECT is_applied, edited_text FROM {$stats_table} WHERE id = %d", $stats_id));
        $this->assertSame('1', $row->is_applied, 'Stats row for a quoted text must be matched');
        $this->assertSame("A dog's red bowl", $row->edited_text);
    }

    private function apply_text($attachment_id, $alt_text, $original_text) {
        $_POST = [
            'nonce'         => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id' => $attachment_id,
            'alt_text'      => wp_slash($alt_text),
            'original_text' => wp_slash($original_text),
            'is_edited'     => $alt_text === $original_text ? '0' : '1',
        ];
        global $wpdb;
        // WooCommerce's HPOS check on admin_init prints a temp-table DB error in the harness.
        $suppress = $wpdb->suppress_errors(true);
        try {
            $this->_handleAjax('apply_alt_text');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request.
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        $response = json_decode($this->_last_response, true);
        $this->_last_response = '';
        return $response;
    }

    public function test_applying_generated_text_unchanged_keeps_it_ai() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $response = $this->apply_text($id, "A barn's red door", "A barn's red door");

        $this->assertTrue($response['success'], wp_json_encode($response));
        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($id));
    }

    public function test_applying_edited_text_makes_it_human() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', 'Old AI text');
        Auto_Alt_Text_Provenance::record($id, 'upload');

        $response = $this->apply_text($id, 'Editor version', 'Generated version');

        $this->assertTrue($response['success'], wp_json_encode($response));
        $this->assertSame('human', Auto_Alt_Text_Provenance::classify($id));
    }
}
