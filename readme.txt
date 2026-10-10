=== Simple Translation Manager ===
Contributors: martiendejong
Tags: translation, multilingual, i18n, language switcher, rest api
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.2
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
* Per-language XML sitemaps — one sitemap per active language, built on the WordPress core sitemaps
* Translation memory, XLIFF and PO import/export, a coverage dashboard and WP-CLI commands for bulk work
* Optional auto-translate with OpenAI or DeepL — off until you enter your own API key (see "External services")
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

= Which REST API routes are public? =

Only read access to the translated content of your public site: the language list, the string
and translation lists, and the translations and slugs of **published** posts. Everything that
creates, changes, deletes, imports, exports or auto-translates requires an administrator.
Translations of drafts, private and password-protected posts are only returned to users who can
edit that post.

= Does it send my content to other services? =

Only if you turn on auto-translate by entering an OpenAI or DeepL API key and then use an
auto-translate button. See "External services" below. Without a key the plugin makes no
requests to any other site.

= What happens to my translations when I update or uninstall? =

Updating the plugin keeps all translations. Deactivating keeps them too. Deleting the plugin
removes its tables and settings unless "Keep data on uninstall" is ticked in the plugin settings.

== External services ==

This plugin can send text to a third-party translation service. It does so only when an
administrator has saved an API key for that service (Translations → Settings) and then uses an
auto-translate button in the post editor or on the Field Values screen, or calls the
administrator-only REST route `/wp-json/stm/v1/translate/auto`. Matches from your own translation
memory are used first and are never sent anywhere.

= OpenAI =

Used when the provider is set to OpenAI. For each translation the plugin sends the text to
translate, the names of the source and target language, the name of the field being translated
(for example "post_title"), your custom prompt template if you set one, and your API key, to
`https://api.openai.com/v1/chat/completions`.

* Terms of use: https://openai.com/policies/terms-of-use/
* Privacy policy: https://openai.com/policies/privacy-policy/

= DeepL =

Used when the provider is set to DeepL. For each translation the plugin sends the text to
translate, the source and target language code, and your API key, to
`https://api-free.deepl.com/v2/translate` (keys ending in `:fx`) or
`https://api.deepl.com/v2/translate`.

* Terms of use: https://www.deepl.com/pro-license
* Privacy policy: https://www.deepl.com/privacy

== Privacy ==

* Translations, language settings and post-to-language links are stored in your own database.
* When a visitor picks a language with the language switcher or a `?lang=` link, the plugin
  sets one cookie named `stm_lang` (the chosen language code, 30 days) to remember the choice.
  It contains no personal data.
* API keys for auto-translate are stored in your site's options table and are only sent to the
  service they belong to.
* The plugin does not track visitors and does not load scripts, fonts or images from other sites.

== Screenshots ==

1. Translations admin screen with search, pagination, and inline editing.
2. Elementor in-editor translation panel.

== Changelog ==

= 1.3.2 =
* WordPress.org readiness: checked with the official Plugin Check (static and runtime checks).
  Input from forms and request parameters is now unslashed and sanitised everywhere, all
  database queries are prepared statements, admin redirects use `wp_safe_redirect()`, and the
  readme documents the external services and the public REST routes.
* Fixed: the public REST routes for post translations and post slugs returned the translations of
  drafts, private and password-protected posts to anyone. They are now only returned for
  published posts, or to users who can edit the post.
* Fixed: uninstalling the plugin (with "Keep data on uninstall" off) left three of its tables
  behind and did not remove its settings and API keys.
* Hreflang: an alternate language is only advertised when translated content exists, Dutch
  posts no longer declare themselves as English, and category and subcategory archives get their
  translated alternates.
* The minimum WordPress version is now 6.0 (the sitemap integration needs WordPress 5.5 or newer).

= 1.3.1 =
* Fixed the nonce check on the Field Values admin forms.

= 1.3.0 =
* Per-language XML sitemaps.
* Language-prefix routing for custom post types, translated slugs, and localised permalinks.
* Value-translatable fields: shared translations for standardised values, with a Field Values screen.
* Filter on missing translations in the Translation Strings screen, and the default-language text
  as placeholder for missing translations.
* Inactive languages can be shown, reactivated and edited by administrators.
* Auto-translate shows the real error from the provider instead of a generic message.

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

= 1.3.2 =
Security fix: translations of draft and private posts are no longer readable through the public
REST API. Requires WordPress 6.0 or newer.

= 1.2.1 =
Fixes an intermittent 502 error on the public search endpoint when a language is selected.
