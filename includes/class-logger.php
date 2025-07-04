<?php
class Auto_Alt_Text_Logger {
    private $table_name;
    const LOG_LEVELS = ['debug', 'info', 'warning', 'error'];

    /**
     * Checks if the plugin is in debug mode.
     *
     * @return bool True if the plugin is in debug mode, false otherwise.
     */
    private static function is_debug_mode() {
        return defined('AUTO_ALT_TEXT_DEBUG') && AUTO_ALT_TEXT_DEBUG;
    }

    /**
     * Logs a debug message if the plugin is in debug mode.
     *
     * @param string $message The message to log.
     * @param array $context Optional. Additional context to include in the log entry.
     */
    public static function debug($message, $context = []) {
        if (self::is_debug_mode()) {
            self::log($message, 'debug', $context);
        }
    }

    /**
     * Initializes the Auto_Alt_Text_Logger class.
     *
     * Retrieves the WordPress database prefix and stores it in the `$table_name` property.
     * Adds an action to the 'admin_menu' hook to add a logs page to the WordPress admin menu.
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'auto_alt_text_logs';
        add_action('admin_menu', [$this, 'add_logs_page']);
    }

    /**
     * Adds a submenu page to the WordPress admin menu for viewing the Auto Alt Text logs.
     *
     * This method is hooked to the 'admin_menu' action and creates a new submenu page under the 'auto-alt-text' parent menu item.
     * The submenu page allows users with the 'manage_options' capability to view the Auto Alt Text logs.
     */
    public function add_logs_page() {
        add_submenu_page(
            'auto-alt-text',
            'WP Auto Alt Text-loggar',
            'Loggar',
            'manage_options',
            'auto-alt-text-logs',
            [$this, 'render_logs_page']
        );
    }

    /**
     * Renders the Auto Alt Text logs page in the WordPress admin.
     *
     * This method is responsible for handling the display and functionality of the Auto Alt Text logs page.
     * It checks the user's capabilities, handles log export and clearing actions, retrieves the current logs based on
     * filters, and renders the logs table.
     */
    public function render_logs_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle export before any output
        if (isset($_POST['action']) && $_POST['action'] === 'export_logs') {
            check_admin_referer('auto_alt_text_logs_action');
            $this->export_logs();
            // export_logs() includes exit() so code won't continue if exporting
        }

        // Handle actions
        if (isset($_POST['action'])) {
            check_admin_referer('auto_alt_text_logs_action');

            switch ($_POST['action']) {
                case 'clear_logs':
                    $this->clear_logs();
                    break;
                case 'export_logs':
                    $this->export_logs();
                    break;
            }
        }

        // Get current filters
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';

        // Get logs with filters
        $logs = $this->get_logs([
            'page' => $current_page,
            'search' => $search,
            'level' => $level
        ]);

        // Render the page
        ?>
        <div class="wrap">
            <h1>WP Auto Alt Text-loggar</h1>

