<?php
// Plugin settings
if (!defined('SETTINGS_GROUP')) {
  define('SETTINGS_GROUP', 'auto-alt-text-settings-group');
}

if (!defined('AUTO_ALT_TEXT_API_KEY_OPTION')) {
  define('AUTO_ALT_TEXT_API_KEY_OPTION', 'auto_alt_text_api_key');
}

if (!defined('AUTO_ALT_TEXT_LANGUAGE_OPTION')) {
  define('AUTO_ALT_TEXT_LANGUAGE_OPTION', 'language');
}

if (!defined('AUTO_ALT_TEXT_LANGUAGES')) {
  define('AUTO_ALT_TEXT_LANGUAGES', [
      'sv' => 'Swedish',
      'no' => 'Norwegian',
      'dk' => 'Danish',
      'fi' => 'Finnish',
      'en' => 'English',
      'es' => 'Spanish',
      'fr' => 'French',
      'de' => 'German',
      'it' => 'Italian',
      'pt' => 'Portuguese',
      'nl' => 'Dutch',
      'ru' => 'Russian',
      'ja' => 'Japanese',
      'zh' => 'Chinese',
      'ko' => 'Korean',
      'ar' => 'Arabic',
  ]);
}