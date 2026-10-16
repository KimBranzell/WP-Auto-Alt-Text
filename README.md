=== WP Auto Alt Text ===

Contributors: kimbranzell
Tags: accessibility, alt-text, openai, images, seo
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 2.0.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0


# WP Auto Alt Text

Generate high-quality, AI-powered alternative text for your WordPress images automatically using OpenAI's advanced image recognition capabilities.


## Description

WP Auto Alt Text is a powerful WordPress plugin that leverages artificial intelligence to automatically generate meaningful and descriptive alt text for your images. This improves your site's accessibility, SEO, and user experience with minimal effort.

## Key Features

- One-click AI-powered alt text generation
- WP CLI and REST API support for bulk processing in the terminal
- Bulk processing capabilities
- Adaptive request pacing based on OpenAI rate-limit headers
- Simple integration with WordPress media library
- Support for multiple image formats (including AVIF and WebP)
- Clean and intuitive user interface
- Statistics dashboard showing usage metrics and generation history
- Visual diff view for edited alt text changes
- Support for WooCommerce product images
- WPML-aware translation flow that translates one source alt text into translated media attachments
- Automatic alt text generation on image upload (configurable)
- Secure API key encryption
- Generation type tracking (Manual, Upload, Batch)
- Image caching system to prevent duplicate API calls

## API Key Responsibility

Users must provide their own OpenAI API key. This plugin does not include or share API keys.
Protect your API key by keeping it private and not sharing it with others.

### Compliance with OpenAI Terms of Use

By using this plugin, you agree to comply with OpenAI’s Terms of Use.
Ensure that your use of the OpenAI API aligns with OpenAI’s guidelines and restrictions.

### No Liability

The developers of this plugin are not responsible for any misuse of the OpenAI API or non-compliance with OpenAI’s policies by end-users.

## Installation

### Standard Installation

1. Download the plugin files
2. Upload the plugin files to your `/wp-content/plugins/` directory
3. Install the plugin through the WordPress plugins screen directly
4. Activate the plugin through the 'Plugins' screen in WordPress

### API Key Setup

1. Navigate to the 'Auto Alt Text' option in your WordPress settings menu
2. Enter your OpenAI API key
3. Save the settings
4. Generate alt text from the Media Library to confirm your API key works

Alternatively, define the key in `wp-config.php`. It then takes precedence over the key saved in the settings and is never stored in the database:

    define('AUTO_ALT_TEXT_OPENAI_API_KEY', 'sk-...');

## Usage

### Individual Images

1. Open any image in your Media Library
2. Look for the "Generate Alternative Text with AI" button
3. Click the button to generate alt text
4. Review and save the generated text

### Best Practices

- Always review generated alt text before saving
- Consider manual adjustments for brand-specific terminology
- Monitor API usage through the dashboard
- Use bulk processing for large image libraries

## Configuration

### Available Settings

- API Key management
- Automatic generation toggle: controls only the automatic generation that runs when an image is uploaded or a WooCommerce product is saved. The Generate button in the media modal, bulk actions, WP-CLI and the REST API always work, whatever the toggle says.
- Automatic throughput adaptation based on your OpenAI project limits
- Default alt text prompt templates
- Processing preferences

### Advanced Options

- Error logging preferences
- Backup settings

## Technical Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Active OpenAI API key
- Valid SSL certificate

## REST API

The plugin exposes REST endpoints for integration and scripting. All routes require the requesting user to have the `upload_files` capability (e.g. logged-in editor or administrator) and to be allowed to edit each attachment: `/generate` returns 403 for an attachment the user cannot edit, and `/batch` reports `Permission denied` for such IDs.

**Namespace:** `wp-auto-alt-text/v1`

| Method | Route | Description |
|--------|--------|-------------|
| POST | `/generate` | Generate alt text for a single attachment. Body: `attachment_id` (required), `context` (optional, e.g. post title for relevance). |
| POST | `/batch` | Generate alt text for multiple attachments. Body: `attachment_ids` (array of attachment IDs). |

**Example (single image):**
```bash
curl -X POST "https://yoursite.com/wp-json/wp-auto-alt-text/v1/generate" \
  -H "Content-Type: application/json" \
  --data '{"attachment_id": 123}' \
  --user "admin:password"
```

