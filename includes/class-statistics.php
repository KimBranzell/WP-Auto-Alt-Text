<?php
class Auto_Alt_Text_Statistics {
    private $table_name;

    public function get_generation_type_label($type) {
        $labels = [
            'upload' => 'Image upload',
            'manual' => 'Manual update',
            'batch' => 'Batch processing'
        ];

        return $labels[$type] ?? $type;
    }

    public function get_orphaned_records_count() {
        global $wpdb;

        return $wpdb->get_var("
            SELECT COUNT(t1.id)
            FROM {$this->table_name} t1
            LEFT JOIN {$wpdb->posts} p ON t1.image_id = p.ID
            WHERE p.ID IS NULL
        ");
    }

    public function cleanup_orphaned_records() {
        global $wpdb;

        $deleted = $wpdb->query("
            DELETE t1 FROM {$this->table_name} t1
            LEFT JOIN {$wpdb->posts} p ON t1.image_id = p.ID
            WHERE p.ID IS NULL
        ");

        return $deleted;
    }

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'auto_alt_text_stats';
    }

    public function track_generation($image_id, $generated_text, $tokens_used, $generation_type, $is_applied = false, $is_edited = false, $edited_text = null) {
        global $wpdb;

        Auto_Alt_Text_Logger::log("Tracking generation stats", "debug", [
            'image_id' => $image_id,
            'tokens' => $tokens_used,
            'type' => $generation_type
        ]);

        return $wpdb->insert(
            $this->table_name,
            [
                'image_id' => $image_id,
                'user_id' => get_current_user_id(),
                'generated_text' => $generated_text,
                'tokens_used' => $tokens_used,
                'generation_type' => $generation_type,
                'generation_time' => current_time('mysql'),
                'is_applied' => $is_applied,
                'is_edited' => $is_edited,
                'edited_text' => $edited_text
            ],
            ['%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s']
        );
    }

    public function get_table_name() {
        return $this->table_name;
    }

    public function get_stats() {
        global $wpdb;

        return [
            'total_generations' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}"),
            'total_tokens' => $wpdb->get_var("SELECT SUM(tokens_used) FROM {$this->table_name}"),
            'types' => [
                'manual' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE generation_type = 'manual'"),
                'upload' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE generation_type = 'upload'"),
                'batch' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE generation_type = 'batch'")
            ],
            'recent_generations' => $wpdb->get_results("
                SELECT t1.*,
                        (SELECT COUNT(*)
                        FROM {$this->table_name} t2
                        WHERE t2.image_id = t1.image_id
                        AND t2.generation_time <= t1.generation_time) as update_number
                FROM {$this->table_name} t1
                ORDER BY t1.generation_time DESC
                LIMIT 10
            ")
        ];
    }
}
