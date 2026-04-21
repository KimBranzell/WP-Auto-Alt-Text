<?php

require_once dirname(__FILE__) . '/interfaces/interface-cli-command.php';

class Auto_Alt_Text_CLI implements Auto_Alt_Text_CLI_Command {
    private const QUERY_POSTS_PER_PAGE = -1;
    private const POST_TYPE = 'attachment';
    private const POST_MIME_TYPE = 'image';
    private const POST_STATUS = 'any';

    private $openai;
    private $statistics;
    private $language_manager;

    public function __construct(?Auto_Alt_Text_OpenAI $openai = null, ?Auto_Alt_Text_Statistics $statistics = null, ?Auto_Alt_Text_Language_Manager $language_manager = null) {
        try {
            $this->openai = $openai ?? new Auto_Alt_Text_OpenAI();
            $this->statistics = $statistics ?? new Auto_Alt_Text_Statistics();
            $this->language_manager = $language_manager ?? new Auto_Alt_Text_Language_Manager();
        } catch (Exception $e) {
            WP_CLI::error('Failed to initialize CLI: ' . $e->getMessage());
        }
    }

    /**
     * Generates alt text for images in the media library
     *
     * ### Options
     *
     * [--limit=<number>]
     * : Maximum number of images to process. Default: all images
     *
     * [--skip-existing]
     * : Skip images that already have alt text
     *
     * [--language=<codes>]
     * : Restrict generation to specific language codes. Accepts a comma-separated list when WPML or Polylang is active. Without a multilingual plugin, only a single override language is allowed.
     *
     * ### Examples
     *
     *     `wp auto-alt-text generate`
     *
     *     `wp auto-alt-text generate --limit=50`
     *
     *     `wp auto-alt-text generate --skip-existing`
     *
     *     `wp auto-alt-text generate --language=sv`
     *
     *     `wp auto-alt-text generate --language=sv,fr`
     *
     *     `wp auto-alt-text generate --brand-tonality`
     */
    public function generate($args, $assoc_args) {
        $limit = $this->validateLimit($assoc_args['limit'] ?? self::QUERY_POSTS_PER_PAGE);
        $skip_existing = isset($assoc_args['skip-existing']);
        $brand_tonality = isset($assoc_args['brand-tonality']);

        try {
            $requested_languages = $this->parse_requested_languages($assoc_args);
            $language_context = $this->resolve_language_context($requested_languages);
        } catch (InvalidArgumentException $e) {
            WP_CLI::error($e->getMessage());
        }

        $active_plugin = $language_context['active_plugin'];
        $language_override = $language_context['language_override'];

        // Temporarily override the brand tonality setting for this CLI run
        $original_setting = get_option('wp_auto_alt_text_enable_brand_tonality', false);
        update_option('wp_auto_alt_text_enable_brand_tonality', $brand_tonality);

        register_shutdown_function(function() use ($original_setting) {
            update_option('wp_auto_alt_text_enable_brand_tonality', $original_setting);
        });

        $query = [
            'post_type' => self::POST_TYPE,
            'post_mime_type' => self::POST_MIME_TYPE,
            'posts_per_page' => (!empty($requested_languages) && !empty($active_plugin)) ? self::QUERY_POSTS_PER_PAGE : $limit,
            'post_status' => self::POST_STATUS
        ];

        if ($brand_tonality) {
            WP_CLI::log('Brand tonality mode: Generating SEO-optimized alt text with brand elements');
        } else {
            WP_CLI::log('Accessibility mode: Generating accessible alt text');
        }

        if (!empty($requested_languages)) {
            $formatted_languages = implode(', ', $this->format_languages_for_log($requested_languages));

            if (!empty($active_plugin)) {
                WP_CLI::log(sprintf('Restricting generation to %s attachments: %s', $active_plugin, $formatted_languages));
            } else {
                WP_CLI::log(sprintf('Using one-run language override: %s', $formatted_languages));
            }
        }

        $images = get_posts($query);

        if (!empty($requested_languages) && !empty($active_plugin)) {
            $images = $this->filter_images_by_languages($images, $requested_languages);

            if ($limit !== self::QUERY_POSTS_PER_PAGE) {
                $images = array_slice($images, 0, $limit);
            }
        }

        $count = count($images);

        if ($count === 0) {
            update_option('wp_auto_alt_text_enable_brand_tonality', $original_setting);

            if (!empty($requested_languages) && !empty($active_plugin)) {
                WP_CLI::success(sprintf('No images matched the requested languages: %s.', implode(', ', $this->format_languages_for_log($requested_languages))));
                return;
            }

            WP_CLI::success('No images found to process.');
            return;
        }

        WP_CLI::log(sprintf('Processing %d images...', $count));

        $progress = \WP_CLI\Utils\make_progress_bar('Generating alt text', $count);

        foreach ($images as $image) {
            if ($skip_existing) {
                $existing_alt = get_post_meta($image->ID, '_wp_attachment_image_alt', true);
                if (!empty($existing_alt)) {
                    $progress->tick();
                    continue;
                }
            }

            $image_url = wp_get_attachment_url($image->ID);
            $alt_text = $this->openai->generate_alt_text($image_url, $image->ID, 'cli', false, $language_override);

            if ($alt_text) {
                WP_CLI::success(sprintf('Generated alt text for image %d: %s', $image->ID, $alt_text));
            }

            $progress->tick();
        }

        $progress->finish();

        update_option('wp_auto_alt_text_enable_brand_tonality', $original_setting);

        WP_CLI::success('Alt text generation completed!');
    }

