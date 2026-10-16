<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tells AI-written alt text apart from text a person wrote.
 *
 * Whenever the plugin saves alt text it stores a fingerprint of the exact
 * stored value. Comparing the current alt text against that fingerprint
 * detects edits made anywhere (media modal, REST, imports, other plugins)
 * without hooking every write path.
 */
class Auto_Alt_Text_Provenance {
    public const META_KEY = '_aat_alt_provenance';
    public const ALT_META_KEY = '_wp_attachment_image_alt';

    public const STATE_MISSING = 'missing';
    public const STATE_AI = 'ai';
    public const STATE_HUMAN = 'human';

    public const POLICY_OPTION = 'auto_alt_text_overwrite_policy';
    public const POLICY_MISSING = 'missing';
    public const POLICY_AI = 'ai';
    public const POLICY_ALL = 'all';
    public const POLICIES = [self::POLICY_MISSING, self::POLICY_AI, self::POLICY_ALL];

    /**
     * Fingerprints the attachment's current alt text as written by the plugin.
     * Reads the stored value, so slashing and sanitising can never cause a
     * mismatch.
     *
     * @param int    $attachment_id
     * @param string $source Generation type of the write, e.g. 'upload', 'cli', 'manual'.
     */
    public static function record($attachment_id, $source) {
        $alt = self::current_alt($attachment_id);
        if ('' === $alt) {
            return;
        }
        update_post_meta((int) $attachment_id, self::META_KEY, [
            'hash'   => sha1($alt),
            'model'  => Auto_Alt_Text_OpenAI::get_model_name(),
            'at'     => time(),
            'source' => sanitize_key($source),
        ]);
    }

    /** Marks the current alt text as the editor's own by dropping the fingerprint. */
    public static function forget($attachment_id) {
        delete_post_meta((int) $attachment_id, self::META_KEY);
    }

    /** @return string One of the STATE_* constants. */
    public static function classify($attachment_id) {
        $alt = self::current_alt($attachment_id);
        if ('' === $alt) {
            return self::STATE_MISSING;
        }
        $meta = get_post_meta((int) $attachment_id, self::META_KEY, true);
        if (is_array($meta) && isset($meta['hash']) && hash_equals((string) $meta['hash'], sha1($alt))) {
            return self::STATE_AI;
        }
        return self::STATE_HUMAN;
    }

    /** Whether a batch run under $policy may (re)generate this image's alt text. */
    public static function allows_overwrite($attachment_id, $policy) {
        $state = self::classify($attachment_id);
        if (self::STATE_MISSING === $state || self::POLICY_ALL === $policy) {
            return true;
        }
        return self::POLICY_AI === $policy && self::STATE_AI === $state;
    }

    /** The site-wide batch policy setting, defaulting to 'missing'. */
    public static function default_policy() {
        return self::sanitize_policy(get_option(self::POLICY_OPTION, self::POLICY_MISSING));
    }

    public static function sanitize_policy($policy) {
        return in_array($policy, self::POLICIES, true) ? $policy : self::POLICY_MISSING;
    }

    private static function current_alt($attachment_id) {
        return trim((string) get_post_meta((int) $attachment_id, self::ALT_META_KEY, true));
    }
}
