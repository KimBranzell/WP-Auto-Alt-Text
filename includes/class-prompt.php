<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builds the alt-text prompt (spec §4). build() is pure; from_settings()
 * gathers its arguments from the plugin's options. Rules go in the request's
 * `instructions`, facts about the image in its `input`.
 */
class Auto_Alt_Text_Prompt {
    public const NAMING_OPTION = 'auto_alt_text_naming';
    public const EXTRA_OPTION = 'auto_alt_text_extra_instructions';
    public const NAMING_VISIBLE = 'visible';
    public const NAMING_PRODUCT = 'product';
    public const NAMING_PRODUCT_BRAND = 'product_brand';
    public const NAMINGS = [self::NAMING_VISIBLE, self::NAMING_PRODUCT, self::NAMING_PRODUCT_BRAND];
    public const DEFAULT_MAX_LENGTH = 125;
    public const EXTRA_MAX_LENGTH = 1000;
    public const PRODUCT_CONTEXT_TYPES = ['product', 'variation'];

    private const LEGACY_BRAND_OPTION = 'wp_auto_alt_text_enable_brand_tonality';
    private const CONTEXT_LABELS = ['product' => 'product', 'variation' => 'product variant', 'page' => 'page', 'post' => 'post'];
    private const NAME_SEPARATOR = ' – ';

    /**
     * @param array $args language_name, naming, context, brand_name, brand_description,
     *                    filename_hint, extra_instructions, max_length, feedback.
     * @return array{instructions:string,input:string}
     */
    public static function build(array $args) {
        $args = array_merge([
            'language_name'      => 'English',
            'naming'             => self::NAMING_VISIBLE,
            'context'            => null,
            'brand_name'         => '',
            'brand_description'  => '',
            'filename_hint'      => null,
            'extra_instructions' => '',
            'max_length'         => self::DEFAULT_MAX_LENGTH,
            'feedback'           => null,
        ], $args);

        $naming = in_array($args['naming'], self::NAMINGS, true) ? $args['naming'] : self::NAMING_VISIBLE;
        $context = is_array($args['context']) && '' !== self::quote_safe($args['context']['title'] ?? '') ? $args['context'] : null;
        $language = self::one_line($args['language_name']);
        $max_length = max(20, (int) $args['max_length']);

        $rules = [
            'You write alt text for images on a website. Screen-reader users hear it instead of seeing the image.',
            '- Reply with the alt text only: no quotes, labels or explanations.',
            "- Write in {$language}. Keep product and brand names exactly as given.",
            "- Aim for at most {$max_length} characters. Go longer only to include important text printed in the image.",
            '- Start with the main subject, then add what is visible and useful: colour, material, shape, action, setting.',
            "- Do not begin with \"Image of\", \"Picture of\", \"Photo of\" or the equivalent in {$language}.",
            '- Describe only what you can see. Never guess a brand, model, size or material.',
            '- No marketing language or opinions (for example "high-quality", "perfect for", "stylish").',
            '- Transcribe important printed text exactly (product name, brand, key claim). Skip fine print and watermarks.',
        ];
        $rules = array_merge($rules, self::naming_rules($naming, $context, self::quote_safe($args['brand_name'])));

        $extra = self::cap(trim((string) $args['extra_instructions']));
        if ('' !== $extra) {
            $rules[] = 'Additional instructions from the site owner. Follow them unless they conflict with the reply format above:';
            $rules[] = $extra;
        }

        $input = [];
        if ($context) {
            $input[] = sprintf(
                'This image belongs to the %s "%s".',
                self::CONTEXT_LABELS[$context['type'] ?? ''] ?? 'page',
                self::quote_safe($context['title'])
            );
        }
        if (is_string($args['filename_hint']) && '' !== $args['filename_hint']) {
            $input[] = sprintf('Filename (may be unreliable): "%s".', self::quote_safe($args['filename_hint']));
        }
        $description = self::one_line($args['brand_description']);
        if ('' !== $description && self::NAMING_VISIBLE !== $naming) {
            $input[] = 'About the shop (background for word choice only; never repeat it): ' . $description;
        }
        if (is_array($args['feedback'])) {
            $input[] = sprintf('Previous alt text: "%s".', self::quote_safe($args['feedback']['previous'] ?? ''));
            $input[] = 'Improve it: ' . self::one_line($args['feedback']['text'] ?? '');
        }
        if (empty($input)) {
            $input[] = 'Write alt text for this image.';
        }

        return ['instructions' => implode("\n", $rules), 'input' => implode("\n", $input)];
    }

    /**
     * @param int        $attachment_id
     * @param string     $language_code Normalized language code, e.g. 'sv'.
     * @param array|null $context       From Auto_Alt_Text_Context.
     * @param array      $overrides     'naming' and 'feedback'.
     */
    public static function from_settings($attachment_id, $language_code, $context, array $overrides = []) {
        return self::build([
            'language_name'      => self::language_name($language_code),
            'naming'             => self::naming($overrides['naming'] ?? null),
            'context'            => $context,
            'brand_name'         => (string) get_option('aat_brand_name', ''),
            'brand_description'  => (string) get_option('aat_brand_description', ''),
            'filename_hint'      => self::filename_hint((string) get_attached_file((int) $attachment_id)),
            'extra_instructions' => (string) get_option(self::EXTRA_OPTION, ''),
            'max_length'         => (int) apply_filters('auto_alt_text_max_length', self::DEFAULT_MAX_LENGTH),
            'feedback'           => $overrides['feedback'] ?? null,
        ]);
    }

