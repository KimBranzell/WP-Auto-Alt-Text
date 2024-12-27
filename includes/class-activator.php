<?php
class Auto_Alt_Text_Activator {
  public static function activate() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'auto_alt_text_stats';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
      id bigint(20) NOT NULL AUTO_INCREMENT,
      image_id bigint(20) NOT NULL,
      user_id bigint(20) NOT NULL,
      generated_text text NOT NULL,
      generation_time datetime DEFAULT CURRENT_TIMESTAMP,
      tokens_used int(11) NOT NULL DEFAULT 0,
      PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }
}
