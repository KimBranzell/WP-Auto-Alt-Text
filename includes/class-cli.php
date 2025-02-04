<?php

require_once dirname(__FILE__) . '/interfaces/interface-cli-command.php';

class Auto_Alt_Text_CLI implements Auto_Alt_Text_CLI_Command {
    private const QUERY_POSTS_PER_PAGE = -1;
    private const POST_TYPE = 'attachment';
    private const POST_MIME_TYPE = 'image';
    private const POST_STATUS = 'any';

    private $openai;
    private $statistics;

    public function __construct(Auto_Alt_Text_OpenAI $openai = null, Auto_Alt_Text_Statistics $statistics = null) {
        try {
            $this->openai = $openai ?? new Auto_Alt_Text_OpenAI();
            $this->statistics = $statistics ?? new Auto_Alt_Text_Statistics();
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
     * ### Examples
     *
     *     `wp auto-alt-text generate`
     *
     *     `wp auto-alt-text generate --limit=50`
     *
     *     `wp auto-alt-text generate --skip-existing`
     */
    public function generate($args, $assoc_args) {
        $limit = $this->validateLimit($assoc_args['limit'] ?? self::QUERY_POSTS_PER_PAGE);
        $skip_existing = isset($assoc_args['skip-existing']);

        $query = [
            'post_type' => self::POST_TYPE,
            'post_mime_type' => self::POST_MIME_TYPE,
            'posts_per_page' => $limit,
            'post_status' => self::POST_STATUS
        ];

        $images = get_posts($query);
        $count = count($images);

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
            $alt_text = $this->openai->generate_alt_text($image_url, $image->ID, 'cli');

            if ($alt_text) {
                WP_CLI::success(sprintf('Generated alt text for image %d: %s', $image->ID, $alt_text));
            }

            $progress->tick();
        }

        $progress->finish();
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
            $attachment_ids = explode(',', $assoc_args['ids']);
        }

        $results = $language_manager->bulk_generate_translations($attachment_ids);

        WP_CLI::success(sprintf('Processed %d images with translations', count($results)));
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
     * Shows statistics about generated alt texts.
     *
     * This method retrieves various statistics related to the generated alt texts, such as the total number of generated alt texts, the number of successfully applied alt texts, the number of user-edited alt texts, the average number of tokens used per image, the total number of tokens used, and the estimated total cost based on GPT-4 Vision pricing.
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

      // GPT-4 Vision pricing calculations
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
