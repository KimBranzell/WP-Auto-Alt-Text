<?php
class Auto_Alt_Text_Logger {
    private $table_name;
    const LOG_LEVELS = ['debug', 'info', 'warning', 'error'];

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'auto_alt_text_logs';
        add_action('admin_menu', [$this, 'add_logs_page']);
    }

    public function add_logs_page() {
        add_submenu_page(
            'auto-alt-text',
            'Logs',
            'View Logs',
            'manage_options',
            'auto-alt-text-logs',
            [$this, 'render_logs_page']
        );
    }

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
            <h1>Auto Alt Text Logs</h1>

            <div class="tablenav top">
                <form method="get">
                    <input type="hidden" name="page" value="auto-alt-text-logs">
                    <div class="alignleft actions">
                        <select name="level">
                            <option value="">All Levels</option>
                            <?php foreach (self::LOG_LEVELS as $log_level): ?>
                                <option value="<?php echo esc_attr($log_level); ?>" <?php selected($level, $log_level); ?>>
                                    <?php echo esc_html(ucfirst($log_level)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search logs...">
                        <?php submit_button('Filter', 'secondary', 'filter', false); ?>
                    </div>
                </form>

                <div class="alignright">
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('auto_alt_text_logs_action'); ?>
                        <input type="hidden" name="action" value="export_logs">
                        <?php submit_button('Export Logs', 'secondary', 'export', false); ?>
                    </form>

                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('auto_alt_text_logs_action'); ?>
                        <input type="hidden" name="action" value="clear_logs">
                        <?php submit_button('Clear Logs', 'delete', 'clear', false); ?>
                    </form>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Level</th>
                        <th>Message</th>
                        <th>Context</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
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
        fputcsv($output, ['Timestamp', 'Level', 'Message', 'Context']);

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

    public function clear_logs() {
        global $wpdb;
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }

    public function cleanup_old_logs($days = 30) {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

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
