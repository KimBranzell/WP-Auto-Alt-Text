<?php

require_once dirname(__FILE__) . '/interfaces/interface-cli-command.php';

class Auto_Alt_Text_CLI implements Auto_Alt_Text_CLI_Command {
    private const QUERY_POSTS_PER_PAGE = -1;
    private const POST_TYPE = 'attachment';
    private const POST_MIME_TYPE = 'image';
    private const POST_STATUS = 'any';
    private const ALT_TEXT_META_KEY = '_wp_attachment_image_alt';
    private const RESUME_OPTION_PREFIX = 'wp_auto_alt_text_cli_resume_';

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
    * [--offset=<number>]
    * : Skip the first N matching images before processing. Uses newest-first attachment ID ordering.
    *
    * [--resume]
    * : Continue from the previous batch position for the same CLI filter set.
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
    *     `wp auto-alt-text generate --limit=100 --offset=100`
    *
    *     `wp auto-alt-text generate --limit=100 --resume`
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
        $offset = $this->validate_offset($assoc_args['offset'] ?? 0);
        $resume = isset($assoc_args['resume']);
        $skip_existing = isset($assoc_args['skip-existing']);
        $brand_tonality = isset($assoc_args['brand-tonality']);

        $this->validate_generate_batch_arguments($assoc_args);

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

        try {
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

            if ($offset > 0) {
                WP_CLI::log(sprintf('Applying offset: skipping the first %d matching images.', $offset));
            }

            $resume_context = $this->build_resume_context($requested_languages, $skip_existing, $brand_tonality, $active_plugin);
            $resume_after_id = 0;

            if ($resume) {
                $resume_state = $this->get_resume_state($resume_context);

                if (!empty($resume_state['last_processed_id'])) {
                    $resume_after_id = (int) $resume_state['last_processed_id'];
                    WP_CLI::log(sprintf('Resuming after attachment ID %d.', $resume_after_id));
                } else {
                    WP_CLI::log('No saved batch position found. Starting from the newest matching image.');
                }
            }

            $matching_attachment_ids = $this->get_matching_attachment_ids(
                $requested_languages,
                $skip_existing,
                $active_plugin,
                $resume_after_id
            );
            $attachment_ids = $this->slice_attachment_ids($matching_attachment_ids, $offset, $limit);

            $matching_count = count($matching_attachment_ids);
            $count = count($attachment_ids);

            if ($count === 0) {
                if ($resume) {
                    $this->clear_resume_state($resume_context);
                }

                if (!empty($requested_languages) && !empty($active_plugin)) {
                    WP_CLI::success(
                        sprintf(
                            'No images matched the current filters for %s attachments: %s.',
                            $active_plugin,
                            implode(', ', $this->format_languages_for_log($requested_languages))
                        )
                    );
                    return;
                }

                WP_CLI::success('No images found to process.');
                return;
            }

            if ($matching_count === $count) {
                WP_CLI::log(sprintf('Processing %d images...', $count));
            } else {
                WP_CLI::log(sprintf('Processing %d of %d matching images...', $count, $matching_count));
            }

            $progress = \WP_CLI\Utils\make_progress_bar('Generating alt text', $count);

            foreach ($attachment_ids as $attachment_id) {
                $image_url = wp_get_attachment_url($attachment_id);
                $alt_text = $this->openai->generate_alt_text($image_url, $attachment_id, 'cli', false, $language_override);

                if ($alt_text) {
                    WP_CLI::success(sprintf('Generated alt text for image %d: %s', $attachment_id, $alt_text));
                } else {
                    $this->warn_generation_failure($attachment_id);
                }

                if ($resume) {
                    $this->persist_resume_state($resume_context, $attachment_id);
                }

                $progress->tick();
            }

            $progress->finish();

            if ($resume) {
                if ($matching_count > $count) {
                    WP_CLI::success('Alt text generation completed! Re-run the same command with --resume to continue.');
                    return;
                }

                $this->clear_resume_state($resume_context);
                WP_CLI::success('Alt text generation completed! No matching images remain for this resume context.');
                return;
            }

            WP_CLI::success('Alt text generation completed!');
        } finally {
            update_option('wp_auto_alt_text_enable_brand_tonality', $original_setting);
        }
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
     * Validates and sanitizes the offset parameter.
     *
     * @param mixed $offset The offset to validate.
     * @return int Validated offset value.
     */
    private function validate_offset($offset) {
        if (filter_var($offset, FILTER_VALIDATE_INT) === false) {
            WP_CLI::error('Offset must be a non-negative integer');
        }

        $offset = (int) $offset;

        if ($offset < 0) {
            WP_CLI::error('Offset must be a non-negative integer');
        }

        return $offset;
    }

    /**
     * Validates generate command batching arguments.
     *
     * @param array $assoc_args CLI associative arguments.
     * @return void
     */
    private function validate_generate_batch_arguments($assoc_args) {
        if (isset($assoc_args['resume']) && array_key_exists('offset', $assoc_args)) {
            WP_CLI::error('--resume cannot be used together with --offset.');
        }
    }

    /**
     * Builds the resume context used for persisted CLI batch state.
     *
     * @param string[]    $requested_languages Requested languages.
     * @param bool        $skip_existing Whether skip-existing is enabled.
     * @param bool        $brand_tonality Whether brand tonality is enabled.
     * @param string|null $active_plugin Active multilingual plugin name.
     * @return array<string,mixed>
     */
    private function build_resume_context($requested_languages, $skip_existing, $brand_tonality, $active_plugin) {
        $languages = $requested_languages;
        sort($languages);

        return [
            'active_plugin' => !empty($active_plugin) ? $active_plugin : 'none',
            'brand_tonality' => (bool) $brand_tonality,
            'languages' => array_values($languages),
            'skip_existing' => (bool) $skip_existing,
        ];
    }

