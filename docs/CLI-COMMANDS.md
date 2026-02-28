# WP-CLI Commands

Simple Translation Manager provides WP-CLI commands for bulk operations and automation.

## Installation

WP-CLI commands are automatically available after plugin activation. No additional setup required.

## Commands

### Import Post Translations

Import translations from a JSON file.

```bash
wp stm import-posts translations.json --lang=nl
```

**Options:**
- `<file>` - Path to JSON file (required)
- `--lang=<code>` - Language code (default: nl)
- `--dry-run` - Preview changes without saving

**JSON Format:**
```json
{
  "2903": {
    "category": "Innovatie",
    "title": "AI Integratie",
    "description": "Op maat gemaakte AI-oplossingen..."
  },
  "2904": {
    "category": "Ontwikkeling",
    "title": "Web Ontwikkeling"
  }
}
```

**Examples:**
```bash
# Import Dutch translations
wp stm import-posts translations.json --lang=nl

# Preview without saving
wp stm import-posts translations.json --lang=nl --dry-run

# Import English translations
wp stm import-posts en-translations.json --lang=en
```

---

### Find Missing Translations

Find posts that are missing translations in a specific language.

```bash
wp stm find-missing --post-type=mdj_service --lang=nl
```

**Options:**
- `--post-type=<type>` - Post type to check (default: all)
- `--lang=<code>` - Language code (default: nl)
- `--field=<field>` - Specific field to check (default: title)
- `--export=<file>` - Export missing posts to JSON template

**Examples:**
```bash
# Find all posts missing Dutch translations
wp stm find-missing --lang=nl

# Check specific post type
wp stm find-missing --post-type=mdj_service --lang=nl

# Export template for missing posts
wp stm find-missing --post-type=mdj_project --lang=nl --export=missing.json
```

The `--export` option creates a JSON template file with placeholders for all missing translations:

```json
{
  "2903": {
    "title": "",
    "description": "",
    "category": "",
    "_note": "Original: AI Integration"
  }
}
```

Fill in the empty fields and then import with `wp stm import-posts`.

---

### Translation Statistics

Show translation coverage statistics.

```bash
wp stm stats
```

**Options:**
- `--post-type=<type>` - Post type to analyze (default: all)

**Examples:**
```bash
# Show stats for all posts
wp stm stats

# Show stats for specific post type
wp stm stats --post-type=mdj_service
```

**Output:**
```
=== Translation Statistics ===

Total posts: 17

🇬🇧 English (en): 17/17 (100.0%)
🇳🇱 Nederlands (nl): 8/17 (47.1%)
🇩🇪 Deutsch (de): 0/17 (0.0%)
```

---

## Programmatic Usage (PHP)

You can also use the bulk API directly in PHP:

```php
use STM\API;

// Save multiple fields for one post
$result = API::save_post_translations(2903, [
    'category' => 'Innovatie',
    'title' => 'AI Integratie',
    'description' => 'Op maat gemaakte AI oplossingen...'
], 'nl');

// Check results
if (count($result['errors']) > 0) {
    error_log("Failed to save some translations");
} else {
    echo "{$result['success']}/{$result['total']} translations saved";
}
```

---

## Workflow Examples

### Complete Translation Workflow

1. **Find missing translations:**
```bash
wp stm find-missing --post-type=mdj_service --lang=nl --export=missing-nl.json
```

2. **Fill in translations in the JSON file** (edit missing-nl.json)

3. **Preview import:**
```bash
wp stm import-posts missing-nl.json --lang=nl --dry-run
```

4. **Import for real:**
```bash
wp stm import-posts missing-nl.json --lang=nl
```

5. **Verify coverage:**
```bash
wp stm stats --post-type=mdj_service
```

### Migrate from Another System

If you have translations in another format, convert to JSON and import:

```bash
# Example: Convert CSV to JSON (custom script)
python convert-csv-to-json.py translations.csv > translations.json

# Import
wp stm import-posts translations.json --lang=nl
```

---

## Automation

Add to cron jobs or deployment scripts:

```bash
#!/bin/bash
# deploy-translations.sh

# Import translations after deployment
wp stm import-posts /var/www/translations/nl.json --lang=nl
wp stm import-posts /var/www/translations/en.json --lang=en

# Verify coverage
wp stm stats
```

---

## Error Handling

All commands include error logging:

- Database errors are logged to PHP error log
- Failed translations are reported individually
- Use `--dry-run` to preview before making changes

Check logs:
```bash
tail -f /var/log/php-errors.log | grep "\[STM\]"
```

---

## Support

For issues or questions, check:
- Plugin error log: `[STM]` prefix in PHP error log
- WordPress debug log: `WP_DEBUG_LOG` enabled
- Database table: `wp_stm_post_translations`
