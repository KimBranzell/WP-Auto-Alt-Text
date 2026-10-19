<?php
class Auto_Alt_Text_Context_Field_Test extends WP_UnitTestCase {
    private function image_on_page() {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Om oss']);
        return self::factory()->attachment->create(['post_mime_type' => 'image/jpeg', 'post_parent' => $page, 'file' => 'field-test.jpg']);
    }

    private function fields_for($attachment_id) {
        return apply_filters('attachment_fields_to_edit', [], get_post($attachment_id));
    }

    public function test_edit_screen_renders_the_explanation() {
        set_current_screen('attachment');
        $id = $this->image_on_page();

        $field = $this->fields_for($id)['aat_alt_context'];

        $this->assertSame('Alt-textkontext', $field['label']);
        $this->assertStringContainsString('data-loaded="1"', $field['html']);
        $this->assertStringContainsString('Sida: Om oss (uppladdad till)', $field['html']);
    }

    public function test_media_modal_gets_a_placeholder_without_any_lookup() {
        add_filter('wp_doing_ajax', '__return_true');
        $id = $this->image_on_page();

        // Prime the caches the other field callbacks use, so only a context lookup could query.
        $post = get_post($id);
        get_post_meta($id);
        wp_attachment_is_image($id);

        // Run only the plugin's field callback and record every SQL statement.
        $queries = [];
        $spy = function ($sql) use (&$queries) {
            $queries[] = $sql;
            return $sql;
        };
        $callback = null;
        global $wp_filter;
        foreach ($wp_filter['attachment_fields_to_edit']->callbacks[11] ?? [] as $registered) {
            if (is_array($registered['function']) && 'add_context_field' === $registered['function'][1]) {
                $callback = $registered['function'];
            }
        }
        $this->assertNotNull($callback, 'add_context_field must be registered at priority 11');

        add_filter('query', $spy);
        $before = $GLOBALS['wpdb']->num_queries;
        $html = call_user_func($callback, [], $post)['aat_alt_context']['html'];
        $after = $GLOBALS['wpdb']->num_queries;
        remove_filter('query', $spy);

        $this->assertSame([], $queries, 'Modal mode must not query the database');
        $this->assertSame($before, $after);
        $this->assertStringContainsString('data-attachment-id="' . $id . '"', $html);
        $this->assertStringContainsString('data-nonce="', $html);
        $this->assertStringNotContainsString('Om oss', $html, 'The context is fetched lazily, never in grid responses');
        $this->assertStringNotContainsString('data-loaded', $html);
    }

    public function test_titles_are_escaped() {
        set_current_screen('attachment');
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Kök & <b>bad</b>']);
        $id = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg', 'post_parent' => $page, 'file' => 'field-test.jpg']);

        $html = $this->fields_for($id)['aat_alt_context']['html'];

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('Kök &amp; bad', $html);
    }

    public function test_non_images_get_no_field() {
        $pdf = self::factory()->attachment->create(['post_mime_type' => 'application/pdf', 'file' => 'doc.pdf']);

        $this->assertArrayNotHasKey('aat_alt_context', $this->fields_for($pdf));
    }

    public function tearDown(): void {
        set_current_screen('front');
        parent::tearDown();
    }
}
