<?php
class Auto_Alt_Text_Activator {
    /**
     * Activates the plugin by creating or updating the necessary database tables.
     *
     * @return void
     */

    private const CURRENT_DB_VERSION = '1.0';
    private const COLUMN_VERSION = 'db_version';

    private const COLUMN_GENERATION_TYPE = 'generation_type';
    private const COLUMN_IS_EDITED = 'is_edited';
    private const COLUMN_EDITED_TEXT = 'edited_text';
    private const COLUMN_IS_APPLIED = 'is_applied';
    private const COLUMN_IMAGE_ID = 'image_id';
    private const COLUMN_USER_ID = 'user_id';
    private const COLUMN_GENERATED_TEXT = 'generated_text';
    private const COLUMN_TOKENS_USED = 'tokens_used';
    private const COLUMN_GENERATION_TIME = 'generation_time';

    private static function get_db() {
        global $wpdb;
        return $wpdb;
    }

    private static function get_stats_table_name() {
        return self::get_db()->prefix . 'auto_alt_text_stats';
    }

    private static function get_logs_table_name() {
        return self::get_db()->prefix . 'auto_alt_text_logs';
    }

    private static function column_exists($table, $column) {
        $wpdb = self::get_db();
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        return $columns !== false && in_array($column, $columns);
    }

    private static function add_version_tracking($table) {
        $wpdb = self::get_db();

        if (!self::column_exists($table, self::COLUMN_VERSION)) {
            $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN " . self::COLUMN_VERSION . " varchar(10) NOT NULL DEFAULT '" . self::CURRENT_DB_VERSION . "'");
            if ($result === false) {
                throw new Exception('Failed to add version tracking column');
            }
        }
    }

    public static function activate() {
        $wpdb = self::get_db();
        $charset_collate = $wpdb->get_charset_collate();
        $stats_table = self::get_stats_table_name();
        $logs_table = self::get_logs_table_name();

        try {
            // Add version tracking to both tables
            self::add_version_tracking($stats_table);
            self::add_version_tracking($logs_table);

            // Add generation_type column
            if (!self::column_exists($stats_table, self::COLUMN_GENERATION_TYPE)) {
                $result = $wpdb->query("ALTER TABLE {$stats_table} ADD COLUMN " . self::COLUMN_GENERATION_TYPE . " varchar(50) NOT NULL DEFAULT 'manual'");
                if ($result === false) {
                    throw new Exception('Failed to add generation_type column');
                }
            }

            // Add is_edited column
            if (!self::column_exists($stats_table, self::COLUMN_IS_EDITED)) {
                $result = $wpdb->query("ALTER TABLE {$stats_table} ADD COLUMN " . self::COLUMN_IS_EDITED . " TINYINT(1) NOT NULL DEFAULT 0");
                if ($result === false) {
                    throw new Exception('Failed to add is_edited column');
                }
            }

            // Add edited_text column
            if (!self::column_exists($stats_table, self::COLUMN_IS_EDITED)) {
                $result = $wpdb->query("ALTER TABLE {$stats_table} ADD COLUMN " . self::COLUMN_IS_EDITED . " TINYINT(1) NOT NULL DEFAULT 0");
                if ($result === false) {
                    throw new Exception('Failed to add is_edited column');
                }
            }

            // Add is_applied column
            if (!self::column_exists($stats_table, self::COLUMN_IS_APPLIED)) {
                $result = $wpdb->query("ALTER TABLE {$stats_table} ADD COLUMN " . self::COLUMN_IS_APPLIED . " TINYINT(1) NOT NULL DEFAULT 0");
                if ($result === false) {
                    throw new Exception('Failed to add is_applied column');
                }
            }

            // Create or update tables
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');


            $sql = "CREATE TABLE IF NOT EXISTS $stats_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                " . self::COLUMN_IMAGE_ID . " bigint(20) NOT NULL,
                " . self::COLUMN_USER_ID . " bigint(20) NOT NULL,
                " . self::COLUMN_GENERATED_TEXT . " text NOT NULL,
                " . self::COLUMN_TOKENS_USED . " int(11) NOT NULL DEFAULT 0,
                " . self::COLUMN_GENERATION_TYPE . " varchar(50) NOT NULL,
                " . self::COLUMN_GENERATION_TIME . " datetime NOT NULL,
                " . self::COLUMN_IS_APPLIED . " tinyint(1) DEFAULT 0,
                " . self::COLUMN_IS_EDITED . " tinyint(1) DEFAULT 0,
                " . self::COLUMN_EDITED_TEXT . " text DEFAULT NULL,
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
