# Blog Translation Feature - Complete Guide

## Overview

The Simple Translation Manager now supports **full multilingual blog functionality**, similar to WPML or Polylang. You can translate posts, pages, and custom post types directly in the WordPress editor.

## Features

✅ **Post/Page Translation**
- Translate title, slug, excerpt, and content
- Rich editor (TinyMCE) for translated content
- Translation meta box in post editor
- Language-specific slugs

✅ **Translation Groups**
- Links all translated versions together
- Easy switching between translations
- Automatic association tracking

✅ **Frontend Display**
- Automatic content translation based on `?lang=` parameter
- Session/cookie language persistence
- Filters: `the_title`, `the_content`, `the_excerpt`

✅ **Language Switcher**
- Widget (3 styles: list, dropdown, flags)
- Shortcode: `[stm_language_switcher]`
- Template function: `stm_language_switcher()`

✅ **Category/Tag Translation**
- Separate translations for term names and slugs
- Automatic term filtering on frontend

✅ **Admin Features**
- Language column in post list
- Translation status indicators
- Post language selection
- Tab-based translation interface

---

## Usage

### 1. Translating a Post

1. Create or edit a post in WordPress admin
2. Scroll down to the **"Translations"** meta box
3. Select the **current post language** (e.g., English)
4. Click on a language tab (e.g., **🇳🇱 Dutch**)
5. Fill in the translated content:
   - **Title**: Translated post title
   - **Slug**: URL-friendly slug for this language
   - **Excerpt**: Translated excerpt
   - **Content**: Translated post content (with rich editor)
6. Click **Publish** or **Update**

### 2. Viewing Translations

**Default language (English):**
```
http://localhost/my-post/
```

**Translated language (Dutch):**
```
http://localhost/my-post/?lang=nl
```

The plugin automatically:
- Detects the `?lang=` parameter
- Displays translated content
- Stores language preference in cookie/session

### 3. Language Switcher

**Widget:**
1. Go to **Appearance → Widgets**
2. Add **"Language Switcher"** widget
3. Choose style: List, Dropdown, or Flags
4. Save

**Shortcode (in posts/pages):**
```
[stm_language_switcher style="list"]
[stm_language_switcher style="dropdown"]
[stm_language_switcher style="flags"]
```

**Template function (in theme files):**
```php
<?php stm_language_switcher(['style' => 'list']); ?>
```

### 4. Template Usage

**Get current language:**
```php
$current_lang = STM\Frontend::get_current_language(); // 'en', 'nl', etc.
```

**Check post language:**
```php
$post_lang = STM\PostEditor::get_post_language($post_id);
```

**Get specific translation:**
```php
$translation = STM\PostEditor::get_post_translation($post_id, 'nl');
echo $translation['post_title'];
echo $translation['post_content'];
```

---

## Database Structure

### New Tables

**`wp_stm_post_associations`**
Links translated post versions together via `translation_group` UUID.

| Column | Type | Description |
|--------|------|-------------|
| post_id | bigint | WordPress post ID |
| language_code | varchar(10) | Language code (en, nl) |
| translation_group | varchar(32) | UUID linking all translations |
| is_original | tinyint(1) | Original post flag |

**`wp_stm_term_translations`**
Stores category/tag translations.

| Column | Type | Description |
|--------|------|-------------|
| term_id | bigint | WordPress term ID |
| language_code | varchar(10) | Language code |
| name | varchar(200) | Translated name |
| slug | varchar(200) | Translated slug |
| description | text | Translated description |

---

## How It Works

### Translation Flow

1. **Post Creation**
   - User creates post in English (default language)
   - Post gets assigned to translation group

2. **Adding Translation**
   - User opens translation tab for Dutch
   - Fills in Dutch title, content, slug, excerpt
   - Saves post → translations stored in `wp_stm_post_translations`

3. **Frontend Display**
   - User visits `/?p=123&lang=nl`
   - Frontend class detects language parameter
   - Content filters replace English with Dutch
   - User sees Dutch version

4. **Language Persistence**
   - Language choice stored in cookie (30 days)
   - Stored in session (until browser close)
   - All subsequent pages show selected language

### Content Filters

The plugin hooks into WordPress filters:

