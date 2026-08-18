<?php
// tests/test-quota-monitor.php
class Auto_Alt_Text_Quota_Monitor_Test extends WP_UnitTestCase {
    private $sent_emails = [];

    public function setUp(): void {
        parent::setUp();
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_option(Auto_Alt_Text_Quota_Monitor::LAST_EMAIL_OPTION);
        $this->sent_emails = [];
        add_filter('pre_wp_mail', function ($null, $atts) {
            $this->sent_emails[] = $atts;
            return true; // short-circuit actual sending
        }, 10, 2);
    }

    public function test_inactive_by_default() {
        $this->assertFalse(Auto_Alt_Text_Quota_Monitor::is_active());
    }

    public function test_first_failure_activates_and_sends_one_email() {
        Auto_Alt_Text_Quota_Monitor::record_failure('You exceeded your current quota');
        $this->assertTrue(Auto_Alt_Text_Quota_Monitor::is_active());
        $this->assertCount(1, $this->sent_emails);
        $this->assertSame(get_option('admin_email'), $this->sent_emails[0]['to']);
    }

    public function test_repeated_failures_send_no_further_email() {
        Auto_Alt_Text_Quota_Monitor::record_failure('quota');
        Auto_Alt_Text_Quota_Monitor::record_failure('quota');
        Auto_Alt_Text_Quota_Monitor::record_failure('quota');
        $this->assertCount(1, $this->sent_emails);
    }

    public function test_success_clears_incident() {
        Auto_Alt_Text_Quota_Monitor::record_failure('quota');
        Auto_Alt_Text_Quota_Monitor::record_success();
        $this->assertFalse(Auto_Alt_Text_Quota_Monitor::is_active());
    }

    public function test_new_incident_after_clear_sends_new_email_after_24h_floor() {
        Auto_Alt_Text_Quota_Monitor::record_failure('quota');
        Auto_Alt_Text_Quota_Monitor::record_success();
        // Age the cross-incident email floor past 24 h.
        update_option(Auto_Alt_Text_Quota_Monitor::LAST_EMAIL_OPTION, time() - DAY_IN_SECONDS - 60, false);
        Auto_Alt_Text_Quota_Monitor::record_failure('quota again');
        $this->assertCount(2, $this->sent_emails);
    }

    public function test_flapping_quota_within_24h_sends_only_one_email() {
        Auto_Alt_Text_Quota_Monitor::record_failure('quota');
        Auto_Alt_Text_Quota_Monitor::record_success();
        Auto_Alt_Text_Quota_Monitor::record_failure('quota again');
        Auto_Alt_Text_Quota_Monitor::record_success();
        Auto_Alt_Text_Quota_Monitor::record_failure('and again');
        $this->assertCount(1, $this->sent_emails, 'Flapping quota must not re-arm the email guard within 24 h');
        $this->assertTrue(get_option(Auto_Alt_Text_Quota_Monitor::OPTION)['email_sent']);
    }

    public function test_should_probe_gates_to_fifteen_minutes() {
        Auto_Alt_Text_Quota_Monitor::record_failure('quota');
        // Fresh incident: not yet time to probe.
        $this->assertFalse(Auto_Alt_Text_Quota_Monitor::should_probe());
        // Age the incident's probe clock past 15 minutes.
        $incident = get_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        $incident['last_probe_at'] = time() - 16 * MINUTE_IN_SECONDS;
        update_option(Auto_Alt_Text_Quota_Monitor::OPTION, $incident, false);
        $this->assertTrue(Auto_Alt_Text_Quota_Monitor::should_probe());
        // Probe consumed: gate closed again.
        $this->assertFalse(Auto_Alt_Text_Quota_Monitor::should_probe());
    }

    public function test_dismiss_hides_notice_until_new_incident() {
        Auto_Alt_Text_Quota_Monitor::record_failure('quota');
        Auto_Alt_Text_Quota_Monitor::dismiss_notice();
        $this->assertTrue(get_option(Auto_Alt_Text_Quota_Monitor::OPTION)['notice_dismissed']);
        Auto_Alt_Text_Quota_Monitor::record_success();
        Auto_Alt_Text_Quota_Monitor::record_failure('again');
        $this->assertFalse(get_option(Auto_Alt_Text_Quota_Monitor::OPTION)['notice_dismissed']);
    }

    public function test_incident_email_links_to_plugin_admin_page() {
        Auto_Alt_Text_Quota_Monitor::record_failure('You exceeded your current quota');
        $this->assertCount(1, $this->sent_emails);
        $email_message = $this->sent_emails[0]['message'];
        $this->assertStringContainsString('admin.php?page=auto-alt-text', $email_message);
        $this->assertStringNotContainsString('options-general.php', $email_message);
    }

    public function test_email_send_failure_still_marks_email_sent_and_logs() {
        // Make wp_mail fail for this test
        remove_all_filters('pre_wp_mail');
        add_filter('pre_wp_mail', function () {
            return false; // Simulate wp_mail failure
        }, 10, 2);

        Auto_Alt_Text_Quota_Monitor::record_failure('quota');

        // email_sent should be true even though wp_mail failed
        $incident = get_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        $this->assertTrue($incident['email_sent']);

        // Reset for another call
        $this->sent_emails = [];

        // Second call should not attempt to send again
        Auto_Alt_Text_Quota_Monitor::record_failure('quota again');
        $this->assertCount(0, $this->sent_emails);
    }
}
