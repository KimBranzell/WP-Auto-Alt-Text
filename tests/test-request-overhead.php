<?php
class Auto_Alt_Text_Request_Overhead_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
    }

    public function test_schema_upgrade_is_skipped_when_version_is_current() {
        $this->assertFalse(Auto_Alt_Text_Activator::maybe_upgrade());
    }

    public function test_schema_upgrade_runs_when_version_is_missing() {
        delete_option(Auto_Alt_Text_Activator::DB_VERSION_OPTION);
        $this->assertTrue(Auto_Alt_Text_Activator::maybe_upgrade());
        $this->assertFalse(Auto_Alt_Text_Activator::maybe_upgrade());
    }

    public function test_schema_version_is_not_recorded_when_the_queue_table_is_missing() {
        global $wpdb;
        delete_option(Auto_Alt_Text_Activator::DB_VERSION_OPTION);
        // Simulate a failed CREATE TABLE: point the plugin at tables that do
        // not exist and let dbDelta() run nothing.
        $prefix = $wpdb->prefix;
        $wpdb->prefix = 'aat_missing_';
        add_filter('dbdelta_queries', '__return_empty_array');
        try {
            Auto_Alt_Text_Activator::activate();
        } finally {
            $wpdb->prefix = $prefix;
            remove_filter('dbdelta_queries', '__return_empty_array');
        }

        $this->assertFalse(get_option(Auto_Alt_Text_Activator::DB_VERSION_OPTION), 'A failed schema install must be retried on the next request');
    }

    public function test_rest_api_does_not_build_an_openai_client_until_a_route_runs() {
        update_option('auto_alt_text_api_key', 'not-a-real-encrypted-key');
        $reads = 0;
        add_filter('option_auto_alt_text_api_key', function ($value) use (&$reads) {
            $reads++;
            return $value;
        });

        new Auto_Alt_Text_REST_API();

        $this->assertSame(0, $reads);
    }

    public function test_debug_log_entries_are_dropped_unless_enabled() {
        add_filter('auto_alt_text_log_debug', '__return_false');
        $before = $this->log_count();

        Auto_Alt_Text_Logger::log('noise', 'debug');
        $this->assertSame($before, $this->log_count());

        Auto_Alt_Text_Logger::log('signal', 'error');
        $this->assertSame($before + 1, $this->log_count());
    }

    public function test_prune_removes_only_old_log_rows() {
        global $wpdb;
        $table = $wpdb->prefix . 'auto_alt_text_logs';
        $wpdb->query("DELETE FROM {$table}");
        $wpdb->insert($table, ['timestamp' => wp_date('Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS), 'level' => 'info', 'message' => 'old', 'context' => '[]']);
        $wpdb->insert($table, ['timestamp' => wp_date('Y-m-d H:i:s'), 'level' => 'info', 'message' => 'new', 'context' => '[]']);

        Auto_Alt_Text_Logger::prune(30);

        $this->assertSame(['new'], $wpdb->get_col("SELECT message FROM {$table}"));
    }

    public function test_daily_maintenance_is_scheduled_and_prunes_logs() {
        WP_Auto_Alt_Text_Plugin::get_instance()->maybe_schedule_queue_cron();
        $this->assertNotFalse(wp_next_scheduled('aat_daily_maintenance'));
        $this->assertNotFalse(has_action('aat_daily_maintenance', ['Auto_Alt_Text_Logger', 'prune']));
    }

    public function test_attachment_edits_are_not_hooked() {
        $processor = WP_Auto_Alt_Text_Plugin::get_instance()->get_component('image_processor');
        $this->assertFalse(has_action('edit_attachment', [$processor, 'handle_attachment_update']));
    }

    private function log_count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}auto_alt_text_logs");
    }
}
