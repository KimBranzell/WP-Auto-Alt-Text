<?php
/**
 * Helpers for eval/run.php: arguments, manifest, import into the site.
 * Dev tooling, never shipped.
 */

function aat_eval_parse_args(array $args) {
    $options = [
        'models'  => implode(',', array_keys(Auto_Alt_Text_Models::all())),
        'naming'  => 'product',
        'detail'  => 'auto',
        'lang'    => 'sv',
        'items'   => 'all',
        'repeat'  => '1',
        'run'     => gmdate('Ymd-His'),
        'dry-run' => '0',
    ];
    foreach ($args as $arg) {
        [$key, $value] = array_pad(explode('=', (string) $arg, 2), 2, '1');
        if (!array_key_exists($key, $options)) {
            WP_CLI::error(sprintf('Unknown argument "%s". Use: %s', $key, implode(', ', array_keys($options))));
        }
        $options[$key] = $value;
    }
    $list = static function ($value) {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    };
    return [
        'models'  => $list($options['models']),
        'naming'  => $list($options['naming']),
        'detail'  => $list($options['detail']),
        'lang'    => $list($options['lang']),
        'items'   => (string) $options['items'],
        'repeat'  => max(1, (int) $options['repeat']),
        'run'     => sanitize_file_name($options['run']),
        'dry_run' => '1' === (string) $options['dry-run'],
    ];
}

function aat_eval_manifest() {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/manifest.json'), true);
    if (!is_array($manifest) || empty($manifest['items'])) {
        WP_CLI::error('eval/manifest.json is missing or invalid.');
    }
    return $manifest['items'];
}

/** @param string $selector all | category:<name> | comma-separated IDs */
function aat_eval_select_items(array $items, $selector) {
    if ('all' === $selector) {
        return $items;
    }
    if (str_starts_with($selector, 'category:')) {
        $category = substr($selector, 9);
        return array_values(array_filter($items, static fn($item) => $item['category'] === $category));
    }
    $ids = array_map('trim', explode(',', $selector));
    return array_values(array_filter($items, static fn($item) => in_array($item['id'], $ids, true)));
}

/** The selected items plus the other members of their colour-pair groups (a variable product needs all its variations). */
function aat_eval_with_siblings(array $selected, array $all) {
    $groups = array_filter(array_unique(array_column($selected, 'variant_group')));
    $ids = array_column($selected, 'id');
    $result = $selected;
    foreach ($all as $item) {
        if (!empty($item['variant_group']) && in_array($item['variant_group'], $groups, true) && !in_array($item['id'], $ids, true)) {
            $result[] = $item;
        }
    }
    return $result;
}

/** Items whose image file is not in eval/images (devsite items use existing attachments and are skipped). */
function aat_eval_missing_files(array $items) {
    $missing = [];
    foreach ($items as $item) {
        if ('devsite' !== $item['source'] && !empty($item['file']) && !is_file(dirname(__DIR__) . '/' . $item['file'])) {
            $missing[] = $item['id'];
        }
    }
    return $missing;
}

/**
 * Makes sure every item exists on the site: an attachment, and a WooCommerce
 * product (or variation) for items with a shop name. Idempotent through the
 * _aat_eval_id meta.
 *
 * @return array<string,array{attachment_id:int,product_name:?string}>
 */
