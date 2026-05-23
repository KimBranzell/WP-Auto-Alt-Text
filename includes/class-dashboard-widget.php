<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Auto_Alt_Text_Dashboard_Widget {
  private $statistics;
  public function __construct() {
    if (!class_exists('Auto_Alt_Text_Statistics')) {
      throw new RuntimeException('Required Statistics class not found');
    }
    $this->statistics = new Auto_Alt_Text_Statistics();
    add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);
  }

  /**
   * Registers a WordPress dashboard widget to display Auto Alt Text statistics.
   */
  public function register_dashboard_widget() {
    wp_add_dashboard_widget(
      'auto_alt_text_stats',
      'Auto Alt Text Statistics',
      [$this, 'display_stats_widget']
    );
  }

  /**
   * Displays the Auto Alt Text statistics on the WordPress dashboard.
   * This method retrieves the statistics from the Auto_Alt_Text_Statistics class
   * and formats them for display in the dashboard widget.
   */
  public function display_stats_widget() {
      $stats = $this->statistics->get_stats();
      $total_generations = isset( $stats['total_generations'] ) ? (int) $stats['total_generations'] : 0;
      $total_tokens = isset( $stats['total_tokens'] ) ? (int) $stats['total_tokens'] : 0;

      $queue_manager = new Auto_Alt_Text_Queue_Manager();
      $queue_status = $queue_manager->get_queue_status();
      ?>
      <p>
      <?php echo esc_html__( 'Images Processed:', 'WP-Auto-Alt-Text' ); ?> <?php echo esc_html( number_format_i18n( $total_generations ) ); ?><br>
      <?php echo esc_html__( 'Tokens Used:', 'WP-Auto-Alt-Text' ); ?> <?php echo esc_html( number_format_i18n( $total_tokens ) ); ?>
      </p>
      <?php if ($queue_status['total'] > 0): ?>
      <hr>
      <p>
        <strong><?php esc_html_e('Background Queue:', 'WP-Auto-Alt-Text'); ?></strong><br>
        <?php esc_html_e('Pending:', 'WP-Auto-Alt-Text'); ?> <?php echo esc_html($queue_status['pending']); ?><br>
        <?php esc_html_e('Processing:', 'WP-Auto-Alt-Text'); ?> <?php echo esc_html($queue_status['processing']); ?><br>
        <?php esc_html_e('Completed:', 'WP-Auto-Alt-Text'); ?> <?php echo esc_html($queue_status['completed']); ?><br>
        <?php if ($queue_status['failed'] > 0): ?>
          <?php esc_html_e('Failed:', 'WP-Auto-Alt-Text'); ?> <?php echo esc_html($queue_status['failed']); ?><br>
        <?php endif; ?>
      </p>
      <?php endif; ?>
      <?php
  }
}
?>