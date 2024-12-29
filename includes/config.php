<?php
/**
 * Defines the settings group for the Auto Alt Text plugin.
 * This constant is used to group the plugin's settings in the WordPress admin.
 */
if (!defined('SETTINGS_GROUP')) {
  define('SETTINGS_GROUP', 'auto-alt-text-settings-group');
}

/**
 * Defines the option name for the Auto Alt Text plugin's API key setting.
 * This constant is used to store and retrieve the API key setting in the WordPress options table.
 */
if (!defined('AUTO_ALT_TEXT_API_KEY_OPTION')) {
  define('AUTO_ALT_TEXT_API_KEY_OPTION', 'auto_alt_text_api_key');
}

/**
 * Defines the option name for the Auto Alt Text plugin's language setting.
 * This constant is used to store and retrieve the language setting in the WordPress options table.
 */
if (!defined('AUTO_ALT_TEXT_LANGUAGE_OPTION')) {
  define('AUTO_ALT_TEXT_LANGUAGE_OPTION', 'language');
}

/**
 * Defines a list of supported languages for the Auto Alt Text plugin.
 * The array keys represent the language codes, and the values represent the language names.
 */
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