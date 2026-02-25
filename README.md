# Simple Translation Manager

Lightweight multilingual WordPress plugin with database storage, REST API, and built-in caching.

## Features

- ✅ **Database Storage** - All translations stored in custom database tables
- ✅ **WordPress Caching** - Built-in object cache support for performance
- ✅ **REST API** - Full API for programmatic translation management
- ✅ **Admin Interface** - Search, pagination, inline editing
- ✅ **Post Translations** - Support for translating CPT fields
- ✅ **URL Routing** - Clean language URLs (`/en/`, `/nl/`)
- ✅ **Fully Generic** - Works with any WordPress site

## Installation

1. Upload plugin folder to `/wp-content/plugins/`
2. Activate plugin via WordPress admin
3. Go to **Translations → Languages** to configure languages
4. Go to **Translations → Strings** to add translations

## Configuration

### Default Languages

By default, the plugin installs **English** and **Dutch**. To use different languages, add this filter to your theme's `functions.php`:

```php
add_filter('stm_default_languages', function($languages) {
    return [
        [
            'code' => 'es',
            'name' => 'Spanish',
            'native_name' => 'Español',
            'is_default' => 1,
            'flag_emoji' => '🇪🇸',
            'order_index' => 1
        ],
        [
            'code' => 'fr',
            'name' => 'French',
            'native_name' => 'Français',
            'is_default' => 0,
            'flag_emoji' => '🇫🇷',
            'order_index' => 2
        ],
    ];
});
```

**Note:** Add this filter BEFORE activating the plugin for the first time.

## License

GPL v2 or later
