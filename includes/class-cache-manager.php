<?php
class Auto_Alt_Text_Cache_Manager {
    private const CACHE_PREFIX = 'aat_img_';
    private const ID_MAP_PREFIX = 'aat_img_id_';
    private const DEFAULT_EXPIRATION = DAY_IN_SECONDS * 30; // 30 days default

    public static function init() {
        add_action('wp_update_attachment_metadata', [self::class, 'handle_image_update'], 10, 2);
        add_action('edit_attachment', [self::class, 'invalidate_cache']);
        add_action('delete_attachment', [self::class, 'invalidate_cache']);

        // When image is edited in WordPress editor
        add_action('wp_ajax_image-editor', [self::class, 'handle_image_edit'], 9);

        // When attachment alt text is directly modified
        add_action('update_post_meta', [self::class, 'handle_meta_update'], 10, 4);

        // When image is replaced via various plugins (passes attachment ID)
        add_action('enable_media_replace_upload_done', [self::class, 'invalidate_cache']);
    }

    public static function handle_image_update($metadata, $attachment_id) {
        self::invalidate_cache((int) $attachment_id);
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

    /**
     * @param string $image_path File path to the image.
     * @param string $api_response Cached alt text response.
     * @param int|null $attachment_id Optional attachment ID for invalidation by ID (e.g. on delete).
     */
    public static function set_cached_response($image_path, $api_response, $attachment_id = null) {
        $cache_key = self::get_cache_key($image_path);
        $days = get_option('aat_cache_expiration', 30);
        $expiration = DAY_IN_SECONDS * $days;
        set_transient($cache_key, $api_response, $expiration);
        if ($attachment_id !== null && $attachment_id > 0) {
            set_transient(self::ID_MAP_PREFIX . $attachment_id, $cache_key, $expiration);
        }
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
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::ID_MAP_PREFIX . '%'
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

    /**
     * Invalidate cache by file path or attachment ID.
     * Hooks edit_attachment and delete_attachment pass attachment ID; others may pass path.
     *
     * @param string|int $path_or_id File path or attachment post ID.
     */
    public static function invalidate_cache($path_or_id) {
        if (is_numeric($path_or_id)) {
            $attachment_id = (int) $path_or_id;
            $stored_key = get_transient(self::ID_MAP_PREFIX . $attachment_id);
            if ($stored_key) {
                delete_transient($stored_key);
            }
            delete_transient(self::ID_MAP_PREFIX . $attachment_id);
            $image_path = get_attached_file($attachment_id);
            if ($image_path && is_string($image_path) && file_exists($image_path)) {
                $cache_key = self::get_cache_key($image_path);
                delete_transient($cache_key);
            }
            return;
        }
        $image_path = $path_or_id;
        if (is_string($image_path) && $image_path !== '' && file_exists($image_path)) {
            $cache_key = self::get_cache_key($image_path);
            delete_transient($cache_key);
        }
    }
}
