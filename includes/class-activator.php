<?php
class Auto_Alt_Text_Activator {
    public static function activate() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'auto_alt_text_stats';

        // Check if generation_type column exists
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        if (!in_array('generation_type', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN generation_type varchar(50) NOT NULL DEFAULT 'manual'");
        }

        if (!in_array('is_edited', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN is_edited TINYINT(1) NOT NULL DEFAULT 0");
        }

        if (!in_array('edited_text', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN edited_text TEXT DEFAULT NULL");
        }

        if (!in_array('is_applied', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN is_applied TINYINT(1) NOT NULL DEFAULT 0");
        }

        // Create or update table structure
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

}
