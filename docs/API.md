# Simple Translation Manager API Documentation

## Bulk Translation API

### `STM\API::save_post_translations()`

Save multiple post field translations in bulk.

**Signature:**
```php
STM\API::save_post_translations(int $post_id, array $translations, string $lang_code): array
```

**Parameters:**
- `$post_id` (int) - WordPress post ID
- `$translations` (array) - Associative array of field names to translations
- `$lang_code` (string) - Language code (e.g., 'nl', 'en', 'de')

**Returns:**
Array with keys:
- `success` (int) - Number of translations saved successfully
- `total` (int) - Total number of translations attempted
- `errors` (array) - Array of error messages for failed translations

**Example:**
```php
use STM\API;

$result = API::save_post_translations(2903, [
    'category' => 'Innovatie',
    'title' => 'AI Integratie',
    'description' => 'Op maat gemaakte AI-oplossingen die naadloos integreren met je bestaande systemen.',
    'excerpt' => 'AI-oplossingen op maat'
], 'nl');

// Check results
if (count($result['errors']) > 0) {
    // Some translations failed
    foreach ($result['errors'] as $error) {
        error_log("Translation error: $error");
    }
} else {
    // All translations saved successfully
    echo "Saved {$result['success']} translations!";
}
```

**Features:**
- **Upsert logic** - Updates existing translations, creates new ones
- **Error handling** - Continues on failure, reports all errors
- **Database error logging** - Logs to PHP error log with `[STM]` prefix
- **Cache invalidation** - Automatically clears cache for the post
- **Transactional safety** - Each translation is independent

**Database Schema:**
```sql
CREATE TABLE wp_stm_post_translations (
    id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
    post_id bigint unsigned NOT NULL,
    field_name varchar(100) NOT NULL,
    language_code varchar(10) NOT NULL,
    translation text NOT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY post_field_lang (post_id, field_name, language_code)
);
```

**Field Names:**
Common field names used in templates:
- `title` - Post title
- `excerpt` - Post excerpt/summary
- `content` - Full post content
- `description` - Meta description or custom field
- `category` - Custom taxonomy or meta field
- Any custom post meta field name

**Language Codes:**
Must be valid 2-letter language codes defined in `wp_stm_languages` table:
- `en` - English
- `nl` - Nederlands
- `de` - Deutsch
- `fr` - Français
- etc.

**Error Handling:**
```php
$result = API::save_post_translations($post_id, $translations, 'nl');

if ($result['success'] < $result['total']) {
    // Partial failure
    $failed_count = $result['total'] - $result['success'];
    error_log("$failed_count translations failed:");

    foreach ($result['errors'] as $error) {
        error_log("  - $error");
    }
}
```

**Performance:**
- Each field is saved individually (not batched)
- Cache is invalidated once per post (not per field)
- Database errors are logged but don't stop processing
- Typical performance: ~50-100 posts/second on standard WordPress hosting

**Best Practices:**

1. **Validate post exists first:**
```php
$post = get_post($post_id);
if (!$post) {
    error_log("Post $post_id not found");
    return;
}
```

2. **Validate language code:**
```php
$languages = STM\Database::get_languages();
$valid_codes = array_column($languages, 'code');

if (!in_array($lang_code, $valid_codes)) {
    error_log("Invalid language code: $lang_code");
    return;
}
```

3. **Sanitize input:**
```php
$translations = array_map('sanitize_textarea_field', $translations);
```

4. **Batch processing with progress:**
```php
$posts = get_posts(['posts_per_page' => -1, 'post_type' => 'mdj_service']);
$total = count($posts);

foreach ($posts as $index => $post) {
    $result = API::save_post_translations($post->ID, $translations[$post->ID], 'nl');

    if (($index + 1) % 10 === 0) {
        error_log("Progress: " . ($index + 1) . "/$total posts");
    }
}
```

---

## Template Functions

### `stm_get_post_translation()`

Get translation for a specific post field.

**Signature:**
```php
stm_get_post_translation(int $post_id = null, string $field = 'title', string $lang = null, mixed $fallback = ''): string
```

**Parameters:**
- `$post_id` (int|null) - Post ID (defaults to current post in loop)
- `$field` (string) - Field name (default: 'title')
- `$lang` (string|null) - Language code (defaults to current language)
- `$fallback` (mixed) - Fallback value if translation not found

**Returns:**
Translated string, or fallback value

**Example:**
```php
// In template (inside WordPress loop)
$title = stm_get_post_translation(null, 'title'); // Current post title in current language

// Explicit post and language
$description = stm_get_post_translation(2903, 'description', 'nl');

// With fallback
$category = stm_get_post_translation(2903, 'category', 'nl', 'Algemeen');
```

