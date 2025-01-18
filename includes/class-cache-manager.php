<?php
class Auto_Alt_Text_Cache_Manager {
    private const CACHE_PREFIX = 'aat_img_';
    private const DEFAULT_EXPIRATION = DAY_IN_SECONDS * 30; // 30 days default

    public static function init() {
        add_action('wp_update_attachment_metadata', [self::class, 'handle_image_update'], 10, 2);
        add_action('edit_attachment', [self::class, 'invalidate_cache']);
        add_action('delete_attachment', [self::class, 'invalidate_cache']);

        // When image is edited in WordPress editor
        add_action('wp_ajax_image-editor', [self::class, 'handle_image_edit'], 9);

        // When attachment alt text is directly modified
        add_action('update_post_meta', [self::class, 'handle_meta_update'], 10, 4);

        // When image is replaced via various plugins
        add_action('enable_media_replace_upload_done', [self::class, 'invalidate_cache']);

    }

    public static function handle_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key === '_wp_attachment_image_alt') {
            $file_path = get_attached_file($post_id);
            self::invalidate_cache($file_path);
        }
    }

    public static function handle_image_edit() {
        if (isset($_POST['postid'])) {
            $attachment_id = intval($_POST['postid']);
            $file_path = get_attached_file($attachment_id);
            self::invalidate_cache($file_path);
        }
    }

    public static function get_cache_key($image_path) {
        $content = file_get_contents($image_path);
        $mtime = filemtime($image_path);
        return self::CACHE_PREFIX . md5($content . $mtime);
    }

    public static function get_cached_response($image_path) {
        $cache_key = self::get_cache_key($image_path);
        return get_transient($cache_key);
    }

    public static function set_cached_response($image_path, $api_response) {
        $cache_key = self::get_cache_key($image_path);
        $days = get_option('aat_cache_expiration', 30);
        $expiration = DAY_IN_SECONDS * $days;
        set_transient($cache_key, $api_response, $expiration);
    }

    public static function is_cached($image_path) {
        $cache_key = self::get_cache_key($image_path);
        return get_transient($cache_key) !== false;
    }

    public static function clear_all_caches() {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::CACHE_PREFIX . '%'
            )
        );
    }

    public static function get_cache_size() {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::CACHE_PREFIX . '%'
            )
        );
    }

    public static function invalidate_cache($image_path) {
        $cache_key = self::get_cache_key($image_path);
        delete_transient($cache_key);
    }
}
