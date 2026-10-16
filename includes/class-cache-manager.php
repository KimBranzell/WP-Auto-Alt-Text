<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Auto_Alt_Text_Cache_Manager {
    private const CACHE_PREFIX = 'aat_img_';
    private const DEFAULT_EXPIRATION = DAY_IN_SECONDS * 30; // 30 days default

    public static function get_cache_key($image_path, $context = []) {
        $cache_prefix = self::get_image_cache_prefix($image_path);

        if ($cache_prefix === '') {
            return '';
        }

        return $cache_prefix . '_' . md5(wp_json_encode(self::normalize_cache_context($context)));
    }

    public static function get_cached_response($image_path, $context = []) {
        $cache_key = self::get_cache_key($image_path, $context);

        if ($cache_key === '') {
            return false;
        }

        return get_transient($cache_key);
    }

    public static function set_cached_response($image_path, $api_response, $context = []) {
        $cache_key = self::get_cache_key($image_path, $context);

        if ($cache_key === '') {
            return;
        }

        $days = get_option('aat_cache_expiration', 30);
        $expiration = DAY_IN_SECONDS * $days;
        set_transient($cache_key, $api_response, $expiration);
    }

    public static function is_cached($image_path, $context = []) {
        $cache_key = self::get_cache_key($image_path, $context);

        if ($cache_key === '') {
            return false;
        }

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

    public static function invalidate_cache($image_reference) {
        $image_path = self::resolve_image_path($image_reference);
        $cache_prefix = self::get_image_cache_prefix($image_path);

        if ($cache_prefix === '') {
            return;
        }

        global $wpdb;

        $transient_pattern = $wpdb->esc_like('_transient_' . $cache_prefix) . '%';
        $timeout_pattern = $wpdb->esc_like('_transient_timeout_' . $cache_prefix) . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $transient_pattern,
                $timeout_pattern
            )
        );
    }

    /**
     * Builds the image-specific prefix used by all cache variants.
     *
     * @param string $image_path The attachment file path.
     * @return string
     */
    private static function get_image_cache_prefix($image_path) {
        $image_path = self::resolve_image_path($image_path);

        if (!is_string($image_path) || $image_path === '' || !file_exists($image_path)) {
            return '';
        }

        $content = file_get_contents($image_path);
        $mtime = filemtime($image_path);

        if ($content === false || $mtime === false) {
            return '';
        }

        return self::CACHE_PREFIX . md5($content . $mtime);
    }

    /**
     * Resolves an attachment ID or file path to a valid image path.
     *
     * @param int|string $image_reference Attachment ID or file path.
     * @return string
     */
    private static function resolve_image_path($image_reference) {
        if (is_numeric($image_reference)) {
            return (string) get_attached_file((int) $image_reference);
        }

        if (is_string($image_reference)) {
            return $image_reference;
        }

        return '';
    }

    /**
     * Normalizes cache context before hashing it into the transient key.
     *
     * @param array $context Cache context values.
     * @return array
     */
    private static function normalize_cache_context($context) {
        if (!is_array($context)) {
            return [];
        }

        ksort($context);

        return $context;
    }
}
