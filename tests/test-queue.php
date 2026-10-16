<?php
class Auto_Alt_Text_Queue_Test extends WP_UnitTestCase {
    private $queue;

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        $this->queue = new Auto_Alt_Text_Queue();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . Auto_Alt_Text_Queue::get_table_name());
        // run() leaves the queue alone until an API key is configured.
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
    }

    public function test_queue_table_exists() {
        global $wpdb;
        $table = Auto_Alt_Text_Queue::get_table_name();
        $this->assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
    }

    public function test_enqueue_creates_pending_job() {
        $id = self::factory()->attachment->create();
        $this->assertTrue($this->queue->enqueue($id, 'woocommerce'));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d', $id
        ));
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $row->status);
        $this->assertSame('woocommerce', $row->generation_type);
        $this->assertSame('0', $row->attempts);
    }

    public function test_enqueue_dedupes_active_jobs() {
        $id = self::factory()->attachment->create();
        $this->assertTrue($this->queue->enqueue($id, 'woocommerce'));
        $this->assertFalse($this->queue->enqueue($id, 'upload'));
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d', $id
        ));
        $this->assertSame('1', $count);
    }

    public function test_enqueue_allows_requeue_after_done() {
        $id = self::factory()->attachment->create();
        $this->queue->enqueue($id, 'upload');
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Queue::get_table_name(),
            ['status' => Auto_Alt_Text_Queue::STATUS_DONE], ['attachment_id' => $id]);
        $this->assertTrue($this->queue->enqueue($id, 'woocommerce'));
    }

    /** Helper: build a queue whose processor returns a fixed result and records calls. */
    private function queue_with_result($result, &$calls = null) {
        $calls = [];
        return new Auto_Alt_Text_Queue(function ($attachment_id, $type) use ($result, &$calls) {
            $calls[] = $attachment_id;
            return $result;
        });
    }

    private function get_job($attachment_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d', $attachment_id
        ));
    }

    public function test_run_marks_success_done() {
        $id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($id, 'upload');
        $queue->run();
        $this->assertSame([$id], $calls);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_DONE, $this->get_job($id)->status);
    }

    public function test_run_skips_attachment_with_existing_alt_text_without_processing() {
        $id = self::factory()->attachment->create();
        update_post_meta($id, '_wp_attachment_image_alt', 'Already described');
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($id, 'upload');
        $queue->run();
        $this->assertSame([], $calls);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_DONE, $this->get_job($id)->status);
    }

    public function test_retryable_failure_reschedules_with_backoff() {
        $id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'retry', 'error' => 'HTTP 500'], $calls);
        $queue->enqueue($id, 'upload');
        $queue->run();
        $job = $this->get_job($id);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $job->status);
        $this->assertSame('1', $job->attempts);
        $this->assertSame('HTTP 500', $job->last_error);
        // attempts=1 → delay 2^1 * 60 = 120 s
        $delta = strtotime($job->next_attempt_at) - strtotime($job->updated_at);
        $this->assertGreaterThanOrEqual(110, $delta);
        $this->assertLessThanOrEqual(130, $delta);
    }

    public function test_job_fails_after_max_attempts() {
        $id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'retry', 'error' => 'HTTP 500']);
        $queue->enqueue($id, 'upload');
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Queue::get_table_name(),
            ['attempts' => Auto_Alt_Text_Queue::MAX_ATTEMPTS - 1], ['attachment_id' => $id]);
        $queue->run();
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_FAILED, $this->get_job($id)->status);
    }

    public function test_permanent_failure_marks_failed_immediately() {
        $id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'failed', 'error' => 'Invalid image']);
        $queue->enqueue($id, 'upload');
        $queue->run();
        $job = $this->get_job($id);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_FAILED, $job->status);
        $this->assertSame('Invalid image', $job->last_error);
    }

    public function test_quota_result_leaves_job_pending_and_stops_run() {
        $id1 = self::factory()->attachment->create();
        $id2 = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'quota', 'error' => 'insufficient_quota'], $calls);
        $queue->enqueue($id1, 'upload');
        $queue->enqueue($id2, 'upload');
        $queue->run();
        $this->assertCount(1, $calls);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $this->get_job($id1)->status);
        $this->assertSame('0', $this->get_job($id1)->attempts);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $this->get_job($id2)->status);
    }

    public function test_future_jobs_are_not_claimed() {
        $id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($id, 'upload');
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Queue::get_table_name(),
            ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 3600)], ['attachment_id' => $id]);
        $queue->run();
        $this->assertSame([], $calls);
    }

    public function test_stuck_processing_job_is_recovered_with_an_attempt_and_backoff() {
        $id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($id, 'upload');
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Queue::get_table_name(), [
            'status'     => Auto_Alt_Text_Queue::STATUS_PROCESSING,
            'attempts'   => 2,
            'updated_at' => gmdate('Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS),
        ], ['attachment_id' => $id]);
        $queue->run();
        $job = $this->get_job($id);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $job->status);
        $this->assertSame('3', $job->attempts, 'A crashed run must consume an attempt');
        // attempts=3 -> delay 2^3 * 60 = 480 s; a poison job must not be retried first every tick.
        $this->assertGreaterThan(time() + 7 * MINUTE_IN_SECONDS, strtotime($job->next_attempt_at . ' UTC'));
        $this->assertSame([], $calls, 'The recovered job waits for its backoff');
    }

    public function test_stuck_processing_job_at_last_attempt_is_failed() {
        $id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($id, 'upload');
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Queue::get_table_name(), [
            'status'     => Auto_Alt_Text_Queue::STATUS_PROCESSING,
            'attempts'   => Auto_Alt_Text_Queue::MAX_ATTEMPTS - 1,
            'updated_at' => gmdate('Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS),
        ], ['attachment_id' => $id]);
        $queue->run();
        $job = $this->get_job($id);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_FAILED, $job->status);
        $this->assertSame((string) Auto_Alt_Text_Queue::MAX_ATTEMPTS, $job->attempts);
        $this->assertSame('Processing timed out (recovered stuck job)', $job->last_error);
        $this->assertSame([], $calls);
    }

    public function test_deleted_attachment_marks_job_done() {
        $id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($id, 'upload');
        wp_delete_attachment($id, true);
        $queue->run();
        $this->assertSame([], $calls);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_DONE, $this->get_job($id)->status);
    }

    public function test_run_does_not_release_or_process_other_runners_fresh_processing_jobs() {
        $mine = self::factory()->attachment->create();
        $theirs = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($mine, 'upload');
        $queue->enqueue($theirs, 'upload');
        global $wpdb;
        // Simulate a concurrent runner holding $theirs: processing, fresh updated_at.
        $wpdb->update(Auto_Alt_Text_Queue::get_table_name(), [
            'status'     => Auto_Alt_Text_Queue::STATUS_PROCESSING,
            'updated_at' => gmdate('Y-m-d H:i:s', time() - 2 * MINUTE_IN_SECONDS),
        ], ['attachment_id' => $theirs]);
        $queue->run();
        $this->assertSame([$mine], $calls, 'Must not process another runner\'s in-flight job');
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PROCESSING, $this->get_job($theirs)->status,
            'Must not release another runner\'s fresh processing job');
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_DONE, $this->get_job($mine)->status);
    }

    public function test_get_counts_and_retry_failed() {
        $a = self::factory()->attachment->create();
        $b = self::factory()->attachment->create();
        $this->queue->enqueue($a, 'upload');
        $this->queue->enqueue($b, 'upload');
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Queue::get_table_name(),
            ['status' => Auto_Alt_Text_Queue::STATUS_FAILED], ['attachment_id' => $b]);
        $counts = $this->queue->get_counts();
        $this->assertSame(1, $counts['pending']);
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, $this->queue->retry_failed());
        $this->assertSame(2, $this->queue->get_counts()['pending']);
    }

    public function test_run_exits_immediately_during_quota_incident() {
        add_filter('pre_wp_mail', '__return_true');
        Auto_Alt_Text_Quota_Monitor::record_failure('quota');
        $id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($id, 'upload');
        $queue->run();
        $this->assertSame([], $calls, 'No jobs processed while quota incident active and probe not due');
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
    }

    public function test_run_probes_single_job_when_probe_due() {
        add_filter('pre_wp_mail', '__return_true');
        Auto_Alt_Text_Quota_Monitor::record_failure('quota');
        $incident = get_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        $incident['last_probe_at'] = time() - 16 * MINUTE_IN_SECONDS;
        update_option(Auto_Alt_Text_Quota_Monitor::OPTION, $incident, false);

        $a = self::factory()->attachment->create();
        $b = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($a, 'upload');
        $queue->enqueue($b, 'upload');
        $queue->run();
        $this->assertCount(1, $calls, 'Probe processes exactly one job');
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
    }

    public function test_cron_event_is_scheduled() {
        WP_Auto_Alt_Text_Plugin::get_instance()->maybe_schedule_queue_cron();
        $this->assertNotFalse(wp_next_scheduled('aat_queue_tick'));
        $schedules = wp_get_schedules();
        $this->assertArrayHasKey('aat_minutely', $schedules);
        $this->assertSame(60, $schedules['aat_minutely']['interval']);
    }

    public function test_processor_crash_reschedules_job_instead_of_fataling() {
        $id = self::factory()->attachment->create();
        $queue = new Auto_Alt_Text_Queue(function () {
            throw new RuntimeException('imagecreatefromavif(): AVIF not supported');
        });
        $queue->enqueue($id, 'upload');
        $queue->run();
        $job = $this->get_job($id);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $job->status, 'Crashed job must go back to pending with backoff, not stay processing');
        $this->assertSame('1', $job->attempts, 'Crash must consume an attempt so MAX_ATTEMPTS is reachable');
        $this->assertStringContainsString('AVIF not supported', $job->last_error);
    }

    public function test_run_leaves_jobs_pending_when_auto_generate_disabled() {
        // Two-step set: update_option($opt, false) on a missing row is a no-op
        // (old value false == new value); prime with true so false persists.
        update_option('auto_alt_text_auto_generate', true);
        update_option('auto_alt_text_auto_generate', false);
        $id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($id, 'upload');
        $queue->run();
        $this->assertSame([], $calls, 'No jobs may be processed while auto-generate is disabled');
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $this->get_job($id)->status, 'Backlog must survive the toggle being off');
        update_option('auto_alt_text_auto_generate', true);
    }

    public function test_explicit_jobs_run_while_auto_generate_disabled() {
        update_option('auto_alt_text_auto_generate', true);
        update_option('auto_alt_text_auto_generate', false);
        $auto_id = self::factory()->attachment->create();
        $bulk_id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($auto_id, 'upload');
        $queue->enqueue($bulk_id, 'woocommerce_bulk');

        $queue->run();
        update_option('auto_alt_text_auto_generate', true);

        $this->assertSame([$bulk_id], $calls, 'Only the explicit job may run while auto-generate is off');
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $this->get_job($auto_id)->status);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_DONE, $this->get_job($bulk_id)->status);
    }

    public function test_promote_pending_relabels_only_pending_jobs() {
        $pending = self::factory()->attachment->create();
        $processing = self::factory()->attachment->create();
        $this->queue->enqueue($pending, 'upload');
        $this->queue->enqueue($processing, 'upload');
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Queue::get_table_name(),
            ['status' => Auto_Alt_Text_Queue::STATUS_PROCESSING], ['attachment_id' => $processing]);

        $this->assertTrue($this->queue->promote_pending($pending, 'woocommerce_bulk'));
        $this->assertSame('woocommerce_bulk', $this->get_job($pending)->generation_type);
        $this->assertFalse($this->queue->promote_pending($processing, 'woocommerce_bulk'));
        $this->assertSame('upload', $this->get_job($processing)->generation_type);
        $this->assertFalse($this->queue->promote_pending(self::factory()->attachment->create(), 'woocommerce_bulk'));
    }

    public function test_run_without_api_key_claims_nothing() {
        if (defined('AUTO_ALT_TEXT_OPENAI_API_KEY')) {
            $this->markTestSkipped('AUTO_ALT_TEXT_OPENAI_API_KEY is defined in this environment');
        }
        delete_option('auto_alt_text_api_key');
        $id = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->enqueue($id, 'upload');
        $queue->run();
        $this->assertSame([], $calls, 'No job may be processed without an API key');
        $job = $this->get_job($id);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $job->status);
        $this->assertSame('0', $job->attempts);
    }

    public function test_auth_result_leaves_jobs_pending_and_stops_run() {
        $id1 = self::factory()->attachment->create();
        $id2 = self::factory()->attachment->create();
        $queue = $this->queue_with_result(['status' => 'auth', 'error' => 'Authentication failed.'], $calls);
        $queue->enqueue($id1, 'upload');
        $queue->enqueue($id2, 'upload');
        $queue->run();
        $this->assertCount(1, $calls, 'An auth error must stop the run');
        foreach ([$id1, $id2] as $id) {
            $job = $this->get_job($id);
            $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $job->status);
            $this->assertSame('0', $job->attempts, 'An auth error must not consume an attempt');
        }
        $this->assertNotFalse(get_transient('aat_queue_auth_pause'));
    }

    public function test_auth_pause_blocks_the_next_run() {
        $id = self::factory()->attachment->create();
        $failing = $this->queue_with_result(['status' => 'auth', 'error' => 'Authentication failed.']);
        $failing->enqueue($id, 'upload');
        $failing->run();

        $queue = $this->queue_with_result(['status' => 'success', 'error' => null], $calls);
        $queue->run();
        $this->assertSame([], $calls, 'Queue stays paused after an auth error');
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $this->get_job($id)->status);
    }

    public function test_saving_a_new_api_key_lifts_the_auth_pause() {
        set_transient('aat_queue_auth_pause', 1, 15 * MINUTE_IN_SECONDS);
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-replacement-key');
        $this->assertFalse(get_transient('aat_queue_auth_pause'));
    }

    public function test_openai_401_pauses_queue_without_failing_the_job_or_emailing() {
        $mails = 0;
        add_filter('pre_wp_mail', function () use (&$mails) {
            $mails++;
            return true;
        });
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['error' => ['message' => 'Incorrect API key provided', 'code' => 'invalid_api_key']]),
                'response' => ['code' => 401, 'message' => 'Unauthorized'],
                'cookies'  => [],
            ];
        }, 10, 3);
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $this->queue->enqueue($id, 'upload');

        $this->queue->run();

        $job = $this->get_job($id);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $job->status);
        $this->assertSame('0', $job->attempts);
        $this->assertNotFalse(get_transient('aat_queue_auth_pause'));
        $this->assertFalse(Auto_Alt_Text_Quota_Monitor::is_active());
        $this->assertSame(0, $mails, 'Auth errors must not send the quota email');
    }
}
