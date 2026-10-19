<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Caches generated alt text in transients. Keys are built from the attachment
 * ID and everything that shaped the answer (file size and mtime, model,
 * detail, prompt), so the image file is never read just to make a key.
 */
class Auto_Alt_Text_Cache_Manager {
    private const CACHE_PREFIX = 'aat_img_';

    /** @param array $parts file fingerprint, model, effort, detail, instructions, input. */
    public static function get_cache_key($attachment_id, array $parts) {
        return self::CACHE_PREFIX . (int) $attachment_id . '_' . md5(wp_json_encode(self::normalize($parts)));
    }

    /** @return string|false */
    public static function get_cached_response($cache_key) {
        return get_transient($cache_key);
    }

    public static function set_cached_response($cache_key, $alt_text) {
        $days = max(1, (int) get_option('aat_cache_expiration', 30));
        set_transient($cache_key, $alt_text, DAY_IN_SECONDS * $days);
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

    /** Drops every cached variant for one attachment. */
    public static function invalidate_cache($attachment_id) {
        global $wpdb;
        $prefix = self::CACHE_PREFIX . (int) $attachment_id . '_';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . $prefix) . '%',
                $wpdb->esc_like('_transient_timeout_' . $prefix) . '%'
            )
        );
    }

    /** Sorts associative arrays recursively so key order never changes the hash. */
    private static function normalize($value) {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        return array_map([__CLASS__, 'normalize'], $value);
    }
}
