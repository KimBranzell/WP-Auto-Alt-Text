<?php
class Auto_Alt_Text_Statistics {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'auto_alt_text_stats';
    }

    public function track_generation($image_id, $generated_text, $tokens_used) {
        global $wpdb;

        error_log("Tracking generation for image ID: " . $image_id);
        error_log("Tokens used: " . $tokens_used);

        return $wpdb->insert(
            $this->table_name,
            [
                'image_id' => $image_id,
                'user_id' => get_current_user_id(),
                'generated_text' => $generated_text,
                'tokens_used' => $tokens_used,
                'generation_time' => current_time('mysql')
            ],
            ['%d', '%d', '%s', '%d', '%s']
        );
    }

    public function get_stats() {
        global $wpdb;

        return [
            'total_generations' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}"),
            'total_tokens' => $wpdb->get_var("SELECT SUM(tokens_used) FROM {$this->table_name}"),
            'recent_generations' => $wpdb->get_results("
                SELECT * FROM {$this->table_name}
                ORDER BY generation_time DESC
                LIMIT 10
            ")
        ];
    }
}
