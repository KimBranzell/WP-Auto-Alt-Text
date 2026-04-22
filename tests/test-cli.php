<?php

namespace {

    if (!class_exists('WP_CLI')) {
        class WP_CLI {
            public static $logs = [];
            public static $successes = [];
            public static $warnings = [];

            public static function log($message) {
                self::$logs[] = $message;
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

        public function __construct() {}

        public function generate_alt_text($image_source, $attachment_id, $generation_type = 'manual', $preview_mode = false, $language_override = null) {
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

            $resume_context = $this->invoke_private_method($cli, 'build_resume_context', [[], false, false, null]);
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

        public function test_cache_key_varies_by_language_context() {
            $image_path = tempnam(sys_get_temp_dir(), 'aat-cache-');
            file_put_contents($image_path, 'cache-test-image');

            $swedish_key = Auto_Alt_Text_Cache_Manager::get_cache_key($image_path, [
                'language' => 'sv',
                'instruction' => 'Generate alt text in Svenska',
            ]);
            $english_key = Auto_Alt_Text_Cache_Manager::get_cache_key($image_path, [
                'language' => 'en',
                'instruction' => 'Generate alt text in Engelska',
            ]);

            Assert::assertNotSame($swedish_key, $english_key);
            Assert::assertSame(
                $swedish_key,
                Auto_Alt_Text_Cache_Manager::get_cache_key($image_path, [
                    'instruction' => 'Generate alt text in Svenska',
                    'language' => 'sv',
                ])
            );

            unlink($image_path);
        }

        private function create_cli($language_manager = null) {
            return new Auto_Alt_Text_CLI(
                $this->openai,
                $this->statistics,
                $language_manager ?? new Auto_Alt_Text_CLI_Test_Language_Manager()
            );
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
