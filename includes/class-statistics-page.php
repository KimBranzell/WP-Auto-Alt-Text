<?php

require_once ABSPATH . WPINC . '/Text/Diff.php';
require_once ABSPATH . WPINC . '/Text/Diff/Renderer.php';
require_once ABSPATH . WPINC . '/Text/Diff/Renderer/inline.php';


class Auto_Alt_Text_Statistics_Page {
    private $statistics;

    public function __construct() {
        $this->statistics = new Auto_Alt_Text_Statistics();
        add_action('admin_menu', [$this, 'add_statistics_page']);
    }

    /**
     * Adds a new submenu page for the Alt Text Statistics under the "Auto Alt Text" admin menu.
     *
     * This method is hooked to the 'admin_menu' action and creates a new submenu page with the title "Alt Text Statistics"
     * and the menu title "Statistics". The page is accessible to users with the 'manage_options' capability and has the
     * menu slug 'auto-alt-text-stats'. The 'render_statistics_page' method is called when the page is accessed.
     */
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

    /**
     * Renders the Alt Text Generation Statistics page in the WordPress admin.
     *
     * This method is responsible for displaying the statistics related to the auto-generated alt text, including the total
     * number of generations, total tokens used, generation types, and a history of recent generations. It also provides
     * functionality to clean up any orphaned records from deleted images.
     */
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
                        <th>Status</th>
                        <th>User</th>
                        <th>Date</th>
                        <th>Tokens</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['recent_generations'] as $generation): ?>
                        <tr>
                            <td><?php echo wp_get_attachment_image($generation->image_id, [50, 50]); ?></td>
                            <td>
                                <?php
                                echo esc_html($generation->generated_text);
                                if ($generation->is_edited) {
                                    $diff = $this->text_diff($generation->generated_text, $generation->edited_text);
                                    echo '<div class="edited-text">';
                                    echo '<span class="diff-label">Changes:</span>';
                                    echo '<div class="diff-view">' . $diff . '</div>';
                                    echo '</div>';
                                }
                                ?>
                            </td>
                            <td><span class="generation-type <?php echo esc_attr($generation->generation_type); ?>"><?php echo esc_html($generation->generation_type); ?></span></td>
                            <td><?php echo esc_html($generation->update_number); ?></td>
                            <td>
                                <?php if ($generation->is_applied): ?>
                                    <span class="status-badge applied">Applied</span>
                                <?php endif; ?>
                                <?php if ($generation->is_edited): ?>
                                    <span class="status-badge edited">Edited</span>
                                <?php endif; ?>
                            </td>
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

    /**
     * Generates a text diff between the old and new text.
     *
     * This private function is used to generate a text diff between the old and new text. It uses the Text_Diff and Text_Diff_Renderer_Inline classes to generate the diff.
     *
     * @param string $old_text The old text to compare.
     * @param string $new_text The new text to compare.
     * @return string The rendered text diff.
     */
    private function text_diff($old_text, $new_text) {
        $old_words = preg_split('/\s+/', $old_text);
        $new_words = preg_split('/\s+/', $new_text);

        $diff = new Text_Diff($old_words, $new_words);
        $renderer = new Text_Diff_Renderer_Inline();

        return $renderer->render($diff);
    }
}
