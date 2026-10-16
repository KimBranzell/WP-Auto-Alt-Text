<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tracks whether WordPress's AI Connector Approval guard (the `ai` plugin,
 * later core Connectors) is blocking this plugin's OpenAI requests, and tells
 * administrators where to approve it. The guard is the site admin's consent
 * mechanism and must never be bypassed.
 */
class Auto_Alt_Text_Connector_Status {
    public const ERROR_CODE = 'wpai_connector_not_approved';
    public const BLOCKED_TRANSIENT = 'aat_connector_blocked';

    public static function mark_blocked($message) {
        set_transient(self::BLOCKED_TRANSIENT, (string) $message, DAY_IN_SECONDS);
    }

    public static function clear() {
        delete_transient(self::BLOCKED_TRANSIENT);
    }

    public static function is_blocked() {
        return false !== get_transient(self::BLOCKED_TRANSIENT);
    }

    /** Admin page where the `ai` plugin lists pending connector requests. */
    public static function approval_url() {
        return (string) apply_filters('auto_alt_text_connector_approval_url', admin_url('tools.php?page=ai-connector-approval'));
    }

    /** Shown on the Media Library and the plugin's own screens while blocked. */
    public static function render_admin_notice() {
        if (!self::is_blocked() || !current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || ('upload' !== $screen->id && false === strpos((string) $screen->id, 'auto-alt-text'))) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
            esc_html__('WP Auto Alt Text:', 'WP-Auto-Alt-Text'),
            esc_html__('WordPress AI has blocked the OpenAI connection for this plugin, so alt text generation is paused. An administrator needs to approve WP Auto Alt Text for the OpenAI connector.', 'WP-Auto-Alt-Text'),
            esc_url(self::approval_url()),
            esc_html__('Review connector requests', 'WP-Auto-Alt-Text')
        );
    }
}
