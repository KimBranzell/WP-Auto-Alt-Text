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
            __('Alt text statistics', 'wp-auto-alt-text'),  // Page title
            __('Statistics', 'wp-auto-alt-text'),          // Menu title
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

		// Pagination setup
		$per_page = 20;
		$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
		$offset = ($current_page - 1) * $per_page;

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
        $stats = $this->statistics->get_stats($per_page, $offset);

		$total_items = $this->statistics->get_total_generations_count();
		$total_pages = ceil($total_items / $per_page);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Alt text statistics', 'wp-auto-alt-text'); ?></h1>

            <?php if ($orphaned_count > 0): ?>
                <div class="notice notice-warning orphaned-alt-text-stats">
                    <p><?php echo esc_html(sprintf(__('Found %d orphaned records from deleted images.', 'wp-auto-alt-text'), $orphaned_count)); ?></p>
                    <form method="post">
                        <?php wp_nonce_field('auto_alt_text_cleanup'); ?>
                        <input type="submit" name="cleanup_stats" class="button" value="<?php esc_attr_e('Remove orphaned records', 'wp-auto-alt-text'); ?>">
                    </form>
                </div>
            <?php endif; ?>

            <?php
            $estimated_cost = $this->statistics->get_estimated_cost();
            $by_day = $this->statistics->get_stats_by_day(7);
            $max_gen = !empty($by_day['generations']) ? max($by_day['generations']) : 1;
            ?>
            <div class="stats-overview">
                <div class="stat-box">
                    <h3><?php esc_html_e('Total generations', 'wp-auto-alt-text'); ?></h3>
                    <p class="stat-number"><?php echo esc_html($stats['total_generations']); ?></p>
                </div>
                <div class="stat-box">
                    <h3><?php esc_html_e('Total tokens used', 'wp-auto-alt-text'); ?></h3>
                    <p class="stat-number"><?php echo esc_html(number_format_i18n($stats['total_tokens'])); ?></p>
                </div>
                <?php if ($estimated_cost !== null) : ?>
                <div class="stat-box">
                    <h3><?php esc_html_e('Estimated cost', 'wp-auto-alt-text'); ?></h3>
                    <p class="stat-number">$<?php echo esc_html(number_format($estimated_cost, 2)); ?></p>
                    <p class="description"><?php esc_html_e('Approximate; check your OpenAI usage.', 'wp-auto-alt-text'); ?></p>
                </div>
                <?php endif; ?>
                <div class="stat-box">
                    <h3><?php esc_html_e('Generation types', 'wp-auto-alt-text'); ?></h3>
                    <ul class="generation-types">
                        <li><?php echo esc_html(sprintf(__('Manual updates: %s', 'wp-auto-alt-text'), $stats['types']['manual'] ?? 0)); ?></li>
                        <li><?php echo esc_html(sprintf(__('Image uploads: %s', 'wp-auto-alt-text'), $stats['types']['upload'] ?? 0)); ?></li>
                        <li><?php echo esc_html(sprintf(__('Batch processing: %s', 'wp-auto-alt-text'), $stats['types']['batch'] ?? 0)); ?></li>
                    </ul>
                </div>
            </div>

            <?php if ($max_gen > 0) : ?>
            <h2><?php esc_html_e('Generations per day (last 7 days)', 'wp-auto-alt-text'); ?></h2>
            <div class="aat-stats-bars" style="display:flex;align-items:flex-end;gap:6px;height:50px;margin-bottom:1em;">
                <?php foreach ($by_day['generations'] as $i => $gen) :
                    $pct = $max_gen > 0 ? min(100, (int) round(( $gen / $max_gen ) * 100)) : 0;
                    $label = isset($by_day['labels'][$i]) ? $by_day['labels'][$i] : '';
                ?>
                    <div title="<?php echo esc_attr($label . ': ' . $gen); ?>" style="flex:1;background:#2271b1;height:<?php echo (int) $pct; ?>%;min-height:4px;border-radius:3px;"></div>
                <?php endforeach; ?>
            </div>
            <p style="font-size:12px;color:#646970;"><?php echo esc_html(implode(', ', $by_day['labels'])); ?></p>
            <?php endif; ?>

            <h2><?php esc_html_e('Generation history', 'wp-auto-alt-text'); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e('Image', 'wp-auto-alt-text'); ?></th>
						<th><?php esc_html_e('Generated text', 'wp-auto-alt-text'); ?></th>
						<th><?php esc_html_e('Type', 'wp-auto-alt-text'); ?></th>
						<th><?php esc_html_e('Status', 'wp-auto-alt-text'); ?></th>
						<th><?php esc_html_e('User', 'wp-auto-alt-text'); ?></th>
						<th><?php esc_html_e('Date', 'wp-auto-alt-text'); ?></th>
						<th><?php esc_html_e('Tokens', 'wp-auto-alt-text'); ?></th>
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
									echo '<span class="diff-label">' . esc_html__('Changes:', 'wp-auto-alt-text') . '</span>';
									echo '<div class="diff-view">' . $diff . '</div>';
									echo '</div>';
								}
								?>
							</td>
							<td><span class="generation-type <?php echo esc_attr($generation->generation_type); ?>"><?php echo esc_html($generation->generation_type); ?></span></td>
							<td>
								<?php if ($generation->is_applied): ?>
									<span class="status-badge applied">Applied</span>
								<?php endif; ?>
								<?php if ($generation->is_edited): ?>
									<span class="status-badge edited">Edited</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html(get_user_by('id', $generation->user_id)->display_name); ?></td>
							<td><?php echo esc_html(sprintf(__('%s ago', 'wp-auto-alt-text'), human_time_diff(strtotime($generation->generation_time), (int) current_time('timestamp')))); ?></td>
							<td><?php echo esc_html($generation->tokens_used); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ($total_pages > 1): ?>
            <div class="tablenav">
                <div class="tablenav-pages">
				<?php
					$links = paginate_links([
						'base'      => add_query_arg('paged', '%#%'),
						'format'    => '',
						'current'   => $current_page,
						'total'     => $total_pages,
						'prev_next' => true,
						'prev_text' => __('&laquo; Previous', 'wp-auto-alt-text'),
						'next_text' => __('Next &raquo;', 'wp-auto-alt-text'),
						'type'      => 'array',
					]);
					if ($links) {
						foreach ($links as &$link) {
							// Add button class to <a> and <span> elements
							$link = str_replace('<a ', '<a class="button button-secondary" ', $link);
							$link = str_replace('<span aria-current="page" class="page-numbers current"', '<span aria-current="page" class="button button-disabled current"', $link);
							echo $link;
						}
					}
				?>
                </div>
            </div>
        <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders the feedback statistics section.
     */
    private function render_feedback_stats() {
        $feedback_count = intval(get_option('alt_text_feedback_regeneration_count', 0));
        ?>
        <div class="stat-box">
            <h3><?php _e('Feedback', 'wp-auto-alt-text'); ?></h3>
            <div class="stat-content">
                <div class="stat-item">
                    <span class="stat-number"><?php echo $feedback_count; ?></span>
                    <span class="stat-label"><?php _e('Improvement requests', 'wp-auto-alt-text'); ?></span>
                </div>
            </div>
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
