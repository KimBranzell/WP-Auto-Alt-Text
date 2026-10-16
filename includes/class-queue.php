<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Persistent generation queue. Jobs are enqueued by automatic triggers
 * (uploads, WooCommerce hooks) and processed in the background by the
 * cron runner, one API attempt per job per run.
 */
class Auto_Alt_Text_Queue {
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_FAILED     = 'failed';

    public const MAX_ATTEMPTS = 8;

    /** Job types created by automatic triggers; paused while auto-generation is off. */
    public const AUTO_GENERATION_TYPES = ['upload', 'woocommerce', 'woocommerce_variation'];

    /** Set when OpenAI rejects the API key (HTTP 401); run() waits it out. */
    public const AUTH_PAUSE_TRANSIENT = 'aat_queue_auth_pause';
    private const AUTH_PAUSE_SECONDS = 15 * MINUTE_IN_SECONDS;

    /** @var callable|null */
    private $processor;

    /**
     * @param callable|null $processor Overridable job processor for testing.
     *                                 Signature: fn(int $attachment_id, string $generation_type, ?int $context_post_id): array
     *                                 returning ['status' => 'success'|'retry'|'failed'|'quota'|'auth', 'error' => ?string].
     */
    public function __construct(?callable $processor = null) {
        $this->processor = $processor;
    }

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_queue';
    }

    /**
     * Adds a job unless an active (pending/processing) job for the same
     * attachment already exists. A context hint (the post the image belongs
     * to, e.g. the saved product) is added to an existing job that has none.
     *
     * @return bool Whether a row was created.
     */
    public function enqueue($attachment_id, $generation_type, $context_post_id = null) {
        global $wpdb;
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return false;
        }
        $context_post_id = $context_post_id ? (int) $context_post_id : null;

        $table = self::get_table_name();
        $active = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE attachment_id = %d AND status IN (%s, %s) LIMIT 1",
            $attachment_id, self::STATUS_PENDING, self::STATUS_PROCESSING
        ));
        if ($active) {
            $this->add_context_hint((int) $active, $context_post_id);
            return false;
        }

        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($table, [
            'attachment_id'   => $attachment_id,
            'generation_type' => sanitize_key($generation_type),
            'context_post_id' => $context_post_id,
            'status'          => self::STATUS_PENDING,
            'attempts'        => 0,
            'next_attempt_at' => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ], ['%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s']);

        return (bool) $inserted;
    }

    /** Sets a job's context hint when it has none yet. */
    private function add_context_hint($job_id, $context_post_id) {
        if (!$context_post_id) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::get_table_name() . ' SET context_post_id = %d WHERE id = %d AND context_post_id IS NULL',
            $context_post_id, $job_id
        ));
    }

    /**
     * Re-labels an attachment's pending job, e.g. so an explicit bulk request
     * for an image whose automatic job is paused runs anyway.
     *
     * @return bool Whether the attachment has a pending job, now of that type.
     */
    public function promote_pending($attachment_id, $generation_type, $context_post_id = null) {
        global $wpdb;
        $table = self::get_table_name();
        $generation_type = sanitize_key($generation_type);
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT id, generation_type FROM {$table} WHERE attachment_id = %d AND status = %s LIMIT 1",
            (int) $attachment_id, self::STATUS_PENDING
        ));
        if (!$job) {
            return false;
        }
        if ($job->generation_type !== $generation_type) {
            $wpdb->update($table, [
                'generation_type' => $generation_type,
                'updated_at'      => current_time('mysql', true),
            ], ['id' => (int) $job->id, 'status' => self::STATUS_PENDING]);
        }
        $this->add_context_hint((int) $job->id, $context_post_id ? (int) $context_post_id : null);
        return true;
    }

    private const STUCK_MINUTES   = 15;
    private const PRUNE_DONE_DAYS = 7;
    private const PRUNE_FAIL_DAYS = 30;

    /**
     * Cron entry point: recover stuck jobs, prune old rows, then claim and
     * process a bounded batch. One API attempt per job — retry timing lives
     * in next_attempt_at, never in sleep().
     */
    public function run() {
        global $wpdb;
        // Idle fast-path: an empty table needs no maintenance writes.
        if (!$wpdb->get_var('SELECT id FROM ' . self::get_table_name() . ' LIMIT 1')) {
            return;
        }

        // Without a working API key every job would burn its attempts and
        // fail: leave the backlog pending until a key is set or the pause ends.
        if (!self::has_api_key() || get_transient(self::AUTH_PAUSE_TRANSIENT)) {
            return;
        }

        $this->recover_stuck_jobs();
        $this->prune();

        $probing = false;
        if (Auto_Alt_Text_Quota_Monitor::is_active()) {
            if (!Auto_Alt_Text_Quota_Monitor::should_probe()) {
                return; // Paused: jobs wait as pending; backlog drains after recovery.
            }
            $probing = true;
        }

        $batch_size = $probing ? 1 : max(1, (int) apply_filters('aat_queue_batch_size', 5));
        $rate_limiter = new Auto_Alt_Text_Rate_Limiter();
        // The auto-generate toggle pauses jobs created by automatic triggers:
        // they stay pending until it is re-enabled. Explicit jobs (bulk
        // actions) are user requests and always run.
        $excluded_types = get_option('auto_alt_text_auto_generate', true) ? [] : self::AUTO_GENERATION_TYPES;
        $jobs = $this->claim_batch($batch_size, $excluded_types);

        $unprocessed_ids = wp_list_pluck($jobs, 'id');
        foreach ($jobs as $job) {
            if (!$rate_limiter->can_make_request()) {
                break; // Rate limited: stop, remaining claimed jobs are released below.
            }
            // This job is about to be processed; drop it from the release list
            // regardless of outcome (process_job() always finalizes or reschedules it).
            $unprocessed_ids = array_diff($unprocessed_ids, [$job->id]);
            if (!$this->process_job($job)) {
                break; // quota or rate-limit stop: remaining claimed jobs are released below.
            }
        }

        if (!empty($unprocessed_ids)) {
            $this->release_claimed($unprocessed_ids);
        }
    }

    /**
     * Claims due pending jobs one row at a time so ownership is unambiguous
     * under concurrent runners: a per-row UPDATE guarded by status='pending'
     * either affects exactly one row (we own it) or zero (another runner
     * already claimed it, so we skip it).
     *
     * @return array<object> Rows this call successfully claimed.
     */
    private function claim_batch($batch_size, array $excluded_types = []) {
        global $wpdb;
        $table = self::get_table_name();
        $now = current_time('mysql', true);

        $type_clause = '';
        $params = [self::STATUS_PENDING, $now];
        if (!empty($excluded_types)) {
            $type_clause = ' AND generation_type NOT IN (' . implode(',', array_fill(0, count($excluded_types), '%s')) . ')';
            $params = array_merge($params, array_values($excluded_types));
        }
        $params[] = $batch_size;

        $candidate_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status = %s AND next_attempt_at <= %s{$type_clause}
             ORDER BY next_attempt_at ASC, id ASC LIMIT %d",
            $params
        ));
        if (empty($candidate_ids)) {
            return [];
        }

        $owned_ids = [];
        foreach ($candidate_ids as $id) {
            $id = (int) $id;
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status = %s",
                self::STATUS_PROCESSING, $now, $id, self::STATUS_PENDING
            ));
            if ($affected) {
                $owned_ids[] = $id;
            }
        }
        if (empty($owned_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($owned_ids), '%d'));
        // No status filter needed here: we just claimed these rows above, so
        // we own them regardless of what a concurrent runner does next.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id IN ({$placeholders})",
            $owned_ids
        ));
    }

    /**
     * Processes one claimed job.
     *
     * @return bool False when the whole run must stop (quota exhausted / rate limited).
     */
    private function process_job($job) {
        $attachment_id = (int) $job->attachment_id;

        // Attachment gone → nothing to do.
        if (!get_post($attachment_id)) {
            $this->finish($job, self::STATUS_DONE);
            return true;
        }

        // Automatic jobs only fill missing alt text; explicit jobs (bulk
        // actions) follow the overwrite setting. Checked at claim time, so an
        // edit or a setting change made while the job waited is respected.
        $policy = in_array($job->generation_type, self::AUTO_GENERATION_TYPES, true)
            ? Auto_Alt_Text_Provenance::POLICY_MISSING
            : Auto_Alt_Text_Provenance::default_policy();
        if (!Auto_Alt_Text_Provenance::allows_overwrite($attachment_id, $policy)) {
            $this->finish($job, self::STATUS_DONE);
            return true;
        }

        $processor = $this->processor ?: [$this, 'default_processor'];
        try {
            $context_post_id = isset($job->context_post_id) ? (int) $job->context_post_id : 0;
            $result = call_user_func($processor, $attachment_id, $job->generation_type, $context_post_id ?: null);
        } catch (Throwable $e) {
            // A fatal inside the processor (e.g. missing AVIF support in GD)
            // must never kill the cron tick or strand the job in 'processing'
            // with a frozen attempt counter; route it through normal backoff.
            Auto_Alt_Text_Logger::log('Queue processor crashed', 'error', [
                'attachment_id' => $attachment_id,
                'error'         => $e->getMessage(),
            ]);
            $result = ['status' => 'retry', 'error' => $e->getMessage()];
        }
        $status = is_array($result) ? ($result['status'] ?? 'failed') : 'failed';
        $error  = is_array($result) ? ($result['error'] ?? null) : null;

        switch ($status) {
            case 'success':
                $this->finish($job, self::STATUS_DONE);
                return true;

            case 'quota':
                // Job untouched: release back to pending, stop the run.
                $this->update_job($job->id, ['status' => self::STATUS_PENDING]);
                return false;

            case 'auth':
                // API key rejected: every job would fail the same way. Pause the
                // queue (a new key or any successful call lifts it early), leave
                // the job untouched and stop the run.
                set_transient(self::AUTH_PAUSE_TRANSIENT, 1, self::AUTH_PAUSE_SECONDS);
                $this->update_job($job->id, ['status' => self::STATUS_PENDING]);
                return false;

            case 'retry':
                $attempts = (int) $job->attempts + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->finish($job, self::STATUS_FAILED, $error);
                } else {
                    $this->update_job($job->id, [
                        'status'          => self::STATUS_PENDING,
                        'attempts'        => $attempts,
                        'last_error'      => (string) $error,
                        'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + self::retry_delay($attempts)),
                    ]);
                }
                return true;

            case 'failed':
            default:
                $this->finish($job, self::STATUS_FAILED, $error);
                return true;
        }
    }

    /**
     * Default processor: one real OpenAI attempt. Wired fully in Task 5
     * (quota monitor + rate limiter integration).
     */
    private function default_processor($attachment_id, $generation_type, $context_post_id = null) {
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->set_max_api_attempts(1);
        $openai->set_context_post_id($context_post_id);
        $alt_text = $openai->generate_alt_text(
            wp_get_attachment_url($attachment_id),
            $attachment_id,
            $generation_type
        );

        if (!empty($alt_text)) {
            return ['status' => 'success', 'error' => null];
        }

        $class = $openai->get_last_error_class();
        $map = ['quota' => 'quota', 'auth' => 'auth', 'permanent' => 'failed'];
        return [
            'status' => $map[$class] ?? 'retry',
            'error'  => $openai->get_last_error(),
        ];
    }

    /** Backoff after the given number of attempts: 2^n minutes, capped at 6 hours. */
    private static function retry_delay($attempts) {
        return min((int) pow(2, $attempts) * MINUTE_IN_SECONDS, 6 * HOUR_IN_SECONDS);
    }

    /** Lifts the 401 pause: hooked to API key changes, called on API success. */
    public static function clear_auth_pause() {
        delete_transient(self::AUTH_PAUSE_TRANSIENT);
    }

    private static function has_api_key() {
        if (defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY) {
            return true;
        }
        return '' !== (string) get_option('auto_alt_text_api_key', '');
    }

    private function finish($job, $status, $error = null) {
        $data = ['status' => $status];
        if (null !== $error) {
            $data['last_error'] = (string) $error;
        }
        $this->update_job($job->id, $data);
    }

    private function update_job($id, array $data) {
        global $wpdb;
        $data['updated_at'] = current_time('mysql', true);
        $wpdb->update(self::get_table_name(), $data, ['id' => (int) $id]);
    }

    /**
     * Releases specific jobs claimed by this run but never processed (early
     * stop, e.g. quota exhausted mid-batch). Scoped to the exact ids this
     * run claimed, so a concurrent runner's own in-flight jobs are untouched.
     *
     * @param array<int> $ids
     */
    private function release_claimed(array $ids) {
        global $wpdb;
        if (empty($ids)) {
            return;
        }
        $ids = array_map('intval', $ids);
        $table = self::get_table_name();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, updated_at = %s WHERE status = %s AND id IN ({$placeholders})",
            array_merge([self::STATUS_PENDING, current_time('mysql', true), self::STATUS_PROCESSING], $ids)
        ));
    }

    /**
     * Recovers processing rows older than STUCK_MINUTES (the runner died
     * mid-job, e.g. a fatal or memory exhaustion). That counts as a used
     * attempt with the normal backoff, so a job that kills the runner every
     * time ends up failed instead of being retried first on every tick.
     */
    private function recover_stuck_jobs() {
        global $wpdb;
        $table = self::get_table_name();
        $threshold = gmdate('Y-m-d H:i:s', time() - self::STUCK_MINUTES * MINUTE_IN_SECONDS);
        $stuck = $wpdb->get_results($wpdb->prepare(
            "SELECT id, attempts, updated_at FROM {$table} WHERE status = %s AND updated_at < %s",
            self::STATUS_PROCESSING, $threshold
        ));

        foreach ($stuck as $job) {
            $attempts = (int) $job->attempts + 1;
            $data = [
                'attempts'   => $attempts,
                'last_error' => 'Processing timed out (recovered stuck job)',
                'updated_at' => current_time('mysql', true),
            ];
            if ($attempts >= self::MAX_ATTEMPTS) {
                $data['status'] = self::STATUS_FAILED;
            } else {
                $data['status'] = self::STATUS_PENDING;
                $data['next_attempt_at'] = gmdate('Y-m-d H:i:s', time() + self::retry_delay($attempts));
            }
            // Guarded on the row as read, so a concurrent runner recovering
            // the same job cannot count the attempt twice.
            $wpdb->update($table, $data, [
                'id'         => (int) $job->id,
                'status'     => self::STATUS_PROCESSING,
                'updated_at' => $job->updated_at,
            ]);
        }
    }

    /** Prunes done rows older than 7 days and failed rows older than 30 days. */
    private function prune() {
        global $wpdb;
        $table = self::get_table_name();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = %s AND updated_at < %s",
            self::STATUS_DONE, gmdate('Y-m-d H:i:s', time() - self::PRUNE_DONE_DAYS * DAY_IN_SECONDS)
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = %s AND updated_at < %s",
            self::STATUS_FAILED, gmdate('Y-m-d H:i:s', time() - self::PRUNE_FAIL_DAYS * DAY_IN_SECONDS)
        ));
    }

    /** @return array{pending:int,processing:int,failed:int,done:int} */
    public function get_counts() {
        global $wpdb;
        $table = self::get_table_name();
        $counts = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'done' => 0];
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status");
        foreach ($rows as $row) {
            if (isset($counts[$row->status])) {
                $counts[$row->status] = (int) $row->n;
            }
        }
        return $counts;
    }

    /** @return array<object> */
    public function get_jobs($status = null, $limit = 50) {
        global $wpdb;
        $table = self::get_table_name();
        $limit = max(1, (int) $limit);
        if ($status) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY next_attempt_at ASC, id ASC LIMIT %d",
                sanitize_key($status), $limit
            ));
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY next_attempt_at ASC, id ASC LIMIT %d", $limit
        ));
    }

    /** @return int Number of failed jobs re-queued. */
    public function retry_failed() {
        global $wpdb;
        $table = self::get_table_name();
        $now = current_time('mysql', true);
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, attempts = 0, next_attempt_at = %s, updated_at = %s WHERE status = %s",
            self::STATUS_PENDING, $now, $now, self::STATUS_FAILED
        ));
    }
}
