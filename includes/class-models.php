<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The OpenAI models the plugin supports, with prices and the request settings
 * each one needs (spec §6). Extend or override through `auto_alt_text_models`.
 */
class Auto_Alt_Text_Models {
    public const OPTION = 'auto_alt_text_model';
    /** Used when no entry is marked recommended. */
    public const FALLBACK_ID = 'gpt-5.2';
    /** Typical usage per image for the cost hint: a ~1 MP image plus the prompt, and a short answer. */
    public const TYPICAL_INPUT_TOKENS = 1300;
    public const TYPICAL_OUTPUT_TOKENS = 60;

    private static $logged_unknown = false;

    /**
     * Every supported model, keyed by ID. Prices are USD per 1M tokens
     * (OpenAI pricing page, 2026-10-19). 'effort' is the reasoning effort to
     * send, or null to send no `reasoning` field.
     *
     * @return array<string,array>
     */
    public static function all() {
        $models = apply_filters('auto_alt_text_models', [
            'gpt-6-luna' => [
                'label'             => 'GPT-6 Luna',
                'input_price'       => 0.10,
                'output_price'      => 0.50,
                'efforts'           => ['none', 'low', 'medium', 'high', 'xhigh', 'max'],
                'effort'            => 'none',
                'max_output_tokens' => 300,
            ],
            'gpt-5.6-luna' => [
                'label'             => 'GPT-5.6 Luna',
                'input_price'       => 0.20,
                'output_price'      => 1.20,
                'efforts'           => ['none', 'low', 'medium', 'high', 'xhigh', 'max'],
                'effort'            => 'none',
                'max_output_tokens' => 300,
            ],
            'gpt-5.4-nano' => [
                'label'             => 'GPT-5.4 nano',
                'input_price'       => 0.20,
                'output_price'      => 1.25,
                'efforts'           => ['none', 'low', 'medium', 'high', 'xhigh'],
                'effort'            => 'none',
                'max_output_tokens' => 300,
            ],
            'gpt-5.4-mini' => [
                'label'             => 'GPT-5.4 mini',
                'input_price'       => 0.75,
                'output_price'      => 4.50,
                'efforts'           => ['none', 'low', 'medium', 'high', 'xhigh'],
                'effort'            => 'none',
                'max_output_tokens' => 300,
            ],
            'gpt-6.1-sol' => [
                'label'             => 'GPT-6.1 Sol',
                'input_price'       => 2.00,
                'output_price'      => 10.00,
                'efforts'           => ['low', 'medium', 'high', 'xhigh', 'max'],
                'effort'            => 'low',
                'max_output_tokens' => 1500,
                'recommended'       => true,
            ],
            'gpt-5.2' => [
                'label'             => 'GPT-5.2',
                'input_price'       => 1.75,
                'output_price'      => 14.00,
                'efforts'           => [],
                'effort'            => null,
                'max_output_tokens' => 300,
            ],
        ]);

        $normalized = [];
        foreach ((array) $models as $id => $model) {
            if (is_string($id) && '' !== $id && is_array($model)) {
                $normalized[$id] = self::normalize($id, $model);
            }
        }
        return $normalized;
    }

    /** @return array|null */
    public static function get($id) {
        $models = self::all();
        return is_string($id) && isset($models[$id]) ? $models[$id] : null;
    }

    public static function default_id() {
        $models = self::all();
        foreach ($models as $id => $model) {
            if ($model['recommended']) {
                return $id;
            }
        }
        return isset($models[self::FALLBACK_ID]) ? self::FALLBACK_ID : (string) array_key_first($models);
    }

    /** The setting, then the `auto_alt_text_model` filter; unknown IDs fall back to the default. */
    public static function current_id() {
        $saved = get_option(self::OPTION, '');
        $id = apply_filters('auto_alt_text_model', is_string($saved) && '' !== $saved ? $saved : self::default_id());
        if (is_string($id) && null !== self::get($id)) {
            return $id;
        }
        if (!self::$logged_unknown) {
            self::$logged_unknown = true;
            Auto_Alt_Text_Logger::log('Unknown OpenAI model; using the default instead', 'warning', [
                'model'   => is_scalar($id) ? (string) $id : '',
                'default' => self::default_id(),
            ]);
        }
        return self::default_id();
    }

    public static function current() {
        return self::get(self::current_id());
    }

    /** Settings sanitizer: a registry ID, or '' for "use the default". */
    public static function sanitize($value) {
        return is_string($value) && null !== self::get($value) ? $value : '';
    }

    /** Estimated USD per 1,000 images at typical usage, for the settings hint. */
    public static function cost_per_1000($id) {
        $model = self::get($id);
        if (null === $model) {
            return 0.0;
        }
        $per_image = (self::TYPICAL_INPUT_TOKENS * $model['input_price'] + self::TYPICAL_OUTPUT_TOKENS * $model['output_price']) / 1000000;
        return $per_image * 1000;
    }

    private static function normalize($id, array $model) {
        $effort = isset($model['effort']) && '' !== $model['effort'] ? (string) $model['effort'] : null;
        return [
            'id'                => $id,
            'label'             => (string) ($model['label'] ?? $id),
            'input_price'       => (float) ($model['input_price'] ?? 0),
            'output_price'      => (float) ($model['output_price'] ?? 0),
            'efforts'           => array_values(array_map('strval', (array) ($model['efforts'] ?? []))),
            'effort'            => $effort,
            'max_output_tokens' => max(16, (int) ($model['max_output_tokens'] ?? 300)),
            'recommended'       => !empty($model['recommended']),
        ];
    }
}
