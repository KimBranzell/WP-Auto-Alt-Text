<?php
class Auto_Alt_Text_Statistics {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'auto_alt_text_stats';
    }

    /**
     * Returns a human-readable label for the given generation type.
     *
     * @param string $type The generation type, e.g. 'upload', 'manual', 'batch'.
     * @return string The human-readable label for the generation type.
     */
    public function get_generation_type_label($type) {
        $labels = [
            'upload' => 'Image upload',
            'manual' => 'Manual update',
            'batch' => 'Batch processing'
        ];

        return $labels[$type] ?? $type;
    }

    /**
     * Retrieves the count of orphaned records in the statistics table.
     *
     * Orphaned records are those where the corresponding post/image no longer exists.
     * This method performs a LEFT JOIN between the statistics table and the posts table
     * to find records where the post ID is NULL, indicating the post/image has been deleted.
     *
     * @return int The count of orphaned records in the statistics table.
     */
    public function get_orphaned_records_count() {
        global $wpdb;

        return $wpdb->get_var("
            SELECT COUNT(t1.id)
            FROM {$this->table_name} t1
            LEFT JOIN {$wpdb->posts} p ON t1.image_id = p.ID
            WHERE p.ID IS NULL
        ");
    }

    /**
     * Cleans up orphaned records from the statistics table.
     *
     * Orphaned records are those where the corresponding post/image no longer exists.
     * This method performs a DELETE query with a LEFT JOIN between the statistics table
     * and the posts table to remove records where the post ID is NULL, indicating the
     * post/image has been deleted.
     *
     * @return int The number of orphaned records that were deleted.
     */
    public function cleanup_orphaned_records() {
        global $wpdb;

        $deleted = $wpdb->query("
            DELETE t1 FROM {$this->table_name} t1
            LEFT JOIN {$wpdb->posts} p ON t1.image_id = p.ID
            WHERE p.ID IS NULL
        ");

        return $deleted;
    }

    /**
     * Tracks the generation of text for a given image.
     *
     * This method logs the generation event and inserts a record into the statistics table.
     * The record includes information such as the image ID, generated text, tokens used,
     * generation type, and whether the text was applied or edited.
     *
     * @param int    $image_id       The ID of the image for which the text was generated.
     * @param string $generated_text The text that was generated.
     * @param int    $tokens_used    The number of tokens used for the generation.
     * @param string $generation_type The type of generation (e.g. 'manual', 'upload', 'batch').
     * @param bool   $is_applied     Whether the generated text was applied to the image.
     * @param bool   $is_edited      Whether the generated text was edited.
     * @param string $edited_text    The edited text, if any.
     *
     * @return bool|int The result of the database insert operation.
     */
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

    /**
     * Returns the name of the statistics table.
     *
     * @return string The name of the statistics table.
     */
    public function get_table_name() {
        return $this->table_name;
    }

    /**
     * Retrieves various statistics related to the generation of text for images.
     *
     * This method queries the statistics table to gather the following information:
     * - Total number of generations
     * - Total number of tokens used
     * - Counts of generation types (manual, upload, batch)
     * - Details of the 10 most recent generations, including the update number for each image
     *
     * @return array An associative array containing the requested statistics.
     */
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
