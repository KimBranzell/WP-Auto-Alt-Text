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
        $stats = $this->statistics->get_stats();
        ?>
        <div class="wrap">
            <h1>Alt Text Generation Statistics</h1>

            <div class="stats-overview">
                <div class="stat-box">
                    <h3>Total Generations</h3>
                    <p class="stat-number"><?php echo esc_html($stats['total_generations']); ?></p>
                </div>
                <div class="stat-box">
                    <h3>Total Tokens Used</h3>
                    <p class="stat-number"><?php echo esc_html($stats['total_tokens']); ?></p>
                </div>
            </div>

            <h2>Recent Generations</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Generated Text</th>
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
                            <td><?php echo esc_html(get_user_by('id', $generation->user_id)->display_name); ?></td>
                            <td><?php echo esc_html($generation->generation_time); ?></td>
                            <td><?php echo esc_html($generation->tokens_used); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
