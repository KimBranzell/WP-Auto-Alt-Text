<?php
if (!defined('ABSPATH')) exit;

class Auto_Alt_Text_Queue_Manager {
    private $table_name;
    private $openai;

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'auto_alt_text_queue';
    }

    public function get_table_name() {
        return $this->table_name;
    }

    public function enqueue_batch($attachment_ids, $generation_type = 'batch') {
        global $wpdb;

        $batch_id = uniqid('aat_', true);
        $now = current_time('mysql');
        $inserted = 0;
        $user_id = get_current_user_id();

        foreach ($attachment_ids as $id) {
            $id = intval($id);
            if ($id <= 0) {
                continue;
            }

            $result = $wpdb->insert(
                $this->table_name,
                [
                    'attachment_id' => $id,
                    'batch_id' => $batch_id,
                    'generation_type' => $generation_type,
                    'status' => self::STATUS_PENDING,
                    'user_id' => $user_id,
                    'created_at' => $now,
                ],
                ['%d', '%s', '%s', '%s', '%d', '%s']
            );

            if ($result) {
                $inserted++;
            }
        }

        return [
            'batch_id' => $batch_id,
            'inserted' => $inserted,
            'total' => count($attachment_ids),
        ];
    }

    public function claim_next_item() {
        global $wpdb;

        $lock_key = 'aat_queue_lock';
        $locked = get_transient($lock_key);

        if ($locked) {
            return null;
        }

        set_transient($lock_key, true, 30);

        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                WHERE status = %s
                AND attempts < 3
                ORDER BY id ASC
                LIMIT 1",
                self::STATUS_PENDING
            )
        );

        if (!$item) {
            delete_transient($lock_key);
            return null;
        }

        $wpdb->update(
            $this->table_name,
            [
                'status' => self::STATUS_PROCESSING,
                'started_at' => current_time('mysql'),
                'attempts' => $item->attempts + 1,
            ],
            ['id' => $item->id],
            ['%s', '%s', '%d'],
            ['%d']
        );

        delete_transient($lock_key);

        $item->status = self::STATUS_PROCESSING;
        return $item;
    }

    public function mark_completed($id, $alt_text = '') {
        global $wpdb;

        return $wpdb->update(
            $this->table_name,
            [
                'status' => self::STATUS_COMPLETED,
                'completed_at' => current_time('mysql'),
                'result_text' => $alt_text,
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    public function mark_failed($id, $error_message = '') {
        global $wpdb;

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));

        if (!$item) {
            return false;
        }

        if ($item->attempts >= 3) {
            return $wpdb->update(
                $this->table_name,
                [
                    'status' => self::STATUS_FAILED,
                    'completed_at' => current_time('mysql'),
                    'error_message' => $error_message,
                ],
                ['id' => $id],
                ['%s', '%s', '%s'],
                ['%d']
            );
        }

        return $wpdb->update(
            $this->table_name,
            [
                'status' => self::STATUS_PENDING,
                'error_message' => $error_message,
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    public function get_queue_status($batch_id = null) {
        global $wpdb;

        $where = 'WHERE 1=1';
        $params = [];

        if ($batch_id) {
            $where .= ' AND batch_id = %s';
            $params[] = $batch_id;
        }

        $pending = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} {$where} AND status = %s",
                array_merge($params, [self::STATUS_PENDING])
            )
        );

        $processing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} {$where} AND status = %s",
                array_merge($params, [self::STATUS_PROCESSING])
            )
        );

        $completed = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} {$where} AND status = %s",
                array_merge($params, [self::STATUS_COMPLETED])
            )
        );

        $failed = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} {$where} AND status = %s",
                array_merge($params, [self::STATUS_FAILED])
            )
        );

        $total = $pending + $processing + $completed + $failed;

        return [
            'total' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'failed' => $failed,
        ];
    }

    public function get_total_pending_count() {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s AND attempts < 3",
                self::STATUS_PENDING
            )
        );
    }

    public function is_any_processing() {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE status IN (%s, %s)",
                self::STATUS_PENDING,
                self::STATUS_PROCESSING
            )
        ) > 0;
    }

    public function get_recent_batches($limit = 5) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT batch_id, COUNT(*) as total,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as failed,
                    MIN(created_at) as created_at
                FROM {$this->table_name}
                GROUP BY batch_id
                ORDER BY MIN(id) DESC
                LIMIT %d",
                self::STATUS_COMPLETED,
                self::STATUS_FAILED,
                $limit
            )
        );
    }

    public function cleanup_old_items($days = 7) {
        global $wpdb;

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name}
                WHERE status IN (%s, %s)
                AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                self::STATUS_COMPLETED,
                self::STATUS_FAILED,
                $days
            )
        );
    }

    public function cancel_batch($batch_id) {
        global $wpdb;

        return $wpdb->update(
            $this->table_name,
            ['status' => self::STATUS_FAILED, 'error_message' => 'Cancelled by user'],
            ['batch_id' => $batch_id, 'status' => self::STATUS_PENDING],
            ['%s', '%s'],
            ['%s', '%s']
        );
    }
}
