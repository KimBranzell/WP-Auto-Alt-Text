<?php
/**
 * Automated checks and cost maths for evaluation records (generation-quality
 * spec §7.2). Dev tooling, never shipped. Needs the plugin loaded (WP-CLI
 * eval-file or the PHPUnit suite) for Auto_Alt_Text_Prompt::contains_name().
 */

const AAT_EVAL_BANNED_OPENINGS = ['image of', 'picture of', 'photo of', 'an image of', 'a picture of', 'a photo of', 'bild av', 'foto av', 'bilden visar', 'en bild av', 'ett foto av'];
const AAT_EVAL_STOPWORDS = [
    'sv' => ['och', 'med', 'en', 'ett', 'på', 'i', 'av', 'som', 'för', 'till', 'den', 'det', 'är', 'från', 'under'],
    'en' => ['and', 'with', 'a', 'an', 'the', 'on', 'in', 'of', 'for', 'to', 'is', 'at', 'from', 'under'],
];

/**
 * @param array       $item       Manifest item.
 * @param string      $text       Final alt text (after the name guarantee).
 * @param string      $first_text The model's first answer.
 * @param array       $config     naming, language (sv|en), product_name (?string), brand_name, max_length.
 * @return array<string,bool|float|null> null means "not applicable".
 */
function aat_eval_checks(array $item, $text, $first_text, array $config) {
    $expected = array_values(array_filter((array) ($item['expected_text'] ?? []), 'is_string'));
    $naming = (string) ($config['naming'] ?? 'visible');
    $product = (string) ($config['product_name'] ?? '');
    $brand = (string) ($config['brand_name'] ?? '');
    $names_required = 'visible' !== $naming && '' !== $product;

    return [
        'length_ok'       => empty($expected) ? mb_strlen($text) <= (int) ($config['max_length'] ?? 125) : null,
        'opening_ok'      => !aat_eval_starts_with_banned($text),
        'language_ok'     => aat_eval_language($text) === ($config['language'] ?? 'sv'),
        'name_first_ok'   => $names_required ? Auto_Alt_Text_Prompt::contains_name($first_text, $product) : null,
        'name_final_ok'   => $names_required ? Auto_Alt_Text_Prompt::contains_name($text, $product) : null,
        'brand_ok'        => ('product_brand' === $naming && '' !== $product && '' !== $brand) ? Auto_Alt_Text_Prompt::contains_name($text, $brand) : null,
        'brand_absent_ok' => ('visible' === $naming && '' !== $brand && !aat_eval_listed($brand, $expected)) ? !Auto_Alt_Text_Prompt::contains_name($text, $brand) : null,
        'text_recall'     => empty($expected) ? null : aat_eval_recall($text, $expected),
    ];
}

function aat_eval_starts_with_banned($text) {
    $start = mb_strtolower(ltrim((string) $text));
    foreach (AAT_EVAL_BANNED_OPENINGS as $phrase) {
        if (str_starts_with($start, $phrase)) {
            return true;
        }
    }
    return false;
}

/** 'sv', 'en' or 'unknown', by stop words (and å/ä/ö for Swedish). */
function aat_eval_language($text) {
    $lower = mb_strtolower((string) $text);
    $words = preg_split('/[^\p{L}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
    $scores = [];
    foreach (AAT_EVAL_STOPWORDS as $language => $stopwords) {
        $scores[$language] = count(array_intersect($words, $stopwords));
    }
    if (preg_match('/[åäö]/u', $lower)) {
        $scores['sv'] += 2;
    }
    arsort($scores);
    $top = array_key_first($scores);
    return $scores[$top] > 0 ? $top : 'unknown';
}

function aat_eval_listed($needle, array $expected) {
    foreach ($expected as $text) {
        if (Auto_Alt_Text_Prompt::contains_name($text, $needle)) {
            return true;
        }
    }
    return false;
}

function aat_eval_recall($text, array $expected) {
    $found = 0;
    foreach ($expected as $needle) {
        if (Auto_Alt_Text_Prompt::contains_name($text, $needle)) {
            $found++;
        }
    }
    return $found / count($expected);
}

/** USD for one call; reasoning tokens are billed as output tokens. */
function aat_eval_cost(array $model, $input_tokens, $output_tokens) {
    return ((int) $input_tokens * $model['input_price'] + (int) $output_tokens * $model['output_price']) / 1000000;
}

/** Rough USD for one call before running: image + prompt in, a short answer (plus reasoning) out. */
function aat_eval_estimate(array $model, $detail) {
    $input = ('low' === $detail ? 320 : 1300) + 450;
    $output = 60 + ((null === $model['effort'] || 'none' === $model['effort']) ? 0 : 250);
    return aat_eval_cost($model, $input, $output);
}
