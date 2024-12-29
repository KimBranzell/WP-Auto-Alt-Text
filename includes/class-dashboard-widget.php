<?php
class Auto_Alt_Text_Dashboard_Widget {
  private $statistics;
  public function __construct() {
    $this->statistics = new Auto_Alt_Text_Statistics();
    add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);
  }
  public function register_dashboard_widget() {
    wp_add_dashboard_widget(
      'auto_alt_text_stats',
      'Auto Alt Text Statistics',
      [$this, 'display_stats_widget']
    );
  }

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