    /** A valid override, else the setting; before the 1.3 migration, the old brand switch. */
    public static function naming($override = null) {
        if (is_string($override) && in_array($override, self::NAMINGS, true)) {
            return $override;
        }
        $saved = get_option(self::NAMING_OPTION, null);
        if (is_string($saved) && in_array($saved, self::NAMINGS, true)) {
            return $saved;
        }
        return get_option(self::LEGACY_BRAND_OPTION, false) ? self::NAMING_PRODUCT_BRAND : self::NAMING_VISIBLE;
    }

    public static function language_name($code) {
        $code = (string) $code;
        if (defined('AUTO_ALT_TEXT_LANGUAGES') && isset(AUTO_ALT_TEXT_LANGUAGES[$code])) {
            return AUTO_ALT_TEXT_LANGUAGES[$code];
        }
        return 'da' === $code ? 'Danska' : 'English';
    }

    /**
     * The filename as words when it looks human-made, else null (spec §4):
     * hashes, camera names and random IDs only add noise.
     */
    public static function filename_hint($path) {
        $name = pathinfo((string) $path, PATHINFO_FILENAME);
        $name = preg_replace('/-(scaled|rotated|\d+x\d+|e\d{10,})$/i', '', $name);
        $name = preg_replace('/-\d{1,2}$/', '', $name);

        $kept = [];
        foreach (preg_split('/[\s._-]+/u', (string) $name, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $length = mb_strlen($token);
            if (ctype_digit($token)
                || ($length >= 8 && preg_match('/^[0-9a-f]+$/i', $token))
                || ($length >= 8 && preg_match('/\d/', $token) && preg_match('/\p{L}/u', $token))
                || preg_match_all('/\p{Lu}/u', mb_substr($token, 1)) >= 2) {
                continue;
            }
            $kept[] = $token;
        }

        $words = array_filter($kept, function ($token) {
            return (bool) preg_match('/^\p{L}{3,}$/u', $token);
        });
        return count($words) >= 2 ? implode(' ', $kept) : null;
    }

    /** Trims wrappers the model sometimes adds; never changes words (spec §4, G5). */
    public static function clean_output($text) {
        $text = trim((string) $text);
        $text = preg_replace('/^[#*`]+\s*/u', '', $text);
        $text = preg_replace('/\s*[*`]+$/u', '', $text);
        $pairs = ['"' => '"', "'" => "'", '“' => '”', '”' => '”', '„' => '“', '«' => '»', '‘' => '’'];
        $first = mb_substr($text, 0, 1);
        if (isset($pairs[$first]) && mb_strlen($text) >= 2 && mb_substr($text, -1) === $pairs[$first]) {
            $inner = mb_substr($text, 1, -1);
            if (false === mb_strpos($inner, $first) && false === mb_strpos($inner, $pairs[$first])) {
                $text = $inner;
            }
        }
        return trim(preg_replace('/[ \t\r\n]+/', ' ', $text));
    }

    /** Whether the text names the product, ignoring case, accents, dashes and quotes. */
    public static function contains_name($text, $name) {
        $needle = self::normalize_for_match($name);
        return '' === $needle || false !== mb_strpos(self::normalize_for_match($text), $needle);
    }

    public static function prefix_name($name, $text) {
        return $name . self::NAME_SEPARATOR . $text;
    }

    public static function name_rewrite_input($text, $name) {
        return sprintf(
            "Rewrite this alt text so it includes the product name \"%s\" exactly once. Keep everything else; reply with the alt text only.\nAlt text: \"%s\"",
            self::quote_safe($name),
            self::quote_safe($text)
        );
    }

    public static function sanitize_naming($value) {
        return is_string($value) && in_array($value, self::NAMINGS, true) ? $value : self::NAMING_VISIBLE;
    }

    public static function sanitize_extra($value) {
        return self::cap(trim(sanitize_textarea_field(is_string($value) ? $value : '')));
    }

    private static function naming_rules($naming, $context, $brand_name) {
        $is_product = $context && in_array($context['type'] ?? '', self::PRODUCT_CONTEXT_TYPES, true);
        if (self::NAMING_VISIBLE === $naming || !$is_product) {
            return ['- Mention a product or brand name only if it is readable in the image.'];
        }
        $rules = [sprintf('- Include the product name "%s" once, naturally.', self::quote_safe($context['title']))];
        if (self::NAMING_PRODUCT_BRAND === $naming && '' !== $brand_name) {
            $rules[] = sprintf('- Include the brand name "%s" once, naturally.', $brand_name);
        }
        return $rules;
    }

    private static function cap($text) {
        return trim(mb_substr((string) $text, 0, self::EXTRA_MAX_LENGTH));
    }

    private static function one_line($value) {
        return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(is_scalar($value) ? (string) $value : '')));
    }

    /** One line without double quotes: the value is quoted inside the prompt. */
    private static function quote_safe($value) {
        return str_replace('"', "'", self::one_line($value));
    }

    private static function normalize_for_match($value) {
        $value = remove_accents(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(['-', '–', '—', '‐', '‑', '‒', '−'], ' ', $value);
        $value = str_replace(['"', "'", '“', '”', '„', '«', '»', '‘', '’'], '', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
