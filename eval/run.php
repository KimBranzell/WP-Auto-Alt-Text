<?php
/**
 * Evaluation harness (generation-quality spec §7.2). Dev tooling, never shipped.
 *
 *   wp eval-file <plugin>/eval/run.php [models=a,b] [naming=product] [detail=auto] [lang=sv]
 *       [items=all|A01,B02|category:packaging] [repeat=1] [run=<name>] [dry-run=1]
 *
 * Imports the manifest into the site once, then calls
 * Auto_Alt_Text_OpenAI::run_generation() for every combination and appends a
 * record per call to eval/results/<run>.jsonl. It never writes alt text,
 * statistics or cache, and switches automatic generation off for its own
 * uploads. AAT_EVAL_API_KEY in the environment replaces the site's key for
 * this process only (the watermark comparison uses a second OpenAI project).
 */

if (!defined('ABSPATH') || !class_exists('Auto_Alt_Text_OpenAI')) {
    fwrite(STDERR, "Run this with: wp eval-file <plugin>/eval/run.php\n");
    return;
}

require_once __DIR__ . '/lib/checks.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$aat_brand = 'Nordisk Hem';
$aat_brand_description = 'En svensk webbutik för hem, kök och friluftsliv.';
$aat_options = aat_eval_parse_args(isset($args) ? (array) $args : []);
$aat_items = aat_eval_select_items(aat_eval_manifest(), $aat_options['items']);

$aat_models = [];
foreach ($aat_options['models'] as $aat_model_id) {
    $aat_model = Auto_Alt_Text_Models::get($aat_model_id);
    if (null === $aat_model) {
        WP_CLI::error(sprintf('Unknown model "%s".', $aat_model_id));
    }
    $aat_models[$aat_model_id] = $aat_model;
}
foreach ($aat_options['naming'] as $aat_naming) {
    if (!in_array($aat_naming, Auto_Alt_Text_Prompt::NAMINGS, true)) {
        WP_CLI::error(sprintf('Unknown naming level "%s".', $aat_naming));
    }
}
// The values Auto_Alt_Text_Image_Source accepts; anything else would be recorded as sent but sent differently.
foreach ($aat_options['detail'] as $aat_detail) {
    if (!in_array($aat_detail, ['low', 'high', 'auto', 'original'], true)) {
        WP_CLI::error(sprintf('Unknown detail "%s". Use: low, high, auto, original.', $aat_detail));
    }
}

$aat_per_combo = count($aat_options['naming']) * count($aat_options['lang']) * $aat_options['repeat'];
$aat_calls = 0;
$aat_estimate = 0.0;
foreach ($aat_models as $aat_model) {
    foreach ($aat_options['detail'] as $aat_detail) {
        $aat_calls += count($aat_items) * $aat_per_combo;
        $aat_estimate += count($aat_items) * $aat_per_combo * aat_eval_estimate($aat_model, $aat_detail);
    }
}
WP_CLI::log(sprintf('%d items, %d calls. Estimated cost: $%.2f (up to $%.2f if many answers need a name rewrite).', count($aat_items), $aat_calls, $aat_estimate, $aat_estimate * 1.5));
if ($aat_options['dry_run']) {
    return;
}

// The eval measures the plugin's own prompt for a fictional shop; its uploads must not queue real generations.
add_filter('pre_option_auto_alt_text_auto_generate', '__return_zero');
add_filter('pre_option_aat_brand_name', static fn() => $aat_brand);
add_filter('pre_option_aat_brand_description', static fn() => $aat_brand_description);
add_filter('pre_option_auto_alt_text_extra_instructions', static fn() => '');

$aat_prepared = aat_eval_import($aat_items);

$aat_key = getenv('AAT_EVAL_API_KEY');
if ($aat_key && !defined('AUTO_ALT_TEXT_OPENAI_API_KEY')) {
    define('AUTO_ALT_TEXT_OPENAI_API_KEY', $aat_key);
}
$aat_openai = new Auto_Alt_Text_OpenAI();

$aat_out = dirname(__FILE__) . '/results/' . $aat_options['run'] . '.jsonl';
wp_mkdir_p(dirname($aat_out));
$aat_handle = fopen($aat_out, 'a');
$aat_progress = \WP_CLI\Utils\make_progress_bar('Evaluating', $aat_calls);

foreach ($aat_items as $aat_item) {
    if (!isset($aat_prepared[$aat_item['id']])) {
        continue;
    }
    $aat_target = $aat_prepared[$aat_item['id']];
    foreach ($aat_models as $aat_model_id => $aat_model) {
        foreach ($aat_options['naming'] as $aat_naming) {
            foreach ($aat_options['detail'] as $aat_detail) {
                foreach ($aat_options['lang'] as $aat_lang) {
                    for ($aat_repeat = 1; $aat_repeat <= $aat_options['repeat']; $aat_repeat++) {
                        $aat_result = $aat_openai->run_generation($aat_target['attachment_id'], 'batch', [
                            'model'    => $aat_model_id,
                            'naming'   => $aat_naming,
                            'detail'   => $aat_detail,
                            'language' => $aat_lang,
                        ]);
                        $aat_record = [
                            'run'              => $aat_options['run'],
                            'item'             => $aat_item['id'],
                            'category'         => $aat_item['category'],
                            'attachment_id'    => $aat_target['attachment_id'],
                            'model'            => $aat_model_id,
                            'effort'           => $aat_result['effort'],
                            'detail'           => $aat_detail,
                            'naming'           => $aat_naming,
                            'language'         => $aat_lang,
                            'repeat'           => $aat_repeat,
                            'status'           => $aat_result['status'],
                            'error'            => $aat_result['error'],
                            'text'             => $aat_result['text'],
                            'first_text'       => $aat_result['first_text'],
                            'name_fix'         => $aat_result['name_fix'],
                            'length'           => mb_strlen($aat_result['text']),
                            'context'          => $aat_result['context']['title'] ?? null,
                            'input_tokens'     => $aat_result['input_tokens'],
                            'output_tokens'    => $aat_result['output_tokens'],
                            'reasoning_tokens' => $aat_result['reasoning_tokens'],
                            'cost'             => aat_eval_cost($aat_model, $aat_result['input_tokens'], $aat_result['output_tokens']),
                            'latency_ms'       => $aat_result['latency_ms'],
                            'checks'           => 'ok' === $aat_result['status'] ? aat_eval_checks($aat_item, $aat_result['text'], $aat_result['first_text'], [
                                'naming'       => $aat_naming,
                                'language'     => $aat_lang,
                                'product_name' => $aat_target['product_name'],
                                'brand_name'   => $aat_brand,
                                'max_length'   => Auto_Alt_Text_Prompt::DEFAULT_MAX_LENGTH,
                            ]) : null,
                        ];
                        fwrite($aat_handle, wp_json_encode($aat_record, JSON_UNESCAPED_UNICODE) . "\n");
                        $aat_progress->tick();
                    }
                }
            }
        }
    }
}

$aat_progress->finish();
fclose($aat_handle);
WP_CLI::success(sprintf('Wrote %s', $aat_out));
