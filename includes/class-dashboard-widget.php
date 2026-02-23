<?php
class Auto_Alt_Text_Dashboard_Widget {
    private $statistics;

    public function __construct() {
        if (!class_exists('Auto_Alt_Text_Statistics')) {
            throw new RuntimeException('Required Statistics class not found');
        }
        $this->statistics = new Auto_Alt_Text_Statistics();
        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);
    }

    public function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'auto_alt_text_stats',
            __('Auto Alt Text Statistics', 'wp-auto-alt-text'),
            [$this, 'display_stats_widget']
        );
    }

    public function display_stats_widget() {
        $stats = $this->statistics->get_stats(5, 0);
        $total_generations = $stats['total_generations'];
        $total_tokens = $stats['total_tokens'];
        $estimated_cost = $this->statistics->get_estimated_cost();
        $by_day = $this->statistics->get_stats_by_day(7);
        $max_gen = !empty($by_day['generations']) ? max($by_day['generations']) : 1;
        $settings_url = admin_url('options-general.php?page=auto-alt-text');
        ?>
        <p>
            <?php echo esc_html(sprintf(__('Images processed: %d', 'wp-auto-alt-text'), $total_generations)); ?>
            <br>
            <?php echo esc_html(sprintf(__('Tokens used: %s', 'wp-auto-alt-text'), number_format_i18n($total_tokens))); ?>
            <?php if ($estimated_cost !== null) : ?>
                <br>
                <strong><?php echo esc_html(sprintf(__('Estimated cost: $%s (approximate)', 'wp-auto-alt-text'), number_format($estimated_cost, 2))); ?></strong>
            <?php endif; ?>
        </p>
        <?php if ($max_gen > 0) : ?>
            <p><strong><?php esc_html_e('Generations per day (last 7 days)', 'wp-auto-alt-text'); ?></strong></p>
            <div class="aat-dashboard-bars" style="display:flex;align-items:flex-end;gap:4px;height:40px;margin-top:4px;">
                <?php foreach ($by_day['generations'] as $i => $gen) :
                    $pct = $max_gen > 0 ? min(100, (int) round(( $gen / $max_gen ) * 100)) : 0;
                    $label = isset($by_day['labels'][$i]) ? $by_day['labels'][$i] : '';
                ?>
                    <div title="<?php echo esc_attr($label . ': ' . $gen); ?>" style="flex:1;background:#2271b1;height:<?php echo (int) $pct; ?>%;min-height:2px;border-radius:2px;"></div>
                <?php endforeach; ?>
            </div>
            <p style="font-size:11px;color:#646970;margin-top:4px;"><?php echo esc_html(implode(', ', $by_day['labels'])); ?></p>
        <?php endif; ?>
        <p><a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Configure price per 1k tokens (Settings &rarr; Auto Alt Text)', 'wp-auto-alt-text'); ?></a></p>
        <?php
    }
}