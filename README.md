# Simple Translation Manager

Lightweight WordPress multilingual plugin met database storage en WordPress object caching.

## Features

✅ **Database + WordPress Caching** - Fast, geen JSON overhead voor runtime
✅ **Complete REST API** - AI kan met Application Password alles beheren
✅ **Slug per language** - Per pagina instelbaar (shared slug of language-specific)
✅ **Template translations** - Alle strings in templates vertaalbaar
✅ **Admin UI** - WordPress native interface
✅ **Import/Export** - JSON backup/restore voor portability
✅ **Translation workflow** - Draft → Published status

## Database Schema

```
stm_languages         - Available languages (en, nl, etc.)
stm_strings           - Translatable string keys
stm_translations      - Actual translations per language
stm_post_translations - Dynamic content (posts/pages)
```

## Template Functions

```php
// Get translation
echo __stm('nav.home', 'Home', 'general');

// Echo translation
_e_stm('site.tagline', 'Default tagline');

// Post field translation
echo stm_get_post_translation($post_id, 'title');
echo stm_get_post_translation($post_id, 'excerpt', 'nl');

// Language switcher
stm_language_switcher('dropdown');  // or 'flags', 'list'

// Get current language
$lang = stm_get_current_language();  // 'en', 'nl', etc.
```

## REST API Endpoints

Alle endpoints ondersteunen Application Password authenticatie.

### Languages
```
GET    /wp-json/stm/v1/languages
POST   /wp-json/stm/v1/languages
```

### Strings & Translations
```
GET    /wp-json/stm/v1/strings
POST   /wp-json/stm/v1/strings
GET    /wp-json/stm/v1/translations
POST   /wp-json/stm/v1/translations
POST   /wp-json/stm/v1/translations/bulk
```

### Post Translations
```
GET    /wp-json/stm/v1/posts/{id}/translations
POST   /wp-json/stm/v1/posts/{id}/translations

# Slugs per language
GET    /wp-json/stm/v1/posts/{id}/slugs
POST   /wp-json/stm/v1/posts/{id}/slugs
```

### Import/Export
```
GET    /wp-json/stm/v1/export?lang=nl&context=general
POST   /wp-json/stm/v1/import
```

## Bulk Import Voorbeeld (AI gebruik)

```bash
# Via curl met Application Password
curl -X POST \
  -u "username:APPLICATION_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '[
    {"key": "nav.home", "lang": "nl", "translation": "Home", "context": "general"},
    {"key": "nav.about", "lang": "nl", "translation": "Over", "context": "general"},
    {"key": "nav.contact", "lang": "nl", "translation": "Contact", "context": "general"}
  ]' \
  https://martiendejong.nl/wp-json/stm/v1/translations/bulk
```

## Slug per Language

Per pagina instelbaar:

```php
// Shared slug (default WordPress behavior)
/about  → works for all languages

// Language-specific slugs
/en/about
/nl/over
/fr/a-propos

// Via API
POST /wp-json/stm/v1/posts/123/slugs
{
  "language_code": "nl",
  "slug": "over-ons"
}
```

## URL Detection Priority

1. URL path: `/nl/about`
2. URL parameter: `?lang=nl`
3. Session
4. Cookie
5. Default language

## Caching

WordPress Object Cache (automatisch):
- Transients voor database queries
- Compatibel met Redis/Memcached
- Invalidatie bij updates

## Admin Interface

WordPress Admin → **Translations**
- Strings: Manage translation keys
- Languages: Add/edit languages
- Import/Export: JSON backup/restore
- Settings: Default language, URL structure

## Installation

1. Upload plugin folder to `/wp-content/plugins/`
2. Activate via WordPress admin
3. Go to **Translations → Settings**
4. Configure languages
5. Start translating!

## Compatibility

- WordPress 5.0+
- PHP 7.4+
- Application Passwords (WP 5.6+)

## Roadmap

- [ ] Admin templates (UI views)
- [ ] Auto-translate via OpenAI/DeepL
- [ ] Translation memory
- [ ] Missing translation detector
- [ ] Bulk actions (delete, status change)
- [ ] CSV import/export
