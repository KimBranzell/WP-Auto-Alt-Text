<?php
/**
 * Re-downloads the evaluation images listed in eval/manifest.json and checks
 * each file's sha256, so the set can be rebuilt with one command:
 *
 *   php eval/fetch.php
 *
 * Plain PHP (no WordPress). Dev tooling, never shipped.
 */

if (PHP_SAPI !== 'cli') { exit; }

$aat_dir = __DIR__;
$aat_manifest = json_decode((string) file_get_contents($aat_dir . '/manifest.json'), true);
if (!is_array($aat_manifest) || empty($aat_manifest['items'])) {
    fwrite(STDERR, "eval/manifest.json is missing or invalid\n");
    exit(1);
}
if (!is_dir($aat_dir . '/images')) {
    mkdir($aat_dir . '/images', 0755, true);
}

$aat_context = stream_context_create(['http' => [
    'header'          => "User-Agent: WP-Auto-Alt-Text-eval/1.0 (+https://github.com/KimBranzell/WP-Auto-Alt-Text)\r\n",
    'timeout'         => 60,
    'follow_location' => 1,
]]);

$aat_failed = 0;
foreach ($aat_manifest['items'] as $aat_item) {
    if (empty($aat_item['file'])) {
        continue;
    }
    $aat_path = $aat_dir . '/' . $aat_item['file'];
    if (is_file($aat_path) && hash_file('sha256', $aat_path) === $aat_item['sha256']) {
        echo "ok      {$aat_item['id']}\n";
        continue;
    }
    $aat_data = @file_get_contents($aat_item['file_url'], false, $aat_context);
    if (false === $aat_data || hash('sha256', $aat_data) !== $aat_item['sha256']) {
        echo "FAILED  {$aat_item['id']} {$aat_item['file_url']}\n";
        $aat_failed++;
    } else {
        file_put_contents($aat_path, $aat_data);
        echo "fetched {$aat_item['id']}\n";
    }
    usleep(500000); // Be polite to the image hosts.
}
exit($aat_failed ? 1 : 0);
