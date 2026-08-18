<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tracks OpenAI quota/billing exhaustion as an "incident": one email per
 * incident, a persistent admin notice while active, and a probe gate the
 * queue runner uses to test recovery without hammering the API.
 */
class Auto_Alt_Text_Quota_Monitor {
    public const OPTION = 'aat_quota_incident';

    /** Timestamp of the last incident email; survives incident clearing. */
    public const LAST_EMAIL_OPTION = 'aat_quota_last_email_at';

    private const PROBE_INTERVAL = 15 * MINUTE_IN_SECONDS;

    public static function get_incident() {
        $incident = get_option(self::OPTION);
        return (is_array($incident) && !empty($incident['active'])) ? $incident : null;
    }

    public static function is_active() {
        return null !== self::get_incident();
    }

    /**
     * Records a terminal quota/billing failure. Activates the incident and
     * sends exactly one admin email per incident.
     */
    public static function record_failure($message) {
        $incident = self::get_incident();

        if (null === $incident) {
            $incident = [
                'active'          => true,
                'detected_at'     => time(),
                'last_probe_at'   => time(),
                'last_error'      => (string) $message,
                'email_sent'      => false,
                'notice_dismissed' => false,
            ];
        } else {
            $incident['last_error'] = (string) $message;
        }

        if (empty($incident['email_sent'])) {
            // Floor across incidents: a quota flapping around a billing limit
            // clears and re-creates the incident repeatedly; without this, each
            // flap would re-arm the one-email-per-incident guard.
            $last_email_at = (int) get_option(self::LAST_EMAIL_OPTION, 0);
            if ((time() - $last_email_at) < DAY_IN_SECONDS) {
                Auto_Alt_Text_Logger::log('Quota incident email suppressed — one already sent within 24 h', 'info');
                $incident['email_sent'] = true;
            }
        }

        if (empty($incident['email_sent'])) {
            $sent = wp_mail(
                get_option('admin_email'),
                sprintf(
                    /* translators: %s: site name */
                    __('[%s] OpenAI quota exhausted — alt text generation paused', 'WP-Auto-Alt-Text'),
                    wp_specialchars_decode(get_option('blogname'), ENT_QUOTES)
                ),
                sprintf(
                    /* translators: 1: error message from OpenAI, 2: settings page URL */
                    __("WP Auto Alt Text has paused background alt text generation because OpenAI reported an exhausted quota or billing limit.\n\nAPI message: %1\$s\n\nQueued images are kept and will be processed automatically once the quota is restored. Check your OpenAI billing at https://platform.openai.com/settings/organization/billing and the plugin status at %2\$s.\n\nYou will not receive further emails for this incident.", 'WP-Auto-Alt-Text'),
                    (string) $message,
                    admin_url('admin.php?page=auto-alt-text')
                )
            );
            if (!$sent) {
                Auto_Alt_Text_Logger::log('Quota incident email failed to send', 'error', [
                    'recipient' => get_option('admin_email'),
                ]);
            }
            $incident['email_sent'] = true; // one attempt per incident, even if wp_mail fails
            update_option(self::LAST_EMAIL_OPTION, time(), false);
        }

        update_option(self::OPTION, $incident, false);

        Auto_Alt_Text_Logger::log('OpenAI quota exhausted — generation paused', 'error', [
            'api_message' => (string) $message,
        ]);
    }

    /** Clears the incident (called on any successful API response). */
    public static function record_success() {
        if (false !== get_option(self::OPTION)) {
            delete_option(self::OPTION);
            Auto_Alt_Text_Logger::log('OpenAI quota restored — generation resumed', 'info');
        }
    }

    /**
     * While an incident is active, allows one probe per PROBE_INTERVAL.
     * Consuming the probe records the attempt time.
     */
    public static function should_probe() {
        $incident = self::get_incident();
        if (null === $incident) {
            return false;
        }
        if ((time() - (int) $incident['last_probe_at']) < self::PROBE_INTERVAL) {
            return false;
        }
        $incident['last_probe_at'] = time();
        update_option(self::OPTION, $incident, false);
        return true;
    }

    public static function dismiss_notice() {
        $incident = self::get_incident();
        if (null !== $incident) {
            $incident['notice_dismissed'] = true;
            update_option(self::OPTION, $incident, false);
        }
    }

    /** Echoes the admin notice while an incident is active and undismissed. */
    public static function render_admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $incident = self::get_incident();
        if (null === $incident || !empty($incident['notice_dismissed'])) {
            return;
        }
        $dismiss_url = wp_nonce_url(
            admin_url('admin-post.php?action=aat_dismiss_quota_notice'),
            'aat_dismiss_quota_notice'
        );
        printf(
            '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p><p><a href="%4$s">%5$s</a></p></div>',
            esc_html__('WP Auto Alt Text:', 'WP-Auto-Alt-Text'),
            esc_html(sprintf(
                /* translators: %s: date/time the incident started */
                __('OpenAI quota exhausted since %s. Background alt text generation is paused; queued images will resume automatically once billing is restored.', 'WP-Auto-Alt-Text'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $incident['detected_at'])
            )),
            esc_html(sprintf(
                /* translators: %s: raw error message from the OpenAI API */
                __('API message: %s', 'WP-Auto-Alt-Text'),
                $incident['last_error']
            )),
            esc_url($dismiss_url),
            esc_html__('Dismiss this notice for the current incident', 'WP-Auto-Alt-Text')
        );
    }

    /** admin-post handler for the dismiss link. */
    public static function handle_dismiss_request() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'WP-Auto-Alt-Text'));
        }
        check_admin_referer('aat_dismiss_quota_notice');
        self::dismiss_notice();
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
