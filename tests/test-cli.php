<?php

namespace {

    if (!class_exists('WP_CLI')) {
        class WP_CLI {
            public static $logs = [];
            public static $successes = [];
            public static $warnings = [];

            public static $lines = [];

            public static function log($message) {
                self::$logs[] = $message;
            }

            public static function line($message = '') {
                self::$lines[] = $message;
            }

            public static function success($message) {
                self::$successes[] = $message;
            }

            public static function warning($message) {
                self::$warnings[] = $message;
            }

            public static function error($message) {
                throw new \InvalidArgumentException($message);
            }
        }
    }

    class Auto_Alt_Text_Test_Progress_Bar {
        public function tick() {}

        public function finish() {}
    }

    class Auto_Alt_Text_CLI_Test_OpenAI extends Auto_Alt_Text_OpenAI {
        public $calls = [];
        /** @var callable|null Runs before each generation, e.g. to simulate an editor working during the run. */
        public $on_generate = null;

        public function __construct() {}

        /** @var array[] Every set_run_overrides() call, in order (the CLI resets to [] when a run ends). */
        public $run_overrides_seen = [];

        public function set_run_overrides(array $overrides) {
            $this->run_overrides_seen[] = $overrides;
            parent::set_run_overrides($overrides);
        }

        public function generate_alt_text($image_source, $attachment_id, $generation_type = 'manual', $preview_mode = false, $language_override = null) {
            if ($this->on_generate) {
                call_user_func($this->on_generate, $attachment_id);
            }
            $this->calls[] = [
                'attachment_id' => $attachment_id,
                'generation_type' => $generation_type,
                'preview_mode' => $preview_mode,
                'language_override' => $language_override,
            ];

            $alt_text = sprintf('Generated alt text for %d', $attachment_id);
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);

            return $alt_text;
        }
    }

    class Auto_Alt_Text_CLI_Test_Statistics extends Auto_Alt_Text_Statistics {
        public function __construct() {}
    }

    class Auto_Alt_Text_CLI_Test_Language_Manager extends Auto_Alt_Text_Language_Manager {
        private $active_plugin_name;
        private $attachment_languages;

        public function __construct($active_plugin_name = null, $attachment_languages = []) {
            $this->active_plugin_name = $active_plugin_name;
            $this->attachment_languages = $attachment_languages;
        }

        public function get_active_plugin_name() {
            return $this->active_plugin_name;
        }

        public function get_post_language($post_id) {
            return $this->attachment_languages[$post_id] ?? 'en';
        }
    }
}

namespace WP_CLI\Utils {

    if (!function_exists(__NAMESPACE__ . '\\make_progress_bar')) {
        function make_progress_bar($message, $count) {
            return new \Auto_Alt_Text_Test_Progress_Bar();
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\format_items')) {
        function format_items($format, $items, $fields) {
            return null;
        }
    }
}

namespace {

    use PHPUnit\Framework\Assert;

    class Auto_Alt_Text_CLI_Test extends WP_UnitTestCase {
        private $openai;
        private $statistics;

        public function setUp(): void {
            parent::setUp();
            $this->openai = new Auto_Alt_Text_CLI_Test_OpenAI();
            $this->statistics = new Auto_Alt_Text_CLI_Test_Statistics();

            $this->delete_resume_state_options();
            WP_CLI::$logs = [];
            WP_CLI::$successes = [];
            WP_CLI::$warnings = [];
            WP_CLI::$lines = [];
        }

        public function tearDown(): void {
            $this->delete_resume_state_options();
            parent::tearDown();
        }

        public function test_parse_requested_languages_normalizes_and_deduplicates_values() {
            $cli = $this->create_cli();

            $languages = $this->invoke_private_method($cli, 'parse_requested_languages', [[
                'language' => 'sv_SE, da, dk, sv',
            ]]);

            Assert::assertSame(['sv', 'da'], $languages);
        }

        public function test_generate_uses_single_language_override_without_multilingual_plugin() {
            $attachment_ids = $this->create_attachments(2);
            $cli = $this->create_cli(new Auto_Alt_Text_CLI_Test_Language_Manager(null, array_fill_keys($attachment_ids, 'en')));

            $cli->generate([], [
                'language' => 'sv',
                'limit' => 2,
            ]);

            Assert::assertCount(2, $this->openai->calls);
            Assert::assertSame('sv', $this->openai->calls[0]['language_override']);
            Assert::assertSame('sv', $this->openai->calls[1]['language_override']);
        }

