<?php

if (!defined('ICL_SITEPRESS_VERSION')) {
    define('ICL_SITEPRESS_VERSION', 'test');
}

class Auto_Alt_Text_Fake_Translator extends Auto_Alt_Text_OpenAI {
    public $calls = [];

    public function __construct() {}

    public function translate_alt_text($prompt) {
        $this->calls[] = $prompt;

        if (strpos($prompt, '(fr)') !== false) {
            return 'Texte alternatif francais';
        }

        return 'Translated alt text';
    }
}

class Auto_Alt_Text_Language_Manager_Test extends WP_UnitTestCase {
    private $translator;
    private $language_manager;
    private $languages = [];
    private $translations = [];
    private $current_language = 'en';

    public function setUp(): void {
        parent::setUp();

        $this->translator = new Auto_Alt_Text_Fake_Translator();
        $this->language_manager = new Auto_Alt_Text_Language_Manager($this->translator);

        add_filter('wpml_default_language', [$this, 'filter_default_language']);
        add_filter('wpml_post_language_details', [$this, 'filter_post_language_details'], 10, 2);
        add_filter('wpml_element_trid', [$this, 'filter_element_trid'], 10, 3);
        add_filter('wpml_get_element_translations', [$this, 'filter_element_translations'], 10, 3);
        add_filter('wpml_object_id', [$this, 'filter_object_id'], 10, 4);
        add_filter('wpml_current_language', [$this, 'filter_current_language'], 10, 2);
    }

    public function tearDown(): void {
        remove_filter('wpml_default_language', [$this, 'filter_default_language']);
        remove_filter('wpml_post_language_details', [$this, 'filter_post_language_details'], 10);
        remove_filter('wpml_element_trid', [$this, 'filter_element_trid'], 10);
        remove_filter('wpml_get_element_translations', [$this, 'filter_element_translations'], 10);
        remove_filter('wpml_object_id', [$this, 'filter_object_id'], 10);
        remove_filter('wpml_current_language', [$this, 'filter_current_language'], 10);

        $this->languages = [];
        $this->translations = [];
        $this->current_language = 'en';

        parent::tearDown();
    }

    public function test_generate_multilingual_alt_text_translates_wpml_targets_from_source_attachment() {
        $source_attachment_id = $this->create_attachment('Source image');
        $translated_attachment_id = $this->create_attachment('Translated image');

        $this->languages = [
            $source_attachment_id => 'en',
            $translated_attachment_id => 'fr',
        ];

        $this->translations = [
            'en' => $source_attachment_id,
            'fr' => $translated_attachment_id,
        ];

        $result = $this->language_manager->generate_multilingual_alt_text($source_attachment_id, 'Accessible source alt text');

        self::assertSame('Accessible source alt text', get_post_meta($source_attachment_id, '_wp_attachment_image_alt', true));
        self::assertSame('Texte alternatif francais', get_post_meta($translated_attachment_id, '_wp_attachment_image_alt', true));
        self::assertSame('Texte alternatif francais', get_post_meta($source_attachment_id, '_wp_attachment_image_alt_fr', true));
        self::assertSame('source', $result['translations']['en']['status']);
        self::assertSame('translated', $result['translations']['fr']['status']);
        self::assertCount(1, $this->translator->calls);
    }

    public function test_bulk_generate_translations_skips_duplicate_translated_attachments() {
        $source_attachment_id = $this->create_attachment('Source image');
        $translated_attachment_id = $this->create_attachment('Translated image');

        $this->languages = [
            $source_attachment_id => 'en',
            $translated_attachment_id => 'fr',
        ];

        $this->translations = [
            'en' => $source_attachment_id,
            'fr' => $translated_attachment_id,
        ];

        update_post_meta($source_attachment_id, '_wp_attachment_image_alt', 'Existing source alt text');

        $results = $this->language_manager->bulk_generate_translations([$source_attachment_id, $translated_attachment_id]);

        self::assertCount(1, $results);
        self::assertArrayHasKey($source_attachment_id, $results);
        self::assertSame('Texte alternatif francais', get_post_meta($translated_attachment_id, '_wp_attachment_image_alt', true));
        self::assertCount(1, $this->translator->calls);
    }

    public function filter_default_language() {
        return 'en';
    }

    public function filter_post_language_details($details, $post_id) {
        return [
            'language_code' => $this->languages[$post_id] ?? 'en',
        ];
    }

    public function filter_element_trid($trid, $post_id, $element_type) {
        return isset($this->languages[$post_id]) ? 100 : null;
    }

    public function filter_element_translations($translation_details, $trid, $element_type) {
        $translations = [];

        foreach ($this->translations as $language => $attachment_id) {
            $translations[$language] = (object) [
                'element_id' => $attachment_id,
            ];
        }

        return $translations;
    }

    public function filter_object_id($translated_id, $attachment_id, $return_original, $language) {
        return $this->translations[$language] ?? 0;
    }

    public function filter_current_language($language, $args = null) {
        return $this->current_language;
    }

    private function create_attachment($title) {
        return wp_insert_attachment([
            'post_title' => $title,
            'post_type' => 'attachment',
            'post_mime_type' => 'image/jpeg',
            'post_status' => 'inherit',
        ]);
    }
}