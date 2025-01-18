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

    /**
     * Retrieves the global WordPress database object.
     *
     * @return wpdb The global WordPress database object.
     */
    private static function get_db() {
        global $wpdb;
        return $wpdb;
    }

    /**
     * Retrieves the name of the stats table in the database.
     *
     * @return string The name of the stats table.
     */
    private static function get_stats_table_name() {
        return self::get_db()->prefix . 'auto_alt_text_stats';
    }

    /**
     * Retrieves the name of the logs table in the database.
     *
     * @return string The name of the logs table.
     */
    private static function get_logs_table_name() {
        return self::get_db()->prefix . 'auto_alt_text_logs';
    }

    /**
     * Checks if the specified column exists in the given database table.
     *
     * @param string $table The name of the database table.
     * @param string $column The name of the column to check.
     * @return bool True if the column exists, false otherwise.
     */
    private static function column_exists($table, $column) {
        $wpdb = self::get_db();
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        return $columns !== false && in_array($column, $columns);
    }

    /**
     * Adds a version tracking column to the specified database table.
     *
     * This method checks if the `COLUMN_VERSION` column exists in the table, and if not, adds it with the current database version as the default value.
     *
     * @param string $table The name of the database table to add the version tracking column to.
     * @throws Exception If the version tracking column could not be added to the table.
     */
    private static function add_version_tracking($table) {
        $wpdb = self::get_db();

        if (!self::column_exists($table, self::COLUMN_VERSION)) {
            $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN " . self::COLUMN_VERSION . " varchar(10) NOT NULL DEFAULT '" . self::CURRENT_DB_VERSION . "'");
            if ($result === false) {
                throw new Exception('Failed to add version tracking column');
            }
        }
    }

    /**
     * Activates the plugin by creating or updating the necessary database tables.
     *
     * This method performs the following tasks:
     * - Adds version tracking columns to the stats and logs tables
     * - Adds additional columns to the stats table (generation_type, is_edited, edited_text, is_applied)
     * - Creates or updates the stats and logs tables with the necessary schema
     *
     * If any errors occur during the activation process, an error message is logged and displayed in the WordPress admin area.
     */
    public static function activate() {
        $wpdb = self::get_db();
        $charset_collate = $wpdb->get_charset_collate();
        $stats_table = self::get_stats_table_name();
        $logs_table = self::get_logs_table_name();

        try {
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


        } catch (Exception $e) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="error"><p>Auto Alt Text activation error: ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
}