        public function test_generate_processes_newest_attachments_first() {
            $attachment_ids = $this->create_attachments(4);
            $cli = $this->create_cli();

            $cli->generate([], [
                'limit' => 2,
            ]);

            Assert::assertSame([
                $attachment_ids[3],
                $attachment_ids[2],
            ], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_skip_existing_advances_to_next_batch_on_second_run() {
            $attachment_ids = $this->create_attachments(5);
            $cli = $this->create_cli();

            $cli->generate([], [
                'limit' => 2,
                'skip-existing' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[4],
                $attachment_ids[3],
            ], array_column($this->openai->calls, 'attachment_id'));

            $this->openai->calls = [];

            $cli->generate([], [
                'limit' => 2,
                'skip-existing' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[2],
                $attachment_ids[1],
            ], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_applies_skip_existing_before_limit() {
            $attachment_ids = $this->create_attachments(5);
            $cli = $this->create_cli();

            update_post_meta($attachment_ids[4], '_wp_attachment_image_alt', 'Existing latest alt text');
            update_post_meta($attachment_ids[3], '_wp_attachment_image_alt', 'Existing second latest alt text');

            $cli->generate([], [
                'limit' => 2,
                'skip-existing' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[2],
                $attachment_ids[1],
            ], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_supports_offset_paging() {
            $attachment_ids = $this->create_attachments(5);
            $cli = $this->create_cli();

            $cli->generate([], [
                'limit' => 2,
                'offset' => 2,
            ]);

            Assert::assertSame([
                $attachment_ids[2],
                $attachment_ids[1],
            ], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_resume_continues_batches_and_clears_saved_state() {
            $attachment_ids = $this->create_attachments(5);
            $cli = $this->create_cli();

            $cli->generate([], [
                'limit' => 2,
                'resume' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[4],
                $attachment_ids[3],
            ], array_column($this->openai->calls, 'attachment_id'));

            $this->openai->calls = [];

            $cli->generate([], [
                'limit' => 2,
                'resume' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[2],
                $attachment_ids[1],
            ], array_column($this->openai->calls, 'attachment_id'));

            $this->openai->calls = [];

            $cli->generate([], [
                'limit' => 2,
                'resume' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[0],
            ], array_column($this->openai->calls, 'attachment_id'));

            $resume_context = $this->invoke_private_method($cli, 'build_resume_context', [[], 'missing', 'visible', Auto_Alt_Text_Models::current_id(), null]);
            $resume_option_name = $this->invoke_private_method($cli, 'get_resume_state_option_name', [$resume_context]);

            Assert::assertFalse(get_option($resume_option_name, false));
        }

        public function test_generate_filters_requested_languages_when_multilingual_plugin_is_active() {
            $attachment_ids = $this->create_attachments(3);
            $language_manager = new Auto_Alt_Text_CLI_Test_Language_Manager('wpml', [
                $attachment_ids[0] => 'en',
                $attachment_ids[1] => 'sv',
                $attachment_ids[2] => 'fr',
            ]);
            $cli = $this->create_cli($language_manager);

            $cli->generate([], [
                'language' => 'sv,fr',
            ]);

            $processed_ids = array_column($this->openai->calls, 'attachment_id');

            sort($processed_ids);
            sort($attachment_ids);

            Assert::assertSame([$attachment_ids[1], $attachment_ids[2]], $processed_ids);
            Assert::assertNull($this->openai->calls[0]['language_override']);
        }

        public function test_generate_applies_offset_after_multilingual_language_filtering() {
            $attachment_ids = $this->create_attachments(6);
            $language_manager = new Auto_Alt_Text_CLI_Test_Language_Manager('wpml', [
                $attachment_ids[0] => 'en',
                $attachment_ids[1] => 'sv',
                $attachment_ids[2] => 'en',
                $attachment_ids[3] => 'fr',
                $attachment_ids[4] => 'sv',
                $attachment_ids[5] => 'en',
            ]);
            $cli = $this->create_cli($language_manager);

            $cli->generate([], [
                'language' => 'sv,fr',
                'limit' => 2,
                'offset' => 1,
            ]);

            Assert::assertSame([
                $attachment_ids[3],
                $attachment_ids[1],
            ], array_column($this->openai->calls, 'attachment_id'));
            Assert::assertNull($this->openai->calls[0]['language_override']);
        }

        public function test_generate_rejects_multiple_languages_without_multilingual_plugin() {
            $cli = $this->create_cli();

            try {
                $cli->generate([], [
                    'language' => 'sv,fr',
                ]);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame(
                    'Multiple language codes require WPML or Polylang. Use a single language code when no multilingual plugin is active.',
                    $exception->getMessage()
                );
            }
        }

        public function test_generate_rejects_resume_with_offset() {
            $cli = $this->create_cli();

            try {
                $cli->generate([], [
                    'resume' => true,
                    'offset' => 0,
                ]);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame('--resume cannot be used together with --offset.', $exception->getMessage());
            }
        }

        public function test_cache_key_varies_by_what_shaped_the_answer() {
            $swedish = Auto_Alt_Text_Cache_Manager::get_cache_key(7, ['instructions' => 'Write in Svenska', 'model' => 'gpt-6-luna']);
            $english = Auto_Alt_Text_Cache_Manager::get_cache_key(7, ['instructions' => 'Write in Engelska', 'model' => 'gpt-6-luna']);

            Assert::assertNotSame($swedish, $english);
            Assert::assertSame($swedish, Auto_Alt_Text_Cache_Manager::get_cache_key(7, ['model' => 'gpt-6-luna', 'instructions' => 'Write in Svenska']));
            Assert::assertStringStartsWith('aat_img_7_', $swedish);
        }

        public function test_queue_command_outputs_counts() {
            Auto_Alt_Text_Activator::activate();
            global $wpdb;
            $wpdb->query('TRUNCATE TABLE ' . Auto_Alt_Text_Queue::get_table_name());
            (new Auto_Alt_Text_Queue())->enqueue(self::factory()->attachment->create(), 'upload');

            $cli = $this->create_cli();
            $cli->queue([], []);

            $output = implode("\n", WP_CLI::$lines);

            Assert::assertStringContainsString('Pending:     1', $output);
            Assert::assertStringContainsString('Queue status: running', $output);
        }

        public function test_queue_command_retry_failed_requeues() {
            Auto_Alt_Text_Activator::activate();
            global $wpdb;
            $wpdb->query('TRUNCATE TABLE ' . Auto_Alt_Text_Queue::get_table_name());
            $queue = new Auto_Alt_Text_Queue();
            $id = self::factory()->attachment->create();
            $queue->enqueue($id, 'upload');
            $wpdb->update(Auto_Alt_Text_Queue::get_table_name(),
                ['status' => Auto_Alt_Text_Queue::STATUS_FAILED], ['attachment_id' => $id]);

            $cli = $this->create_cli();
            $cli->queue([], ['retry-failed' => true]);

            Assert::assertSame(1, $queue->get_counts()['pending']);
            Assert::assertSame(0, $queue->get_counts()['failed']);
            Assert::assertNotEmpty(WP_CLI::$successes);
            Assert::assertStringContainsString('1 failed job(s) re-queued.', WP_CLI::$successes[0]);
        }

        public function test_queue_command_shows_paused_status_during_quota_incident() {
            Auto_Alt_Text_Activator::activate();
            global $wpdb;
            $wpdb->query('TRUNCATE TABLE ' . Auto_Alt_Text_Queue::get_table_name());
            (new Auto_Alt_Text_Queue())->enqueue(self::factory()->attachment->create(), 'upload');

            // Prevent email from being sent during the test
            add_filter('pre_wp_mail', '__return_true');

            // Record a quota incident
            Auto_Alt_Text_Quota_Monitor::record_failure('You exceeded your current quota');

            $cli = $this->create_cli();
            $cli->queue([], []);

            $output = implode("\n", WP_CLI::$lines);

            // Get the stored incident to verify the timestamp format
            $incident = Auto_Alt_Text_Quota_Monitor::get_incident();
            Assert::assertNotNull($incident);

            $expected_timestamp = gmdate('Y-m-d H:i', (int) $incident['detected_at']);

            // Verify the PAUSED status message format
            Assert::assertStringContainsString('Queue status: PAUSED — quota incident since ' . $expected_timestamp, $output);
            Assert::assertStringContainsString('(last error: "You exceeded your current quota")', $output);

            // Cleanup
            delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
            remove_filter('pre_wp_mail', '__return_true');
        }

        public function test_context_command_prints_the_explanation() {
            $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Om oss']);
            $image = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg', 'post_parent' => $page, 'file' => 'cli-context.jpg']);

            $this->create_cli()->context([(string) $image], []);

            Assert::assertSame('Sida: Om oss (uppladdad till)', WP_CLI::$lines[0]);
            Assert::assertContains('reason: found', WP_CLI::$lines);
            Assert::assertContains('source: parent', WP_CLI::$lines);
            Assert::assertContains('product_name: -', WP_CLI::$lines);
        }

        public function test_context_command_prints_json() {
            $image = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg', 'file' => 'cli-context.jpg']);

            $this->create_cli()->context([(string) $image], ['format' => 'json']);

            $data = json_decode(WP_CLI::$lines[0], true);
            Assert::assertSame('none', $data['reason']);
            Assert::assertSame('Ingen produkt eller sida hittades', $data['line']);
        }

        public function test_context_command_rejects_non_images() {
            try {
                $this->create_cli()->context(['999999'], []);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame('Give the ID of an image attachment.', $exception->getMessage());
            }
        }

        public function test_generate_passes_naming_and_model_as_run_overrides() {
            $this->create_attachments(1);
            $writes = 0;
            add_filter('pre_update_option_auto_alt_text_naming', function ($value) use (&$writes) {
                $writes++;
                return $value;
            });

            $this->create_cli()->generate([], ['naming' => 'product', 'model' => 'gpt-6-luna']);

            Assert::assertSame(['naming' => 'product', 'model' => 'gpt-6-luna'], $this->openai->run_overrides_seen[0]);
            Assert::assertSame([], end($this->openai->run_overrides_seen), 'Overrides end with the run');
            Assert::assertSame(0, $writes, 'A run never writes the naming setting');
            Assert::assertFalse(get_option('wp_auto_alt_text_enable_brand_tonality'));
            Assert::assertContains('Naming: product', WP_CLI::$logs);
            Assert::assertContains('Model: gpt-6-luna', WP_CLI::$logs);
        }

        public function test_generate_without_flags_passes_no_overrides() {
            $this->create_attachments(1);

            $this->create_cli()->generate([], []);

            Assert::assertSame([], $this->openai->run_overrides_seen[0]);
        }

        public function test_brand_tonality_is_an_alias_for_product_brand() {
            $this->create_attachments(1);

            $this->create_cli()->generate([], ['brand-tonality' => true]);

            Assert::assertSame(['naming' => 'product_brand'], $this->openai->run_overrides_seen[0]);
        }

        public function test_generate_rejects_naming_together_with_brand_tonality() {
            $this->assert_cli_error(['naming' => 'product', 'brand-tonality' => true], 'Use either --naming or --brand-tonality, not both.');
        }

        public function test_generate_rejects_naming_without_a_value() {
            $this->assert_cli_error(['naming' => true], '--naming needs a value: visible, product or product_brand.');
        }

        public function test_generate_rejects_an_unknown_naming_level() {
            $this->assert_cli_error(['naming' => 'seo'], 'Invalid --naming value "seo". Use visible, product or product_brand.');
        }

        public function test_generate_rejects_an_unknown_model() {
            try {
                $this->create_cli()->generate([], ['model' => 'gpt-retired']);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertStringStartsWith('Unknown --model "gpt-retired". Choose one of: ', $exception->getMessage());
                Assert::assertStringContainsString('gpt-6-luna', $exception->getMessage());
            }
        }

        private function assert_cli_error(array $assoc_args, $message) {
            try {
                $this->create_cli()->generate([], $assoc_args);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame($message, $exception->getMessage());
            }
        }

        private function create_cli($language_manager = null) {
            return new Auto_Alt_Text_CLI(
                $this->openai,
                $this->statistics,
                $language_manager ?? new Auto_Alt_Text_CLI_Test_Language_Manager()
            );
        }

        private function create_mixed_attachments() {
            [$missing, $ai, $human] = $this->create_attachments(3);
            update_post_meta($ai, '_wp_attachment_image_alt', 'AI text');
            Auto_Alt_Text_Provenance::record($ai, 'upload');
            update_post_meta($human, '_wp_attachment_image_alt', 'Editor text');
            return [$missing, $ai, $human];
        }

        public function test_generate_default_policy_only_fills_missing_alt_text() {
            delete_option(Auto_Alt_Text_Provenance::POLICY_OPTION);
            [$missing] = $this->create_mixed_attachments();

            $this->create_cli()->generate([], []);

            Assert::assertSame([$missing], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_overwrite_ai_also_rewrites_untouched_ai_alt_text() {
            [$missing, $ai] = $this->create_mixed_attachments();

            $this->create_cli()->generate([], ['overwrite' => 'ai']);

            Assert::assertSame([$ai, $missing], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_overwrite_all_rewrites_everything() {
            [$missing, $ai, $human] = $this->create_mixed_attachments();

            $this->create_cli()->generate([], ['overwrite' => 'all']);

            Assert::assertSame([$human, $ai, $missing], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_skip_existing_is_an_alias_for_missing() {
            update_option(Auto_Alt_Text_Provenance::POLICY_OPTION, 'all');
            [$missing] = $this->create_mixed_attachments();

            $this->create_cli()->generate([], ['skip-existing' => true]);
            delete_option(Auto_Alt_Text_Provenance::POLICY_OPTION);

            Assert::assertSame([$missing], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_rejects_unknown_overwrite_value() {
            try {
                $this->create_cli()->generate([], ['overwrite' => 'everything']);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertStringContainsString('Invalid --overwrite value', $exception->getMessage());
            }
        }

        public function test_generate_skips_an_image_edited_by_a_person_during_the_run() {
            [$oldest, $edited_later, $first] = $this->create_attachments(3);
            foreach ([$oldest, $edited_later, $first] as $id) {
                update_post_meta($id, '_wp_attachment_image_alt', 'Old AI text');
                Auto_Alt_Text_Provenance::record($id, 'upload');
            }
            // An editor fixes the next image while the first one is being generated.
            $this->openai->on_generate = function ($attachment_id) use ($first, $edited_later) {
                if ($attachment_id === $first) {
                    update_post_meta($edited_later, '_wp_attachment_image_alt', 'Editor fixed it');
                }
            };
            $cli = $this->create_cli();

            $cli->generate([], ['overwrite' => 'ai', 'limit' => 2, 'resume' => true]);

            Assert::assertSame([$first], array_column($this->openai->calls, 'attachment_id'));
            Assert::assertSame('Editor fixed it', get_post_meta($edited_later, '_wp_attachment_image_alt', true));
            Assert::assertContains(
                sprintf('Skipped image %d: its alt text changed during the run and is kept by the overwrite policy.', $edited_later),
                WP_CLI::$logs
            );
            $resume_context = $this->invoke_private_method($cli, 'build_resume_context', [[], 'ai', 'visible', Auto_Alt_Text_Models::current_id(), null]);
            $resume_option_name = $this->invoke_private_method($cli, 'get_resume_state_option_name', [$resume_context]);
            Assert::assertSame($edited_later, get_option($resume_option_name)['last_processed_id'], 'A skipped image still advances --resume');
        }

        public function test_generate_overwrite_all_processes_an_image_edited_during_the_run() {
            [$edited_later, $first] = $this->create_attachments(2);
            $this->openai->on_generate = function ($attachment_id) use ($first, $edited_later) {
                if ($attachment_id === $first) {
                    update_post_meta($edited_later, '_wp_attachment_image_alt', 'Editor text');
                }
            };

            $this->create_cli()->generate([], ['overwrite' => 'all']);

            Assert::assertSame([$first, $edited_later], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_rejects_overwrite_without_a_value() {
            try {
                // WP-CLI passes a bare --overwrite flag as boolean true.
                $this->create_cli()->generate([], ['overwrite' => true]);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame('--overwrite needs a value: missing, ai or all.', $exception->getMessage());
            }
        }

        public function test_generate_rejects_overwrite_together_with_skip_existing() {
            try {
                $this->create_cli()->generate([], ['overwrite' => 'all', 'skip-existing' => true]);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame('Use either --overwrite or --skip-existing, not both.', $exception->getMessage());
            }
            Assert::assertSame([], $this->openai->calls);
        }

        private function create_attachments($count) {
            $attachment_ids = [];

            for ($index = 0; $index < $count; $index++) {
                $attachment_ids[] = wp_insert_attachment([
                    'post_title' => 'CLI Test Image ' . $index,
                    'post_type' => 'attachment',
                    'post_mime_type' => 'image/jpeg',
                    'post_status' => 'inherit',
                ]);
            }

            return $attachment_ids;
        }

        private function invoke_private_method($object, $method_name, $arguments = []) {
            $reflection = new ReflectionMethod($object, $method_name);
            $reflection->setAccessible(true);

            return $reflection->invokeArgs($object, $arguments);
        }

        private function delete_resume_state_options() {
            global $wpdb;

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    'wp_auto_alt_text_cli_resume_%'
                )
            );
        }
    }
}