```php
add_filter('the_title', [Frontend::class, 'filter_title']);
add_filter('the_content', [Frontend::class, 'filter_content']);
add_filter('the_excerpt', [Frontend::class, 'filter_excerpt']);
add_filter('get_term', [Frontend::class, 'filter_term']);
```

**Filter logic:**
1. Check current language (`?lang=`, cookie, session, default)
2. Check post language (from `wp_stm_post_associations`)
3. If mismatch → fetch translation from `wp_stm_post_translations`
4. Return translated content if available, otherwise original

---

## Configuration

### Enable/Disable URL Routing

Go to **Translations → Settings**:
- Enable/disable language URLs (`/en/`, `/nl/`)
- Set cache duration
- Configure debug mode

### Add More Languages

1. Go to **Translations → Languages**
2. Click **"Add Language"**
3. Fill in:
   - Code (e.g., `de`, `fr`, `es`)
   - Name (e.g., `German`, `French`)
   - Native name (e.g., `Deutsch`, `Français`)
   - Flag emoji (e.g., 🇩🇪, 🇫🇷)
4. Save

---

## SEO Considerations

**Current implementation:**
- Uses query parameters (`?lang=nl`)
- Good: Simple, works immediately
- Limitation: Google treats as same URL

**Future enhancement options:**
1. **Subdirectory URLs**: `/en/my-post/`, `/nl/mijn-post/`
2. **Subdomain URLs**: `en.example.com`, `nl.example.com`
3. **Domain URLs**: `example.com`, `example.nl`

For production SEO, consider implementing subdirectory URLs with rewrite rules.

---

## Example Workflow

### Complete Blog Translation

1. **Setup Languages**
   ```
   EN (English) - Default
   NL (Dutch)
   FR (French)
   ```

2. **Create Post in English**
   ```
   Title: "How to Use WordPress Multilingual"
   Content: "This is a guide about..."
   Slug: how-to-use-wordpress-multilingual
   ```

3. **Add Dutch Translation**
   ```
   Title: "Hoe WordPress Meertalig Te Gebruiken"
   Content: "Dit is een handleiding over..."
   Slug: hoe-wordpress-meertalig-te-gebruiken
   ```

4. **Add French Translation**
   ```
   Title: "Comment Utiliser WordPress Multilingue"
   Content: "Ceci est un guide sur..."
   Slug: comment-utiliser-wordpress-multilingue
   ```

5. **Result**
   - English: `/?p=123`
   - Dutch: `/?p=123&lang=nl`
   - French: `/?p=123&lang=fr`

---

## Backwards Compatibility

All existing features still work:

✅ **Template Strings**
```php
echo __t('nav.home'); // Still works
```

✅ **Custom Post Type Fields**
```php
echo stm_get_post_translation($post_id, 'title'); // Still works
```

✅ **REST API**
All endpoints unchanged

✅ **Existing Translations**
Preserved in database

---

## Troubleshooting

### Translation not showing
1. Check language is set correctly in meta box
2. Verify translation is saved (check database)
3. Clear cache (WordPress cache + browser)
4. Check `?lang=` parameter in URL

### Meta box not visible
1. Check post type is public
2. Refresh plugins page
3. Check user has `edit_posts` capability

### Language switcher not working
1. Verify widget is added to sidebar
2. Check shortcode syntax
3. Ensure multiple languages exist

---

## Performance

**Optimizations included:**
- WordPress object cache for languages
- Single database query per translation
- Efficient filters (only when needed)
- Session/cookie to reduce database hits

**Production recommendations:**
- Use object cache plugin (Redis/Memcached)
- Enable CDN for static assets
- Set appropriate cache duration (Settings page)

---

## Comparison to Other Plugins

| Feature | This Plugin | WPML | Polylang |
|---------|-------------|------|----------|
| Post translation | ✅ | ✅ | ✅ |
| Rich editor | ✅ | ✅ | ✅ |
| Translation groups | ✅ | ✅ | ✅ |
| Category translation | ✅ | ✅ | ✅ |
| Language switcher | ✅ | ✅ | ✅ |
| URL structure | Query param | Subdirectory | Subdirectory |
| Price | Free | €99/year | Free/€99 |
| Database overhead | Lightweight | Heavy | Medium |

---

## Credits

Built by Martien de Jong
Version: 1.0.0 (Blog Support)
License: GPL v2+

For issues or feature requests, visit the plugin repository.
