<?php
class Auto_Alt_Text_Privacy_Test extends WP_UnitTestCase {
    private function exporter() {
        $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
        $this->assertArrayHasKey('wp-auto-alt-text', $exporters);
        return $exporters['wp-auto-alt-text']['callback'];
    }

    public function test_unknown_email_returns_an_empty_finished_page() {
        $this->assertSame(['data' => [], 'done' => true], call_user_func($this->exporter(), 'nobody@example.org', 1));
    }

    public function test_exports_only_the_requesters_alt_texts_in_core_shape() {
        $owner = self::factory()->user->create(['user_email' => 'owner@example.org']);
        $other = self::factory()->user->create(['user_email' => 'other@example.org']);
        $mine = self::factory()->attachment->create(['post_author' => $owner, 'post_mime_type' => 'image/jpeg']);
        self::factory()->attachment->create(['post_author' => $owner, 'post_mime_type' => 'image/jpeg']); // no alt
        $theirs = self::factory()->attachment->create(['post_author' => $other, 'post_mime_type' => 'image/jpeg']);
        update_post_meta($mine, '_wp_attachment_image_alt', 'My alt');
        update_post_meta($theirs, '_wp_attachment_image_alt', 'Their alt');

        $result = call_user_func($this->exporter(), 'owner@example.org', 1);

        $this->assertTrue($result['done']);
        $this->assertCount(1, $result['data']);
        $item = $result['data'][0];
        $this->assertSame('wp-auto-alt-text', $item['group_id']);
        $this->assertSame('aat-attachment-' . $mine, $item['item_id']);
        $this->assertSame('My alt', $item['data'][1]['value']);
    }

    public function test_export_query_opts_out_of_multilingual_language_filtering() {
        // Polylang filters attachment queries by the current language in
        // parse_query unless 'lang' is set to an empty string.
        self::factory()->user->create(['user_email' => 'owner@example.org']);
        $query_vars = null;
        add_action('pre_get_posts', function ($query) use (&$query_vars) {
            $query_vars = $query->query;
        });

        call_user_func($this->exporter(), 'owner@example.org', 1);

        $this->assertIsArray($query_vars);
        $this->assertArrayHasKey('lang', $query_vars);
        $this->assertSame('', $query_vars['lang']);
    }
}