**Auto-fallback:**
If no translation found and no fallback provided:
- `title` → `get_the_title($post_id)`
- `excerpt` → `get_the_excerpt($post_id)`
- `content` → `get_post_field('post_content', $post_id)`
- `description` → stripped `post_content`
- Other fields → `get_post_meta($post_id, $field, true)`

---

## Cache Layer

All translations are cached using WordPress Object Cache (compatible with Redis/Memcached).

**Cache Groups:**
- `stm_translations` - All translation data

**Cache Keys:**
- String translations: `md5("{context}:{key}:{lang}")`
- Post translations: `"post_{post_id}_{field}_{lang}"`

**TTL:** 1 hour (3600 seconds)

**Manual Cache Control:**
```php
use STM\Cache;

// Invalidate specific post
Cache::invalidate_post($post_id);

// Invalidate specific field
Cache::invalidate_post($post_id, 'title');

// Flush all translation cache
Cache::flush_all();
```

**Cache is automatically invalidated when:**
- Post translation is created/updated via `API::save_post_translations()`
- String translation is created/updated via REST API
- Language is created/updated

---

## Database Tables

### `wp_stm_languages`

Stores available languages.

```sql
CREATE TABLE wp_stm_languages (
    id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
    code varchar(10) NOT NULL UNIQUE,
    name varchar(100) NOT NULL,
    native_name varchar(100) NOT NULL,
    flag_emoji varchar(10),
    is_default tinyint(1) DEFAULT 0,
    is_active tinyint(1) DEFAULT 1,
    order_index int DEFAULT 999
);
```

### `wp_stm_post_translations`

Stores post field translations.

```sql
CREATE TABLE wp_stm_post_translations (
    id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
    post_id bigint unsigned NOT NULL,
    field_name varchar(100) NOT NULL,
    language_code varchar(10) NOT NULL,
    translation text NOT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY post_field_lang (post_id, field_name, language_code),
    KEY post_id (post_id),
    KEY language_code (language_code)
);
```

### `wp_stm_strings`

Stores translatable strings (template strings, UI labels).

```sql
CREATE TABLE wp_stm_strings (
    id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
    string_key varchar(200) NOT NULL,
    context varchar(100) DEFAULT 'general',
    description text,
    UNIQUE KEY key_context (string_key, context)
);
```

### `wp_stm_translations`

Stores string translations.

```sql
CREATE TABLE wp_stm_translations (
    id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
    string_id bigint unsigned NOT NULL,
    language_code varchar(10) NOT NULL,
    translation text NOT NULL,
    status varchar(20) DEFAULT 'published',
    translated_by bigint unsigned,
    translated_at datetime,
    UNIQUE KEY string_lang (string_id, language_code),
    KEY language_code (language_code)
);
```

---

## Error Logging

All database errors are logged to PHP error log with `[STM]` prefix.

**Enable WordPress debug logging:**
```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

**Check logs:**
```bash
tail -f /path/to/wp-content/debug.log | grep "\[STM\]"
```

**Example log entries:**
```
[STM] Translation save error for post 2903 field title: Duplicate entry '2903-title-nl'
[STM] DB error getting translation for post 2903 field description: Table 'wp_stm_post_translations' doesn't exist
```

---

## Security

### Permissions

- REST API endpoints require `manage_options` capability
- WP-CLI commands require shell access (inherently admin-level)
- Template functions are public (read-only)

### Input Sanitization

All user input is sanitized:
- Language codes: `sanitize_text_field()` + validation regex
- Translation keys: `Security::sanitize_translation_key()`
- Contexts: `Security::sanitize_context()`
- Translations: `Security::sanitize_translation()`

### SQL Injection Protection

All database queries use `$wpdb->prepare()` with proper escaping.

---

## REST API

Full REST API available at `/wp-json/stm/v1/`

See [REST API Documentation](REST-API.md) for complete endpoint reference.

**Quick Examples:**

```bash
# Get all languages
curl https://example.com/wp-json/stm/v1/languages

# Create translation
curl -X POST https://example.com/wp-json/stm/v1/translations \
  -u admin:APP_PASSWORD \
  -H "Content-Type: application/json" \
  -d '{"string_id": 1, "language_code": "nl", "translation": "Hallo"}'

# Bulk import
curl -X POST https://example.com/wp-json/stm/v1/translations/bulk \
  -u admin:APP_PASSWORD \
  -H "Content-Type: application/json" \
  -d '[{"key": "nav.home", "lang": "nl", "translation": "Home"}]'
```
