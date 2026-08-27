=== Simple Translation Manager ===
Contributors: martiendejong
Tags: translation, multilingual, i18n, language switcher, rest api
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight multilingual WordPress plugin with database storage, REST API, and built-in caching.

== Description ==

Simple Translation Manager adds multilingual support to any WordPress site without relying on
per-language content duplication in the file system. All translations are stored in dedicated
database tables and served through WordPress's own object cache for fast lookups.

**Features**

* Database storage — all translations live in custom database tables, not post meta soup
* WordPress caching — built-in object cache support for performant lookups
* REST API — full API for programmatic translation management
* Admin interface — search, pagination, and inline editing of translated strings
* Post translations — support for translating posts, pages, and custom post type fields
* Elementor integration — translate Elementor widget content (including templates and global widgets) per language, with an in-editor translation panel
* Clean language URLs — `/en/`, `/nl/`, and friends via real URL routing (with hreflang output)
* Fully generic — works with any WordPress theme or site, no hard-coded languages

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Translations → Languages** to configure the languages you need.
4. Go to **Translations → Strings** to add or edit translations.

By default, the plugin installs English and Dutch. To use a different set of languages on first
activation, add a filter to your theme's `functions.php` *before* activating the plugin:

`
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
    ];
});
`

== Frequently Asked Questions ==

= Where are translations stored? =

In dedicated custom database tables created on activation, not in post meta or the file system.
Reads are served through WordPress's object cache.

= Does this work with Elementor? =

Yes. Elementor widget content, including templates and global widgets, can be translated per
language directly from an in-editor translation panel.

= Can I change the default languages? =

Yes, use the `stm_default_languages` filter before first activation, or manage languages any
time from **Translations → Languages** in the admin.

= Does it support clean language URLs? =

Yes. The plugin provides real URL routing (for example `/en/`, `/nl/`) along with hreflang
output for search engines.

== Screenshots ==

1. Translations admin screen with search, pagination, and inline editing.
2. Elementor in-editor translation panel.

== Changelog ==

= 1.2.1 =
* Fixed a duplicate `Set-Cookie` header on the public search endpoint that caused intermittent
  502 responses when a language parameter was present.

= 1.2.0 =
* Added deploy-time version tracking.

= 1.1.0 =
* Auto-translate button, save toast, and inline translation UI polish.
* Translation dashboard with coverage, missing-translation list, and CSV export.
* Hreflang injection and true URL routing.
* SEO God integration.

= 1.0.0 =
* Initial release: database-backed translation storage, REST API, admin interface, bulk
  translation API, WP-CLI commands, and generic multi-language support.

== Upgrade Notice ==

= 1.2.1 =
Fixes an intermittent 502 error on the public search endpoint when a language is selected.
