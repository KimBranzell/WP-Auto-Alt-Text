<?php
class Auto_Alt_Text_Activator {
    /**
     * Activates the plugin by creating or updating the necessary database tables.
     *
     * @return void
     */
    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $stats_table = $wpdb->prefix . 'auto_alt_text_stats';

        try {
            // Check and add columns
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$stats_table}");
            if ($columns === false) {
                throw new Exception('Failed to retrieve columns from stats table');
            }

            // Add generation_type column
            if (!in_array('generation_type', $columns)) {
                $result = $wpdb->query("ALTER TABLE {$stats_table} ADD COLUMN generation_type varchar(50) NOT NULL DEFAULT 'manual'");
                if ($result === false) {
                    throw new Exception('Failed to add generation_type column');
                }
            }

            // Add is_edited column
            if (!in_array('is_edited', $columns)) {
                $result = $wpdb->query("ALTER TABLE {$stats_table} ADD COLUMN is_edited TINYINT(1) NOT NULL DEFAULT 0");
                if ($result === false) {
                    throw new Exception('Failed to add is_edited column');
                }
            }

            // Add edited_text column
            if (!in_array('edited_text', $columns)) {
                $result = $wpdb->query("ALTER TABLE {$stats_table} ADD COLUMN edited_text TEXT DEFAULT NULL");
                if ($result === false) {
                    throw new Exception('Failed to add edited_text column');
                }
            }

            // Add is_applied column
            if (!in_array('is_applied', $columns)) {
                $result = $wpdb->query("ALTER TABLE {$stats_table} ADD COLUMN is_applied TINYINT(1) NOT NULL DEFAULT 0");
                if ($result === false) {
                    throw new Exception('Failed to add is_applied column');
                }
            }

            // Create or update tables
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');


            $sql = "CREATE TABLE IF NOT EXISTS $stats_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                image_id bigint(20) NOT NULL,
                user_id bigint(20) NOT NULL,
                generated_text text NOT NULL,
                tokens_used int(11) NOT NULL DEFAULT 0,
                generation_type varchar(50) NOT NULL,
                generation_time datetime NOT NULL,
                is_applied tinyint(1) DEFAULT 0,
                is_edited tinyint(1) DEFAULT 0,
                edited_text text DEFAULT NULL,
                PRIMARY KEY  (id)
            ) $charset_collate;";

            $logs_table = $wpdb->prefix . 'auto_alt_text_logs';
            $sql_logs = "CREATE TABLE IF NOT EXISTS $logs_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                timestamp datetime DEFAULT CURRENT_TIMESTAMP,
                level varchar(10) NOT NULL,
                message text NOT NULL,
                context text,
                PRIMARY KEY  (id)
            ) $charset_collate;";

            $stats_result = dbDelta($sql);
            if (empty($stats_result)) {
                throw new Exception('Failed to create or update stats table');
            }

            $logs_result = dbDelta($sql_logs);
            if (empty($logs_result)) {
                throw new Exception('Failed to create or update logs table');
            }
        } catch (Exception $e) {
            error_log('Auto Alt Text Activation Error: ' . $e->getMessage());
            add_action('admin_notices', function() use ($e) {
                echo '<div class="error"><p>Auto Alt Text activation error: ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
}
