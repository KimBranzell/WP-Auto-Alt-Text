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
      echo sprintf(
          'Images Processed: %d<br>Tokens Used: %d',
          $stats['total_generations'],
          $stats['total_tokens'],
      );
  }
}
?>