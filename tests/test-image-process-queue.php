<?php
class Auto_Alt_Text_Image_Process_Queue_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . Auto_Alt_Text_Queue::get_table_name());
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') !== false) {
                $this->fail('OpenAI must not be called during upload requests');
            }
            return $pre;
        }, 10, 3);
    }

    public function test_new_attachment_enqueues_one_upload_job() {
        // create_upload_object fires add_attachment, which the plugin hooks.
        $id = self::factory()->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );
        global $wpdb;
        $jobs = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d', $id
        ));
        $this->assertCount(1, $jobs, 'Exactly one job row (enqueue dedupe)');
        $this->assertSame('upload', $jobs[0]->generation_type);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $jobs[0]->status);
    }

    public function test_handle_new_attachment_is_hooked_exactly_once() {
        global $wp_filter;
        $count = 0;
        $total_hooks = 0;
        if (isset($wp_filter['add_attachment'])) {
            foreach ($wp_filter['add_attachment']->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    $total_hooks++;
                    $fn = $callback['function'];
                    if (is_array($fn) && $fn[0] instanceof Auto_Alt_Text_Image_Process && 'handle_new_attachment' === $fn[1]) {
                        $count++;
                    }
                }
            }
        }
        $this->assertSame(1, $count, "Found $count matching hooks out of $total_hooks total on add_attachment");
    }

    public function test_non_image_attachment_enqueues_nothing() {
        $id = self::factory()->attachment->create(['post_mime_type' => 'application/pdf']);
        do_action('add_attachment', $id);
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d', $id
        ));
        $this->assertSame('0', $count);
    }

    public function test_upload_enqueues_nothing_when_auto_generate_disabled() {
        // Two-step set: on a fresh install the option row doesn't exist yet,
        // and update_option($opt, false) short-circuits as a no-op because
        // get_option() (no default arg) already returns false — WordPress
        // never creates the row. Priming with `true` first forces the row
        // to exist so the following `false` write actually persists.
        update_option('auto_alt_text_auto_generate', true);
        update_option('auto_alt_text_auto_generate', false);
        try {
            $id = self::factory()->attachment->create_upload_object(
                DIR_TESTDATA . '/images/canola.jpg'
            );
            global $wpdb;
            $count = $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d', $id
            ));
            $this->assertSame('0', $count);
        } finally {
            update_option('auto_alt_text_auto_generate', true);
        }
    }
}