    /**
     * Generates alt text translations for specified attachments
     *
     * ## OPTIONS
     *
     * [--ids=<attachment-ids>]
     * : Comma-separated list of attachment IDs to process
     *
     * [--all]
     * : Process all images in the media library
     */
    public function translate($args, $assoc_args) {
        $language_manager = new Auto_Alt_Text_Language_Manager();

        if (isset($assoc_args['all'])) {
            $attachment_ids = $this->get_all_attachment_ids();
        } else {
            $attachment_ids = isset($assoc_args['ids'])
                ? array_map('intval', explode(',', $assoc_args['ids']))
                : [];
        }

        if (empty($attachment_ids)) {
            WP_CLI::error('Provide attachment IDs with --ids or use --all.');
        }

        $results = $language_manager->bulk_generate_translations($attachment_ids);
        $summary = $this->summarize_translation_results($results);

        WP_CLI::success(
            sprintf(
                'Processed %d source images. %d translations created, %d skipped, %d failed.',
                count($results),
                $summary['translated'],
                $summary['skipped'],
                $summary['failed']
            )
        );
    }

    /**
     * Validates and sanitizes the limit parameter
     *
     * @param int $limit The limit to validate
     * @return int Validated limit value
     */
    private function validateLimit($limit) {
        $limit = (int) $limit;
        if ($limit !== -1 && $limit < 1) {
            WP_CLI::error('Limit must be -1 or a positive integer');
        }
        return $limit;
    }

    /**
     * Parses and validates requested language codes from CLI arguments.
     *
     * @param array $assoc_args CLI associative arguments.
     * @return string[]
     */
    private function parse_requested_languages($assoc_args) {
        if (!array_key_exists('language', $assoc_args)) {
            return [];
        }

        $raw_value = trim((string) $assoc_args['language']);

        if ($raw_value === '') {
            throw new InvalidArgumentException('Provide at least one language code with --language.');
        }

        $requested_languages = [];

        foreach (explode(',', $raw_value) as $raw_language) {
            $raw_language = trim((string) $raw_language);

            if ($raw_language === '') {
                throw new InvalidArgumentException('Language codes must not be empty in --language.');
            }

            $normalized_language = $this->normalize_language_code($raw_language);

            if (!$this->is_supported_language($normalized_language)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Unsupported language code "%s". Supported codes: %s.',
                        $raw_language,
                        implode(', ', $this->get_supported_language_codes())
                    )
                );
            }

