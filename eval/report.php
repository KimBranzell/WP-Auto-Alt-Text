<?php
/**
 * Builds the side-by-side HTML report for one or more eval runs (spec §7.2),
 * and with judge=1 a blind scoring file for the quality judge (spec §7.4).
 *
 *   wp eval-file <plugin>/eval/report.php runs=<run>[,<run>] [judge=1]
 *
 * Writes eval/results/<first run>.html (and <first run>-judge.json plus
 * <first run>-judge-key.json). Dev tooling, never shipped.
 */

if (PHP_SAPI !== 'cli') { exit; }

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this with: wp eval-file <plugin>/eval/report.php runs=<run>\n");
    return;
}

require_once __DIR__ . '/lib/checks.php';

$aat_args = [];
foreach (isset($args) ? (array) $args : [] as $aat_arg) {
    [$aat_key, $aat_value] = array_pad(explode('=', (string) $aat_arg, 2), 2, '1');
    $aat_args[$aat_key] = $aat_value;
}
$aat_runs = array_filter(array_map('sanitize_file_name', explode(',', (string) ($aat_args['runs'] ?? ''))));
if (!$aat_runs) {
    WP_CLI::error('Give runs=<run>[,<run>].');
}

$aat_dir = __DIR__ . '/results/';
$aat_records = [];
foreach ($aat_runs as $aat_run) {
    foreach (file($aat_dir . $aat_run . '.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $aat_line) {
        $aat_records[] = json_decode($aat_line, true);
    }
}
$aat_manifest = [];
foreach (json_decode((string) file_get_contents(__DIR__ . '/manifest.json'), true)['items'] as $aat_item) {
    $aat_manifest[$aat_item['id']] = $aat_item;
}

// With several runs the run name is part of the key, so e.g. watermark on/off rows are never merged. Only the key file and report carry it; the judge file stays blind.
$aat_config = static fn($r) => sprintf('%s%s · %s · %s · %s', count($aat_runs) > 1 ? $r['run'] . ' · ' : '', $r['model'], $r['naming'], $r['detail'], $r['language']);
$aat_rate = static function (array $rows, $check) {
    $values = array_filter(array_map(static fn($r) => $r['checks'][$check] ?? null, $rows), static fn($v) => null !== $v);
    return $values ? array_sum(array_map('floatval', $values)) / count($values) : null;
};
// Spec §7.4 gates 1 and 4 read recall on packaging items only.
$aat_packaging_recall = static fn(array $rows) => $aat_rate(array_filter($rows, static fn($r) => 'packaging' === ($r['category'] ?? null)), 'text_recall');
$aat_pct = static fn($v) => null === $v ? '–' : sprintf('%d%%', round($v * 100));

// Summary per configuration.
$aat_by_config = [];
foreach ($aat_records as $aat_r) {
    $aat_by_config[$aat_config($aat_r)][] = $aat_r;
}
ksort($aat_by_config);
$aat_summary = '';
foreach ($aat_by_config as $aat_name => $aat_rows) {
    $aat_ok = array_values(array_filter($aat_rows, static fn($r) => 'ok' === $r['status']));
    $aat_format = $aat_ok ? count(array_filter($aat_ok, static fn($r) => aat_eval_format_ok($r['checks']))) / count($aat_ok) : 0;
    $aat_fixes = array_count_values(array_map(static fn($r) => (string) $r['name_fix'], $aat_ok));
    $aat_latencies = array_map(static fn($r) => $r['latency_ms'], $aat_ok);
    sort($aat_latencies);
    $aat_summary .= sprintf(
        '<tr><th scope="row">%s</th><td>%d/%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>$%.5f</td><td>%s ms</td></tr>',
        esc_html($aat_name),
        count($aat_ok),
        count($aat_rows),
        $aat_pct($aat_format),
        $aat_pct($aat_rate($aat_ok, 'name_first_ok')),
        $aat_pct($aat_rate($aat_ok, 'brand_ok')),
        $aat_pct($aat_packaging_recall($aat_ok)),
        esc_html(sprintf('%d/%d/%d', $aat_fixes['model'] ?? 0, $aat_fixes['rewrite'] ?? 0, $aat_fixes['prefix'] ?? 0)),
        $aat_pct($aat_ok ? array_sum(array_map(static fn($r) => $r['length'] <= 125 ? 1 : 0, $aat_ok)) / count($aat_ok) : null),
        $aat_ok ? array_sum(array_column($aat_ok, 'cost')) / count($aat_ok) : 0,
        $aat_latencies ? (string) $aat_latencies[(int) floor((count($aat_latencies) - 1) / 2)] : '–'
    );
}

// Per item: thumbnail plus every configuration's text and badges.
$aat_thumb = static function ($record) use ($aat_manifest) {
    $path = get_attached_file((int) $record['attachment_id']);
    if (!$path || !file_exists($path)) {
        return '';
    }
    $editor = wp_get_image_editor($path);
    if (is_wp_error($editor)) {
        return '';
    }
    $editor->resize(240, 240, false);
    $tmp = wp_tempnam('aat-thumb') . '.jpg';
    $saved = $editor->save($tmp, 'image/jpeg');
    if (is_wp_error($saved)) {
        return '';
    }
    $data = base64_encode((string) file_get_contents($saved['path']));
    wp_delete_file($saved['path']);
    wp_delete_file(substr($tmp, 0, -4));
    return '<img alt="" src="data:image/jpeg;base64,' . $data . '">';
};
$aat_by_item = [];
foreach ($aat_records as $aat_r) {
    $aat_by_item[$aat_r['item']][] = $aat_r;
}
ksort($aat_by_item);
$aat_items_html = '';
foreach ($aat_by_item as $aat_id => $aat_rows) {
    $aat_item = $aat_manifest[$aat_id] ?? [];
    $aat_items_html .= '<section class="item"><div class="thumb">' . $aat_thumb($aat_rows[0]) . '</div><div><h3>' . esc_html($aat_id . ' · ' . ($aat_item['shop_name'] ?? $aat_item['category'] ?? '')) . '</h3>';
    $aat_items_html .= '<p class="meta">' . esc_html(($aat_item['notes'] ?? '') . ' · Kontext: ' . ($aat_rows[0]['context'] ?? '–')) . '</p><table>';
    foreach ($aat_rows as $aat_r) {
        $aat_badges = '';
        foreach ((array) $aat_r['checks'] as $aat_check => $aat_value) {
            if (null === $aat_value) {
                continue;
            }
            $aat_pass = is_float($aat_value) ? $aat_value >= 0.8 : (bool) $aat_value;
            $aat_badges .= sprintf('<span class="badge %s">%s%s</span>', $aat_pass ? 'pass' : 'fail', esc_html($aat_check), is_float($aat_value) ? ' ' . round($aat_value * 100) . '%' : '');
        }
        $aat_items_html .= sprintf(
            '<tr><th scope="row">%s</th><td>%s<div class="badges">%s%s</div></td></tr>',
            esc_html($aat_config($aat_r)),
            'ok' === $aat_r['status'] ? esc_html($aat_r['text']) . ' <small>(' . (int) $aat_r['length'] . ')</small>' : '<em>' . esc_html((string) $aat_r['error']) . '</em>',
            $aat_badges,
            $aat_r['name_fix'] ? '<span class="badge info">' . esc_html('name: ' . $aat_r['name_fix']) . '</span>' : ''
        );
    }
    $aat_items_html .= '</table></div></section>';
}

$aat_html = '<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Alt Text Eval</title><style>'
    . ':root{--bg:#fff;--fg:#1d2327;--muted:#646970;--line:#dcdcde;--pass:#00703c;--fail:#b32d2e;--info:#2271b1}'
    . '@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#1d2327;--fg:#f0f0f1;--muted:#a7aaad;--line:#3c434a;--pass:#68de7c;--fail:#ff8085;--info:#72aee6}}'
    . ':root[data-theme="dark"]{--bg:#1d2327;--fg:#f0f0f1;--muted:#a7aaad;--line:#3c434a;--pass:#68de7c;--fail:#ff8085;--info:#72aee6}'
    . 'body{background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,sans-serif;margin:0 auto;max-width:1200px;padding:16px}'
    . 'table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid var(--line);padding:6px 8px;text-align:left;vertical-align:top}'
    . '.wrap{overflow-x:auto}.item{display:grid;grid-template-columns:240px 1fr;gap:16px;border-top:2px solid var(--line);padding:16px 0}'
    . '@media (max-width:700px){.item{grid-template-columns:1fr}}.thumb img{max-width:100%;height:auto}.meta,small{color:var(--muted)}'
    . '.badge{display:inline-block;font-size:12px;border:1px solid currentColor;border-radius:3px;padding:0 4px;margin:2px 4px 0 0}'
    . '.pass{color:var(--pass)}.fail{color:var(--fail)}.info{color:var(--info)}</style></head><body>'
    . '<h1>Alt text evaluation</h1><p class="meta">Runs: ' . esc_html(implode(', ', $aat_runs)) . '. Name fix = model/rewrite/prefix. Format = length (when no text to transcribe), opening and language.</p>'
    . '<div class="wrap"><table><thead><tr><th>Configuration</th><th>OK</th><th>Format</th><th>Name (first answer)</th><th>Brand</th><th>Text recall (packaging)</th><th>Name fix</th><th>≤125 chars</th><th>Cost/image</th><th>p50 latency</th></tr></thead><tbody>'
    . $aat_summary . '</tbody></table></div>' . $aat_items_html . '</body></html>';

$aat_out = $aat_dir . $aat_runs[0] . '.html';
file_put_contents($aat_out, $aat_html);
WP_CLI::success(sprintf('Wrote %s', $aat_out));

if (!empty($aat_args['judge'])) {
    // Blind scoring file: per item, the outputs under shuffled letters; the key maps letters back.
    mt_srand(20261019);
    $aat_judge = [];
    $aat_key = [];
    foreach ($aat_by_item as $aat_id => $aat_rows) {
        $aat_ok = array_values(array_filter($aat_rows, static fn($r) => 'ok' === $r['status']));
        shuffle($aat_ok);
        $aat_entry = ['item' => $aat_id, 'image' => get_attached_file((int) $aat_rows[0]['attachment_id']), 'product' => $aat_manifest[$aat_id]['shop_name'] ?? null, 'outputs' => []];
        foreach ($aat_ok as $aat_index => $aat_r) {
            $aat_label = chr(65 + $aat_index % 26) . ($aat_index >= 26 ? (string) intdiv($aat_index, 26) : '');
            $aat_entry['outputs'][] = ['label' => $aat_label, 'naming' => $aat_r['naming'], 'language' => $aat_r['language'], 'text' => $aat_r['text']];
            $aat_key[$aat_id][$aat_label] = $aat_config($aat_r) . ' #' . $aat_r['repeat'];
        }
        $aat_judge[] = $aat_entry;
    }
    file_put_contents($aat_dir . $aat_runs[0] . '-judge.json', wp_json_encode($aat_judge, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    file_put_contents($aat_dir . $aat_runs[0] . '-judge-key.json', wp_json_encode($aat_key, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    WP_CLI::success('Wrote the blind judge file and its key.');
}
