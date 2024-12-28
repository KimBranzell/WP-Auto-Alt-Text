<?php
class Auto_Alt_Text_Statistics_Page {
    private $statistics;

    public function __construct() {
        $this->statistics = new Auto_Alt_Text_Statistics();
        add_action('admin_menu', [$this, 'add_statistics_page']);
    }

    public function add_statistics_page() {
      add_submenu_page(
          'auto-alt-text',           // Parent slug
          'Alt Text Statistics',     // Page title
          'Statistics',              // Menu title
          'manage_options',          // Capability
          'auto-alt-text-stats',     // Menu slug
          [$this, 'render_statistics_page'],
          8
      );
    }

    public function render_statistics_page() {
        global $wpdb;

        if (isset($_POST['cleanup_stats']) && check_admin_referer('auto_alt_text_cleanup')) {
            $deleted = $this->statistics->cleanup_orphaned_records();
            add_settings_error(
                'auto_alt_text',
                'records-cleaned',
                sprintf('%d orphaned records removed.', $deleted),
                'updated'
            );
        }

        $orphaned_count = $this->statistics->get_orphaned_records_count();
        $stats = $this->statistics->get_stats();
        ?>
        <div class="wrap">
            <h1>Alt Text Generation Statistics</h1>

            <?php if ($orphaned_count > 0): ?>
                <div class="notice notice-warning orphaned-alt-text-stats">
                    <p><?php printf('Found %d orphaned records from deleted images.', $orphaned_count); ?></p>
                    <form method="post">
                        <?php wp_nonce_field('auto_alt_text_cleanup'); ?>
                        <input type="submit" name="cleanup_stats" class="button" value="Remove orphaned records">
                    </form>
                </div>
            <?php endif; ?>

            <div class="stats-overview">
                <div class="stat-box">
                    <h3>Total Generations</h3>
                    <p class="stat-number"><?php echo esc_html($stats['total_generations']); ?></p>
                </div>
                <div class="stat-box">
                    <h3>Total Tokens Used</h3>
                    <p class="stat-number"><?php echo esc_html($stats['total_tokens']); ?></p>
                </div>
                <div class="stat-box">
                    <h3>Generation Types</h3>
                    <ul class="generation-types">
                        <li>Manual updates: <?php echo esc_html($stats['types']['manual'] ?? 0); ?></li>
                        <li>Image uploads: <?php echo esc_html($stats['types']['upload'] ?? 0); ?></li>
                        <li>Batch processing: <?php echo esc_html($stats['types']['batch'] ?? 0); ?></li>
                    </ul>
                </div>
            </div>

            <h2>Generation History</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Generated Text</th>
                        <th>Type</th>
                        <th>Update #</th>
                        <th>User</th>
                        <th>Date</th>
                        <th>Tokens</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['recent_generations'] as $generation): ?>
                        <tr>
                            <td><?php echo wp_get_attachment_image($generation->image_id, [50, 50]); ?></td>
                            <td><?php echo esc_html($generation->generated_text); ?></td>
                            <td>
                                <span class="generation-type <?php echo esc_attr($generation->generation_type); ?>">
                                    <?php echo esc_html($this->statistics->get_generation_type_label($generation->generation_type)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($generation->update_number); ?></td>
                            <td><?php echo esc_html(get_user_by('id', $generation->user_id)->display_name); ?></td>
                            <td><?php echo esc_html(human_time_diff(strtotime($generation->generation_time), current_time('timestamp')) . ' ago'); ?></td>
                            <td><?php echo esc_html($generation->tokens_used); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