function aat_eval_import(array $items) {
    $prepared = [];
    $groups = [];
    $attachments = [];
    $all = aat_eval_manifest();
    $woocommerce = class_exists('WC_Product_Simple');
    if (!$woocommerce) {
        WP_CLI::warning('WooCommerce is not active: product items run without product context.');
    }

    foreach ($items as $item) {
        $attachment_id = 'devsite' === $item['source'] ? (int) $item['attachment_id'] : aat_eval_attachment($item);
        if (!$attachment_id) {
            continue;
        }
        $prepared[$item['id']] = ['attachment_id' => $attachment_id, 'product_name' => null];
        if (!$woocommerce || empty($item['shop_name'])) {
            continue;
        }
        if (!empty($item['variant_group'])) {
            $groups[$item['variant_group']] = true;
            continue;
        }
        aat_eval_simple_product($item, $attachment_id);
        $prepared[$item['id']]['product_name'] = $item['shop_name'];
    }

    // Groups are built from the full manifest, so a subset run never rewrites the attribute options or drops a sibling variation.
    foreach (array_keys($groups) as $group) {
        $members = array_values(array_filter($all, static fn($member) => ($member['variant_group'] ?? null) === $group && !empty($member['shop_name'])));
        foreach ($members as $member) {
            $attachments[$member['id']] = $prepared[$member['id']]['attachment_id'] ?? aat_eval_attachment($member);
        }
        $members = array_values(array_filter($members, static fn($member) => !empty($attachments[$member['id']])));
        aat_eval_variable_product($group, $members, $attachments);
        foreach ($members as $member) {
            if (isset($prepared[$member['id']])) {
                $prepared[$member['id']]['product_name'] = $member['shop_name'];
            }
        }
    }
    return $prepared;
}

/** Uploads the item's image under a hash filename, so the filename gives no hints. */
function aat_eval_attachment(array $item) {
    $existing = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_aat_eval_id', 'meta_value' => $item['id'], 'fields' => 'ids', 'numberposts' => 1]);
    if ($existing) {
        return (int) $existing[0];
    }
    $source = dirname(__DIR__) . '/' . $item['file'];
    if (!is_file($source)) {
        WP_CLI::warning(sprintf('%s: %s is missing. Run php eval/fetch.php first.', $item['id'], $item['file']));
        return 0;
    }
    $tmp = wp_tempnam(basename($source));
    copy($source, $tmp);
    $name = substr($item['sha256'], 0, 20) . '.' . strtolower(pathinfo($source, PATHINFO_EXTENSION));
    $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0);
    if (is_wp_error($id)) {
        @unlink($tmp);
        WP_CLI::warning(sprintf('%s: %s', $item['id'], $id->get_error_message()));
        return 0;
    }
    update_post_meta($id, '_aat_eval_id', $item['id']);
    return (int) $id;
}

function aat_eval_find_product($eval_id) {
    $found = get_posts(['post_type' => ['product', 'product_variation'], 'post_status' => 'any', 'meta_key' => '_aat_eval_id', 'meta_value' => $eval_id, 'fields' => 'ids', 'numberposts' => 1]);
    return $found ? (int) $found[0] : 0;
}

function aat_eval_simple_product(array $item, $attachment_id) {
    $existing = aat_eval_find_product($item['id']);
    $product = $existing ? wc_get_product($existing) : new WC_Product_Simple();
    $product->set_name($item['shop_name']);
    $product->set_status('publish');
    $product->set_image_id($attachment_id);
    $product->save();
    update_post_meta($product->get_id(), '_aat_eval_id', $item['id']);
}

/** One variable product per variant group; each member is a variation with its own image. */
function aat_eval_variable_product($group, array $members, array $attachments) {
    $attribute = new WC_Product_Attribute();
    $attribute->set_name('Färg');
    $attribute->set_options(array_map(static fn($member) => $member['shop_colour'], $members));
    $attribute->set_visible(true);
    $attribute->set_variation(true);

    $existing = aat_eval_find_product('group:' . $group);
    $parent = $existing ? wc_get_product($existing) : new WC_Product_Variable();
    $parent->set_name($members[0]['shop_name']);
    $parent->set_status('publish');
    $parent->set_attributes([$attribute]);
    $parent->save();
    update_post_meta($parent->get_id(), '_aat_eval_id', 'group:' . $group);

    foreach ($members as $member) {
        $existing_variation = aat_eval_find_product($member['id']);
        $variation = $existing_variation ? wc_get_product($existing_variation) : new WC_Product_Variation();
        $variation->set_parent_id($parent->get_id());
        $variation->set_attributes([sanitize_title('Färg') => $member['shop_colour']]);
        $variation->set_image_id($attachments[$member['id']]);
        $variation->set_status('publish');
        $variation->save();
        update_post_meta($variation->get_id(), '_aat_eval_id', $member['id']);
    }
}