**Example (batch):**
```bash
curl -X POST "https://yoursite.com/wp-json/wp-auto-alt-text/v1/batch" \
  -H "Content-Type: application/json" \
  --data '{"attachment_ids": [123, 456, 789]}' \
  --user "admin:password"
```

Use WordPress application passwords or cookie authentication in production.

## WP-CLI

For large libraries, use WP-CLI to generate alt text without browser timeouts:

- `wp auto-alt-text generate` — Process all images ordered by newest attachment ID first.
- `wp auto-alt-text generate --limit=500` — Process up to 500 newest matching images.
- `wp auto-alt-text generate --limit=100 --skip-existing` — Process the 100 newest images that still need alt text. Re-running the same command continues with the next missing batch.
- `wp auto-alt-text generate --limit=100 --offset=100` — Skip the first 100 matching images and process the next 100.
- `wp auto-alt-text generate --limit=100 --resume` — Continue from the previous batch position for the same CLI filters.
- `wp auto-alt-text translate --all` — Translate existing source alt texts across WPML media translations.
- `wp auto-alt-text translate --ids=123,456` — Translate specific attachment groups without re-analyzing the image.
- `wp auto-alt-text stats` — Show generation statistics.
- `wp auto-alt-text queue` — Inspect the background processing queue.
- `wp auto-alt-text queue --status=failed` — Show failed queue jobs with their error messages.
- `wp auto-alt-text queue --retry-failed` — Re-queue all failed jobs for processing.
- `wp auto-alt-text queue --limit=100` — Show up to 100 queue entries (default: 50).

## Background Processing

Automatic alt text generation (image uploads, WooCommerce product saves) does not call OpenAI during the triggering request. Images are added to a persistent queue and processed in the background by WP-Cron, a few at a time. Failed jobs are retried with increasing delays; if your OpenAI quota runs out, the queue pauses, a site admin is emailed once, and processing resumes automatically when the quota is restored. Without an API key, or while OpenAI rejects the key (HTTP 401), queued images wait instead of failing. After a rejected key the queue pauses for 15 minutes; saving a new key in the settings resumes it immediately.

For reliable background processing we strongly recommend real server cron instead of WordPress' visitor-triggered cron:

1. Add to `wp-config.php`:

    define('DISABLE_WP_CRON', true);

2. Add a system crontab entry running every minute:

    * * * * * cd /path/to/site && wp cron event run --due-now >/dev/null 2>&1

Inspect the queue at any time with `wp auto-alt-text queue` (add `--status=failed` to see errors, `--retry-failed` to re-queue failed jobs), or in the plugin's statistics page in wp-admin.

## WooCommerce

Product images without alt text are queued in two ways only: when a product (or a variation) is saved in wp-admin, and from the Products list with the bulk action "Generate Alt Texts" (which runs even when automatic generation is turned off). Nothing is queued at checkout, on stock updates or from front-end requests, and no OpenAI call is made while a shop page renders.

## Running tests

Requirements: PHP 8.0+, Composer, WP-CLI, and a MySQL user that can create databases.

1. `composer install`
2. `wp core download --version=7.1.1 --path=.wp-core`
3. Optional, enables the WooCommerce tests:
   `curl -sSL https://downloads.wordpress.org/plugin/woocommerce.11.2.1.zip -o .wp-core/woocommerce.zip && unzip -q -o .wp-core/woocommerce.zip -d .wp-core/wp-content/plugins/ && rm .wp-core/woocommerce.zip`
4. `cp tests/wp-tests-config.php.dist tests/wp-tests-config.php` and set the DB credentials. Use a dedicated database (default `wp_aat_tests`) — the suite drops its tables.
5. `composer test`

## Contributing

We welcome contributions to improve WP Auto Alt Text!

1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Open a Pull Request

## Support

- Create an issue in the GitHub repository

## License

This project is licensed under the Apache License v2.0 or later - see the LICENSE file for details.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full list of changes in each version.

## Credits

- Developed by Kim Branzell
- Powered by OpenAI's API
- Special thanks to all contributors