            <div class="tablenav top">
                <form method="get">
                    <input type="hidden" name="page" value="auto-alt-text-logs">
                    <div class="alignleft actions">
                        <select name="level">
                            <option value="">Alla Nivåer</option>
                            <?php foreach (self::LOG_LEVELS as $log_level): ?>
                                <option value="<?php echo esc_attr($log_level); ?>" <?php selected($level, $log_level); ?>>
                                    <?php echo esc_html(ucfirst($log_level)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Sök loggar...">
                        <?php submit_button('Filtrera', 'secondary', 'filter', false); ?>
                    </div>
                </form>

                <div class="alignright">
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('auto_alt_text_logs_action'); ?>
                        <input type="hidden" name="action" value="export_logs">
                        <?php submit_button('Exportera loggar', 'secondary', 'export', false); ?>
                    </form>

                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('auto_alt_text_logs_action'); ?>
                        <input type="hidden" name="action" value="clear_logs">
                        <?php submit_button('Rensa loggar', 'delete', 'clear', false); ?>
                    </form>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Tid</th>
                        <th>Nivå</th>
                        <th>Meddelande</th>
                        <th>Kontext</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr class="log-level-<?php echo esc_attr($log->level); ?>">
                            <td><?php echo esc_html($log->timestamp); ?></td>
                            <td><span class="log-level log-level-<?php echo esc_attr($log->level); ?>">
                                <?php echo esc_html(ucfirst($log->level)); ?>
                            </span></td>
                            <td><?php echo esc_html($log->message); ?></td>
                            <td><pre><?php echo esc_html($log->context); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Retrieves a set of logs based on the provided arguments.
     *
     * @param array $args {
     *     Optional. An array of arguments to customize the log query.
     *
     *     @type string $level     The log level to filter by.
     *     @type string $search    The search term to filter logs by message or context.
     *     @type int    $per_page  The number of logs to retrieve per page.
     *     @type int    $page      The page number to retrieve.
     *     @type string $orderby   The column to order the results by.
     *     @type string $order     The order direction (ASC or DESC).
     * }
     * @return array An array of log objects.
     */
    public function get_logs($args = []) {
        global $wpdb;

        $defaults = [
            'level' => '',
            'search' => '',
            'per_page' => 50,
            'page' => 1,
            'orderby' => 'timestamp',
            'order' => 'DESC'
        ];

        $args = wp_parse_args($args, $defaults);
        $where = '1=1';

        if ($args['level']) {
            $where .= $wpdb->prepare(' AND level = %s', $args['level']);
        }

        if ($args['search']) {
            $where .= $wpdb->prepare(' AND (message LIKE %s OR context LIKE %s)',
                '%' . $wpdb->esc_like($args['search']) . '%',
                '%' . $wpdb->esc_like($args['search']) . '%'
            );
        }

        $offset = ($args['page'] - 1) * $args['per_page'];

        return $wpdb->get_results(
            "SELECT * FROM {$this->table_name}
            WHERE {$where}
            ORDER BY {$args['orderby']} {$args['order']}
            LIMIT {$args['per_page']}
            OFFSET {$offset}"
        );
    }

    /**
     * Exports the logs stored in the database to a CSV file.
     *
     * This method retrieves the log entries from the database, formats them as a CSV file,
     * and sends the file to the client for download.
     */
    public function export_logs() {
        global $wpdb;

        // Get only the data we need
        $logs = $wpdb->get_results("
            SELECT timestamp, level, message, context
            FROM {$this->table_name}
            ORDER BY timestamp DESC
        ");

        // Direct output of CSV data
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=auto-alt-text-logs-' . date('Y-m-d') . '.csv');

        // Bypass any output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        $output = fopen('php://output', 'w');

        // Write headers
        fputcsv($output, ['Tid', 'Nivå', 'Meddelande', 'Kontext']);

        // Write data rows
        foreach ($logs as $log) {
            fputcsv($output, [
                $log->timestamp,
                $log->level,
                $log->message,
                $log->context
            ]);
        }

        fclose($output);
        die();
    }

    /**
     * Clears all logs from the database table.
     *
     * This method truncates the database table where the logs are stored, effectively
     * removing all log entries.
     */
    public function clear_logs() {
        global $wpdb;
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }

    /**
     * Cleans up old logs from the database table.
     *
     * This method deletes log entries from the database table that are older than the specified number of days.
     *
     * @param int $days The number of days to keep logs for. Defaults to 30 days.
     * @return int The number of rows deleted from the database table.
     */
    public function cleanup_old_logs($days = 30) {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    /**
     * Logs feedback-related events to track alt text improvement requests.
     *
     * @param string $improvement_type The type of improvement requested
     * @param int $attachment_id The attachment ID
     * @param array $context Additional context information
     * @return void
     */
    public static function log_feedback_event($improvement_type, $attachment_id, $context = []) {
        self::log("Alt text feedback: {$improvement_type}", "feedback", array_merge([
            'attachment_id' => $attachment_id,
            'improvement_type' => $improvement_type
        ], $context));
    }

    /**
     * Logs a message to the debug log and the database.
     *
     * This method logs a message with the specified level and context to both the debug.log file
     * and the database table for logging. The log entry is formatted with the current timestamp,
     * the log level, the message, and the context (if provided).
     *
     * @param string $message The message to log.
     * @param string $level The log level, defaults to 'info'.
     * @param array $context Additional context information to include with the log entry.
     */
    public static function log($message, $level = 'info', $context = []) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'auto_alt_text_logs';

        // Current logging to debug.log
        $log_entry = sprintf(
            '[%s] [%s] %s %s',
            current_time('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            !empty($context) ? json_encode($context) : ''
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($log_entry);
        }

        // Add to database
        $wpdb->insert(
            $table_name,
            [
                'timestamp' => current_time('mysql'),
                'level' => $level,
                'message' => $message,
                'context' => json_encode($context)
            ],
            ['%s', '%s', '%s', '%s']
        );
    }
}