    /**
     * Returns the stored resume state for the provided context.
     *
     * @param array<string,mixed> $resume_context Resume context.
     * @return array<string,mixed>
     */
    private function get_resume_state($resume_context) {
        $resume_state = get_option($this->get_resume_state_option_name($resume_context), []);

        return is_array($resume_state) ? $resume_state : [];
    }

    /**
     * Persists CLI resume state after an attachment has been handled.
     *
     * @param array<string,mixed> $resume_context Resume context.
     * @param int                 $attachment_id Handled attachment ID.
     * @return void
     */
    private function persist_resume_state($resume_context, $attachment_id) {
        update_option(
            $this->get_resume_state_option_name($resume_context),
            [
                'last_processed_id' => (int) $attachment_id,
                'updated_at' => current_time('mysql'),
            ],
            false
        );
    }

    /**
     * Clears CLI resume state for the provided context.
     *
     * @param array<string,mixed> $resume_context Resume context.
     * @return void
     */
    private function clear_resume_state($resume_context) {
        delete_option($this->get_resume_state_option_name($resume_context));
    }

    /**
     * Returns the option name used to persist CLI resume state.
     *
     * @param array<string,mixed> $resume_context Resume context.
     * @return string
     */
    private function get_resume_state_option_name($resume_context) {
        return self::RESUME_OPTION_PREFIX . md5(wp_json_encode($resume_context));
    }

    /**
     * Returns ordered attachment IDs after applying CLI filters.
     *
     * @param string[]    $requested_languages Requested languages.
     * @param bool        $skip_existing Whether skip-existing is enabled.
     * @param string|null $active_plugin Active multilingual plugin name.
     * @param int         $resume_after_id Resume boundary attachment ID.
     * @return int[]
     */
    private function get_matching_attachment_ids($requested_languages, $skip_existing, $active_plugin, $resume_after_id = 0) {
        $attachment_ids = $this->get_ordered_attachment_ids();

        if ($resume_after_id > 0) {
            $attachment_ids = array_values(array_filter($attachment_ids, function($attachment_id) use ($resume_after_id) {
                return (int) $attachment_id < $resume_after_id;
            }));
        }

        if ($skip_existing) {
            $attachment_ids = array_values(array_filter($attachment_ids, function($attachment_id) {
                return !$this->has_existing_alt_text($attachment_id);
            }));
        }

        if (!empty($requested_languages) && !empty($active_plugin)) {
            $attachment_ids = $this->filter_attachment_ids_by_languages($attachment_ids, $requested_languages);
        }

        return $attachment_ids;
    }

    /**
     * Returns all image attachment IDs ordered from newest to oldest.
     *
     * @return int[]
     */
    private function get_ordered_attachment_ids() {
        $attachment_ids = get_posts([
            'fields' => 'ids',
            'no_found_rows' => true,
            'order' => 'DESC',
            'orderby' => 'ID',
            'post_mime_type' => self::POST_MIME_TYPE,
            'post_status' => self::POST_STATUS,
            'post_type' => self::POST_TYPE,
            'posts_per_page' => self::QUERY_POSTS_PER_PAGE,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        return array_values(array_unique(array_map('intval', $attachment_ids)));
    }

    /**
     * Applies offset and limit to the ordered attachment IDs.
     *
     * @param int[] $attachment_ids Ordered attachment IDs.
     * @param int   $offset Number of matching attachments to skip.
     * @param int   $limit Maximum attachments to return.
     * @return int[]
     */
    private function slice_attachment_ids($attachment_ids, $offset, $limit) {
        if ($limit === self::QUERY_POSTS_PER_PAGE) {
            return array_values(array_slice($attachment_ids, $offset));
        }

        return array_values(array_slice($attachment_ids, $offset, $limit));
    }

    /**
     * Determines whether the attachment already has usable alt text.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private function has_existing_alt_text($attachment_id) {
        $existing_alt = get_post_meta($attachment_id, self::ALT_TEXT_META_KEY, true);

        if (is_string($existing_alt)) {
            return trim($existing_alt) !== '';
        }

        return !empty($existing_alt);
    }

    /**
     * Emits a warning when alt text generation fails for an attachment.
     *
     * @param int $attachment_id Attachment ID.
     * @return void
     */
    private function warn_generation_failure($attachment_id) {
        $last_error = method_exists($this->openai, 'get_last_error') ? $this->openai->get_last_error() : '';
        $message = !empty($last_error)
            ? $last_error
            : 'Unable to generate alt text right now. Please try again.';

        if (method_exists('WP_CLI', 'warning')) {
            call_user_func(['WP_CLI', 'warning'], sprintf('Failed to generate alt text for image %d: %s', $attachment_id, $message));
            return;
        }

        WP_CLI::log(sprintf('Warning: Failed to generate alt text for image %d: %s', $attachment_id, $message));
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
    private function filter_attachment_ids_by_languages($attachment_ids, $requested_languages) {
        return array_values(array_filter($attachment_ids, function($attachment_id) use ($requested_languages) {
            $attachment_language = $this->normalize_language_code($this->language_manager->get_post_language($attachment_id));

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
