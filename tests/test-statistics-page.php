<?php
class Auto_Alt_Text_Statistics_Page_Test extends WP_UnitTestCase {
    public function test_history_row_without_a_user_is_attributed_to_the_queue() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}auto_alt_text_stats");
        $attachment_id = self::factory()->attachment->create();
        // The background queue runs from cron with no logged-in user.
        wp_set_current_user(0);
        (new Auto_Alt_Text_Statistics())->track_generation($attachment_id, 'A red bicycle', 42, 'upload');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        ob_start();
        (new Auto_Alt_Text_Statistics_Page())->render_statistics_page();
        $html = ob_get_clean();

        $this->assertStringContainsString('System (queue)', $html);
    }
}
