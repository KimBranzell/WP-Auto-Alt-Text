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
}