            if (!in_array($normalized_language, $requested_languages, true)) {
                $requested_languages[] = $normalized_language;
            }
        }

        return $requested_languages;
    }

    /**
     * Resolves how CLI language selection should behave for the current environment.
     *
     * @param string[] $requested_languages Requested language codes.
     * @return array<string,mixed>
     */
    private function resolve_language_context($requested_languages) {
        $active_plugin = $this->language_manager->get_active_plugin_name();
        $language_override = null;

        if (empty($requested_languages)) {
            return [
                'active_plugin' => $active_plugin,
                'language_override' => null,
            ];
        }

        if (empty($active_plugin)) {
            if (count($requested_languages) > 1) {
                throw new InvalidArgumentException('Multiple language codes require WPML or Polylang. Use a single language code when no multilingual plugin is active.');
            }

            $language_override = reset($requested_languages);
        }

        return [
            'active_plugin' => $active_plugin,
            'language_override' => $language_override,
        ];
    }

    /**
     * Filters attachments to only the requested attachment languages.
     *
     * @param WP_Post[] $images Attachment posts.
     * @param string[]  $requested_languages Normalized language codes.
     * @return WP_Post[]
     */
    private function filter_images_by_languages($images, $requested_languages) {
        return array_values(array_filter($images, function($image) use ($requested_languages) {
            if (!isset($image->ID)) {
                return false;
            }

            $attachment_language = $this->normalize_language_code($this->language_manager->get_post_language($image->ID));

            return in_array($attachment_language, $requested_languages, true);
        }));
    }

    /**
     * Formats language codes for CLI logging.
     *
     * @param string[] $languages Normalized language codes.
     * @return string[]
     */
    private function format_languages_for_log($languages) {
        return array_map(function($language) {
            return sprintf('%s (%s)', $this->get_language_label($language), $language);
        }, $languages);
    }

    /**
     * Returns the label for a language code.
     *
     * @param string $language The normalized language code.
     * @return string
     */
    private function get_language_label($language) {
        if (defined('AUTO_ALT_TEXT_LANGUAGES') && isset(AUTO_ALT_TEXT_LANGUAGES[$language])) {
            return AUTO_ALT_TEXT_LANGUAGES[$language];
        }

        if ($language === 'da') {
            return 'Danska';
        }

        return strtoupper($language);
    }

    /**
     * Returns supported CLI language codes, including normalized aliases.
     *
     * @return string[]
     */
    private function get_supported_language_codes() {
        $supported_languages = defined('AUTO_ALT_TEXT_LANGUAGES') ? array_keys(AUTO_ALT_TEXT_LANGUAGES) : [];

        if (in_array('dk', $supported_languages, true) && !in_array('da', $supported_languages, true)) {
            $supported_languages[] = 'da';
        }

        sort($supported_languages);

        return $supported_languages;
    }

    /**
     * Determines whether a normalized language code is supported.
     *
     * @param string $language Normalized language code.
     * @return bool
     */
    private function is_supported_language($language) {
        return in_array($language, $this->get_supported_language_codes(), true);
    }

    /**
     * Normalizes locale-based language codes before validation.
     *
     * @param string|null $language Raw language value.
     * @return string
     */
    private function normalize_language_code($language) {
        if (empty($language) || !is_string($language)) {
            return 'en';
        }

        $normalized_language = strtolower(trim($language));
        $normalized_language = str_replace('_', '-', $normalized_language);

        if ($normalized_language === 'dk') {
            return 'da';
        }

        $primary_language = strtok($normalized_language, '-');

        return !empty($primary_language) ? $primary_language : 'en';
    }

    /**
     * Returns all image attachment IDs in the media library.
     *
     * @return int[]
     */
    private function get_all_attachment_ids() {
        return get_posts([
            'post_type' => self::POST_TYPE,
            'post_mime_type' => self::POST_MIME_TYPE,
            'post_status' => self::POST_STATUS,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
    }

    /**
     * Summarizes structured translation results for CLI reporting.
     *
     * @param array $results Translation results keyed by source attachment ID.
     * @return array<string,int>
     */
    private function summarize_translation_results($results) {
        $summary = [
            'translated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($results as $result) {
            if (!empty($result['status']) && $result['status'] === 'skipped') {
                $summary['skipped']++;
                continue;
            }

            if (empty($result['translations']) || !is_array($result['translations'])) {
                continue;
            }

            foreach ($result['translations'] as $translation) {
                if (!isset($translation['status'])) {
                    continue;
                }

                switch ($translation['status']) {
                    case 'translated':
                        $summary['translated']++;
                        break;
                    case 'failed':
                        $summary['failed']++;
                        break;
                }
            }
        }

        return $summary;
    }

    /**
     * Shows statistics about generated alt texts.
     *
        * This method retrieves various statistics related to the generated alt texts, such as the total number of generated alt texts, the number of successfully applied alt texts, the number of user-edited alt texts, the average number of tokens used per image, the total number of tokens used, and the estimated total cost based on GPT-5.2 pricing assumptions.
     *
     * The statistics are formatted and displayed in a table format using the WP-CLI utility.
     *
     * ### Usage
     * `wp auto-alt-text stats`
     */
    public function stats($args, $assoc_args) {
      global $wpdb;
      $table_name = $wpdb->prefix . 'auto_alt_text_stats';

      $total_generated = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
      $total_applied = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_applied = 1");
      $total_edited = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_edited = 1");
      $avg_tokens = $wpdb->get_var("SELECT AVG(tokens_used) FROM $table_name");
      $total_tokens = $wpdb->get_var("SELECT SUM(tokens_used) FROM $table_name");

            // GPT-5.2 pricing assumption calculations
      $input_cost_per_million = 2.50;
      $output_cost_per_million = 10.00;

      // Assuming 70% input tokens, 30% output tokens based on typical usage
      $input_tokens = $total_tokens * 0.7;
      $output_tokens = $total_tokens * 0.3;

      $total_input_cost = ($input_tokens / 1000000) * $input_cost_per_million;
      $total_output_cost = ($output_tokens / 1000000) * $output_cost_per_million;
      $total_cost = $total_input_cost + $total_output_cost;

      $stats = [];
      $stats[] = [
          'metric' => 'Total Generated',
          'value' => $total_generated
      ];
      $stats[] = [
          'metric' => 'Successfully Applied',
          'value' => $total_applied
      ];
      $stats[] = [
          'metric' => 'User Edited',
          'value' => $total_edited
      ];
      $stats[] = [
        'metric' => 'Average Tokens/Image',
        'value' => round($avg_tokens, 2)
      ];
      $stats[] = [
        'metric' => 'Total Tokens Used',
        'value' => number_format($total_tokens)
      ];
      $stats[] = [
          'metric' => 'Total Cost (USD)',
          'value' => '$' . number_format($total_cost, 4)
      ];

      $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
      WP_CLI\Utils\format_items($format, $stats, ['metric', 'value']);
    }
}
