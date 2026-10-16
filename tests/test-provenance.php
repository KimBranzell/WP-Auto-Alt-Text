<?php
class Auto_Alt_Text_Provenance_Test extends WP_UnitTestCase {
    private function image_with_alt($alt) {
        $id = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
        if (null !== $alt) {
            update_post_meta($id, '_wp_attachment_image_alt', $alt);
        }
        return $id;
    }

    public function test_classifies_missing_ai_and_human() {
        $missing = $this->image_with_alt(null);
        $blank = $this->image_with_alt('   ');
        $ai = $this->image_with_alt('A red bicycle');
        Auto_Alt_Text_Provenance::record($ai, 'upload');
        $edited = $this->image_with_alt('A red bicycle');
        Auto_Alt_Text_Provenance::record($edited, 'upload');
        update_post_meta($edited, '_wp_attachment_image_alt', 'A red bicycle leaning on a fence');
        $human = $this->image_with_alt('Written by an editor');

        $this->assertSame('missing', Auto_Alt_Text_Provenance::classify($missing));
        $this->assertSame('missing', Auto_Alt_Text_Provenance::classify($blank));
        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($ai));
        $this->assertSame('human', Auto_Alt_Text_Provenance::classify($edited));
        $this->assertSame('human', Auto_Alt_Text_Provenance::classify($human));
    }

    public function test_whitespace_only_differences_keep_ai() {
        $id = $this->image_with_alt('A red bicycle');
        Auto_Alt_Text_Provenance::record($id, 'upload');
        update_post_meta($id, '_wp_attachment_image_alt', 'A red bicycle  ');

        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($id));
    }

    public function test_record_fingerprints_the_stored_value() {
        $id = $this->image_with_alt(null);
        // Metadata functions unslash: the stored value is: It's in C:\photos
        update_post_meta($id, '_wp_attachment_image_alt', wp_slash("It's in C:\\photos"));
        Auto_Alt_Text_Provenance::record($id, 'cli');

        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($id));
        $meta = get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true);
        $this->assertSame('cli', $meta['source']);
        $this->assertSame(Auto_Alt_Text_OpenAI::get_model_name(), $meta['model']);
    }

    public function test_record_ignores_images_without_alt_text() {
        $id = $this->image_with_alt(null);
        Auto_Alt_Text_Provenance::record($id, 'upload');

        $this->assertSame('', get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true));
    }

    public function test_forget_makes_ai_text_human() {
        $id = $this->image_with_alt('A red bicycle');
        Auto_Alt_Text_Provenance::record($id, 'upload');
        Auto_Alt_Text_Provenance::forget($id);

        $this->assertSame('human', Auto_Alt_Text_Provenance::classify($id));
    }

    public function test_overwrite_policy_matrix() {
        $missing = $this->image_with_alt(null);
        $ai = $this->image_with_alt('AI text');
        Auto_Alt_Text_Provenance::record($ai, 'upload');
        $human = $this->image_with_alt('Editor text');

        $cases = [
            ['missing', $missing, true], ['missing', $ai, false], ['missing', $human, false],
            ['ai', $missing, true],      ['ai', $ai, true],       ['ai', $human, false],
            ['all', $missing, true],     ['all', $ai, true],      ['all', $human, true],
        ];
        foreach ($cases as [$policy, $id, $expected]) {
            $this->assertSame(
                $expected,
                Auto_Alt_Text_Provenance::allows_overwrite($id, $policy),
                $policy . ' / ' . Auto_Alt_Text_Provenance::classify($id)
            );
        }
    }

    public function test_default_policy_is_missing_and_unknown_values_fall_back() {
        delete_option(Auto_Alt_Text_Provenance::POLICY_OPTION);
        $this->assertSame('missing', Auto_Alt_Text_Provenance::default_policy());

        update_option(Auto_Alt_Text_Provenance::POLICY_OPTION, 'ai');
        $this->assertSame('ai', Auto_Alt_Text_Provenance::default_policy());

        $this->assertSame('missing', Auto_Alt_Text_Provenance::sanitize_policy('everything'));
        $this->assertSame('all', Auto_Alt_Text_Provenance::sanitize_policy('all'));
    }

    private function insert_generation($image_id, $text, $time) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'auto_alt_text_stats', [
            'image_id'        => $image_id,
            'user_id'         => 0,
            'generated_text'  => $text,
            'tokens_used'     => 0,
            'generation_type' => 'upload',
            'generation_time' => $time,
        ]);
    }

    public function test_backfill_fingerprints_only_unchanged_latest_generations() {
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}auto_alt_text_stats");
        $unchanged = $this->image_with_alt('Latest AI text');
        $edited = $this->image_with_alt('Editor rewrote it');
        $superseded = $this->image_with_alt('First AI text');
        $this->insert_generation($unchanged, 'Latest AI text', '2026-01-01 10:00:00');
        $this->insert_generation($edited, 'Original AI text', '2026-01-01 10:00:00');
        $this->insert_generation($superseded, 'First AI text', '2026-01-01 10:00:00');
        $this->insert_generation($superseded, 'Second AI text', '2026-01-02 10:00:00');

        Auto_Alt_Text_Provenance::backfill_batch(0, 200);

        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($unchanged));
        $this->assertSame('backfill', get_post_meta($unchanged, Auto_Alt_Text_Provenance::META_KEY, true)['source']);
        $this->assertSame('human', Auto_Alt_Text_Provenance::classify($edited));
        $this->assertSame('human', Auto_Alt_Text_Provenance::classify($superseded));
    }

    public function test_backfill_continues_in_batches() {
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}auto_alt_text_stats");
        $ids = [];
        foreach (['One', 'Two', 'Three'] as $text) {
            $id = $this->image_with_alt($text);
            $this->insert_generation($id, $text, '2026-01-01 10:00:00');
            $ids[] = $id;
        }

        $last = Auto_Alt_Text_Provenance::backfill_batch(0, 2);
        $this->assertSame($ids[1], $last);
        $this->assertSame('human', Auto_Alt_Text_Provenance::classify($ids[2]));

        $last = Auto_Alt_Text_Provenance::backfill_batch($last, 2);
        $this->assertSame($ids[2], $last);
        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($ids[2]));

        $this->assertSame(0, Auto_Alt_Text_Provenance::backfill_batch($last, 2));
    }

    public function test_activation_schedules_the_backfill_and_adds_the_context_column() {
        wp_clear_scheduled_hook('aat_provenance_backfill', [0]);
        Auto_Alt_Text_Activator::activate();

        $this->assertNotFalse(wp_next_scheduled('aat_provenance_backfill', [0]));
        global $wpdb;
        $this->assertContains('context_post_id', $wpdb->get_col('SHOW COLUMNS FROM ' . Auto_Alt_Text_Queue::get_table_name()));
        $this->assertNotFalse(has_action('aat_provenance_backfill', ['Auto_Alt_Text_Provenance', 'run_backfill']));
    }

    public function test_stats_table_is_indexed_by_image_and_generation_time() {
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $this->assertNotEmpty($wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}auto_alt_text_stats WHERE Key_name = 'idx_image'"));
    }

    public function test_deactivation_clears_scheduled_backfill_events_with_arguments() {
        wp_schedule_single_event(time() + 60, 'aat_provenance_backfill', [5]);
        do_action('deactivate_' . plugin_basename(dirname(__DIR__) . '/wp-auto-alt-text.php'));

        $this->assertFalse(wp_next_scheduled('aat_provenance_backfill', [5]));
    }
}
