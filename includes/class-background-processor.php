<?php
if (!defined('ABSPATH')) exit;

class Auto_Alt_Text_Background_Processor {
    private $queue_manager;
    private $openai;
    private $language_manager;

    const CRON_HOOK = 'aat_process_queue';
    const CRON_SCHEDULE = 'aat_every_minute';

    public function __construct(
        Auto_Alt_Text_Queue_Manager $queue_manager,
        Auto_Alt_Text_OpenAI $openai,
        Auto_Alt_Text_Language_Manager $language_manager
    ) {
        $this->queue_manager = $queue_manager;
        $this->openai = $openai;
        $this->language_manager = $language_manager;

        $this->register_cron_schedule();
        $this->schedule_cron();

        add_action(self::CRON_HOOK, [$this, 'process_queue']);

        if ($this->is_action_scheduler_available()) {
            add_action('aat_process_single_item', [$this, 'process_single_item'], 10, 1);
        }
    }

    private function register_cron_schedule() {
        add_filter('cron_schedules', function ($schedules) {
            $schedules[self::CRON_SCHEDULE] = [
                'interval' => 60,
                'display' => __('Every Minute', 'WP-Auto-Alt-Text'),
            ];
            return $schedules;
        });
    }

    private function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    private function is_action_scheduler_available() {
        return function_exists('as_enqueue_async_action');
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public function process_queue() {
        if ($this->is_action_scheduler_available()) {
            $this->process_queue_via_action_scheduler();
        } else {
            $this->process_queue_via_cron();
        }
    }

    private function check_batch_completion() {
        $batches = $this->queue_manager->get_recent_batches(1);
        if (empty($batches)) {
            return;
        }

        $batch = $batches[0];
        $total = (int) $batch->total;
        $completed = (int) $batch->completed;
        $failed = (int) $batch->failed;
        $batch_id = isset($batch->batch_id) ? $batch->batch_id : '';

        $notified_batch = get_transient('aat_last_notified_batch');

        if ($notified_batch === $batch_id) {
            return;
        }

        if (($completed + $failed) >= $total && $total > 0) {
            set_transient('aat_queue_completed', [
                'completed' => $completed,
                'failed' => $failed,
                'total' => $total,
            ], HOUR_IN_SECONDS);

            set_transient('aat_last_notified_batch', $batch_id, DAY_IN_SECONDS);

            $this->send_completion_email($completed, $failed, $total);
        }
    }

    private function send_completion_email($completed, $failed, $total) {
        $to = get_option('admin_email');
        if (!$to) {
            return;
        }

        $site_name = get_bloginfo('name');
        $admin_url = admin_url('admin.php?page=auto-alt-text-stats');

        if ($failed > 0) {
            $subject = sprintf(
                __('[%s] WP Auto Alt Text — Batch completed with %d failures', 'WP-Auto-Alt-Text'),
                $site_name,
                $failed
            );
        } else {
            $subject = sprintf(
                __('[%s] WP Auto Alt Text — Batch completed successfully', 'WP-Auto-Alt-Text'),
                $site_name
            );
        }

        $message = sprintf(
            __("Hello,\n\nWP Auto Alt Text has finished processing a batch of images.\n\nSummary:\n- Total images: %d\n- Successfully generated: %d\n- Failed: %d\n\nYou can review the results at: %s\n\n--\nThis email was sent automatically by WP Auto Alt Text.", 'WP-Auto-Alt-Text'),
            $total,
            $completed,
            $failed,
            $admin_url
        );

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        wp_mail($to, $subject, $message, $headers);

        Auto_Alt_Text_Logger::log("Sent completion email to {$to}", 'info', [
            'completed' => $completed,
            'failed' => $failed,
            'total' => $total,
        ]);
    }

    private function process_queue_via_action_scheduler() {
        $items_per_tick = 5;

        for ($i = 0; $i < $items_per_tick; $i++) {
            $item = $this->queue_manager->claim_next_item();
            if (!$item) {
                break;
            }

            as_enqueue_async_action('aat_process_single_item', [
                'item_id' => $item->id,
            ], 'wp-auto-alt-text');
        }

        $this->check_batch_completion();
    }

    private function process_queue_via_cron() {
        $max_execution_time = 40;
        $start_time = time();
        $processed = 0;

        while (true) {
            if ((time() - $start_time) >= $max_execution_time) {
                break;
            }

            $item = $this->queue_manager->claim_next_item();
            if (!$item) {
                break;
            }

            $this->process_single_item($item->id);
            $processed++;
        }

        if ($processed > 0) {
            Auto_Alt_Text_Logger::log("Processed {$processed} queue items via cron", 'info');
        }

        $this->check_batch_completion();
    }

    public function process_single_item($item_id) {
        global $wpdb;
        $table_name = $this->queue_manager->get_table_name();

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $item_id
        ));

        if (!$item || $item->status !== Auto_Alt_Text_Queue_Manager::STATUS_PROCESSING) {
            return;
        }

        $attachment_id = (int) $item->attachment_id;
        $original_user = null;

        if (!empty($item->user_id)) {
            $original_user = wp_get_current_user();
            wp_set_current_user((int) $item->user_id);
        }

        try {
            if (!wp_attachment_is_image($attachment_id)) {
                $this->queue_manager->mark_failed($item->id, 'Not a valid image');
                return;
            }

            $image_url = wp_get_attachment_url($attachment_id);
            if (!$image_url) {
                $this->queue_manager->mark_failed($item->id, 'Could not resolve image URL');
                return;
            }

            $alt_text = $this->openai->generate_alt_text(
                $image_url,
                $attachment_id,
                $item->generation_type
            );

            if ($alt_text) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
                $this->queue_manager->mark_completed($item->id, $alt_text);
            } else {
                $error = $this->openai->get_last_error();
                $this->queue_manager->mark_failed($item->id, $error ?: 'Generation returned empty');
            }
        } catch (Exception $e) {
            $this->queue_manager->mark_failed($item->id, $e->getMessage());
            Auto_Alt_Text_Logger::log("Background processing failed for {$attachment_id}", 'error', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($original_user) {
                wp_set_current_user($original_user->ID);
            }
        }
    }
}
