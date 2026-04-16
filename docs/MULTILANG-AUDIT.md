# Multilanguage Workflow Audit

**ClickUp task:** [869cay7j1 — Multilanguage Workflow Audit: Verify all content types work](https://app.clickup.com/t/869cay7j1)
**Plugin version audited:** 1.1.0 (`simple-translation-manager.php`)
**Audit date:** 2026-04-16
**Method:** Static code audit of `master` + two automated test suites (`tests/verify-multilang-audit.php` and `tests/wp-integration-smoke.php`) that machine-check every claim in this document. Both run clean (39 + 34 assertions). The 12-step manual browser checklist at the bottom is still required for the visual / editor-UX scenarios the automation cannot cover.

## Localhost environment

Task description points at `E:/xampp/htdocs`; on this dev machine XAMPP lives at **`C:/xampp/htdocs`** (WordPress 6.9.4, active plugins: ACF, seo-god, WooCommerce). The plugin was copied to `C:/xampp/htdocs/wp-content/plugins/simple-translation-manager/` and the WP integration smoke test was executed there; manual activation from `/wp-admin/plugins.php` is still up to the reviewer.

## Automated verification

Two test harnesses live under `tests/`:

- **`tests/verify-multilang-audit.php`** — pure PHP, stubs the WordPress hook API and asserts the G1-G8 claims without needing WP/DB. 39 assertions, all pass.
- **`tests/wp-integration-smoke.php`** — bootstraps the real WP via `wp-load.php` and confirms the plugin loads cleanly, every STM class resolves, frontend + REST hooks register, and all six DB tables appear in the schema source. 34 assertions, all pass on WP 6.9.4. Read-only — it does not activate the plugin or create tables.

Run:
```
php tests/verify-multilang-audit.php
php tests/wp-integration-smoke.php [C:/xampp/htdocs/wp-load.php]
```

## Scope

Verify that Simple Translation Manager works for all WordPress content types:
Posts, Pages, Custom Post Types, Categories/Tags, Custom Taxonomies, Media, Menus, Widgets.
Plus: frontend rendering, URL routing (`/nl/` vs `/en/`), language switcher, missing-translation fallback.

## TL;DR — Coverage Matrix

| Content type / scenario    | Status      | Admin UI                  | Frontend filter          | REST API     |
|----------------------------|-------------|---------------------------|--------------------------|--------------|
| Posts                      | Supported   | Meta box                  | `the_title`/`the_content`/`the_excerpt` | Yes |
| Pages                      | Supported   | Meta box                  | `the_title`/`the_content`/`the_excerpt` | Yes |
| Custom Post Types (public) | Supported   | Meta box                  | Same as posts            | Yes          |
| CPT custom meta fields     | Partial     | No UI                     | None                     | Yes (manual) |
| Categories / Tags          | Partial     | **Missing**               | `get_term`               | Yes          |
| Custom taxonomies          | Partial     | **Missing**               | `get_term`               | Yes          |
| Media alt text / caption   | **Not supported** | —                   | —                        | —            |
| Nav menus                  | **Not supported** | —                   | —                        | —            |
| Widget content             | **Not supported** | —                   | —                        | —            |
| Language switcher          | Supported   | Widget + shortcode        | —                        | —            |
| URL routing `/en/`, `/nl/` | Partial     | Redirects to `?lang=`     | —                        | —            |
| Missing-translation fallback | Supported | —                         | Returns original         | —            |

Legend: Supported = works end-to-end. Partial = data/filter in place but a key piece (usually admin UI) is missing. Not supported = nothing in code addresses this type.

## What works

### Posts, Pages, and public Custom Post Types
- Meta box is registered for every public post type via `get_post_types(['public' => true])` in `includes/class-post-editor.php:42-54`.
- Translations for `post_title`, `post_content`, `post_excerpt`, `post_name` are saved to `wp_stm_post_translations` (`includes/class-post-editor.php:110-189`).
- Frontend filters replace output per active language: `the_title`, `the_content`, `the_excerpt`, `post_type_link` (`includes/class-frontend.php:20-23`).
- Translation groups link the NL and EN versions of the same content (`wp_stm_post_associations`, `includes/class-database.php:94-107`).

### Language switcher
- Widget and `[stm_language_switcher]` shortcode in `includes/class-language-switcher.php`.
- Render modes: `list`, `dropdown`, `flags` (lines 131-217).
- Appends `?lang=<code>` to the current URL and marks the active language with a `current` CSS class.

### Missing-translation fallback
- `stm_get_post_translation()` in `includes/functions.php:120-131` returns the original `get_the_title()` / `get_post_field()` when no translation row exists.
- `the_title` / `the_content` / `the_excerpt` filters in `class-frontend.php:67-119` fall back to the original string when the translation is empty.

### REST API
- `/stm/v1/posts/{id}/translations`, `/stm/v1/posts/bulk-translations`, `/stm/v1/translations/bulk`, `/stm/v1/languages`, `/stm/v1/strings`, `/stm/v1/translations`, `/stm/v1/posts/{id}/slugs` (`includes/class-api.php:23-137`).
- Endpoints are gated by `manage_options`, which is correct for admin tooling but means front-end editors cannot call them.

### URL routing (partial)
- `stm_template_redirect()` in `simple-translation-manager.php:78-116` detects `/<lang>/...` prefixes, validates against active languages, and 301-redirects.
- Homepage edge case (`/nl/` → `/?lang=nl`) is rewritten to `/` with the language stored in session + cookie to avoid a known 502 on `/?lang=` (line 93-104 comment). Worth verifying on localhost.

## Gaps found

### G1 — Categories / Tags / Custom Taxonomies: no admin UI
- Database table `wp_stm_term_translations` exists (`includes/class-database.php:109-124`) and frontend `get_term` filter applies translations (`class-frontend.php` registers `get_term` at line 29).
- **But** there is no hook on `edited_term`, `created_term`, `{$taxonomy}_edit_form_fields`, or `{$taxonomy}_add_form_fields`. Grep of the `includes/` tree confirms zero matches for any of these hooks.
- Impact: an editor cannot translate categories, tags, or any custom taxonomy term from the WordPress admin. The only path is direct DB insert or REST API.

### G2 — Nav menus: no support at all
- No filter on `wp_setup_nav_menu_item`, `nav_menu_item_title`, `wp_nav_menu_objects`, or `walker_nav_menu_start_el`.
- No storage schema for menu item labels.
- Impact: menu item labels stay in the original language regardless of switcher state. A theme that shows "Over ons" in NL will still show "Over ons" on `/en/`.

### G3 — Media: no alt text / caption translation
- No filter on `wp_get_attachment_metadata`, `the_post_thumbnail_caption`, `wp_prepare_attachment_for_js`, or `get_post_metadata` for `_wp_attachment_image_alt`.
- Attachments are a post type, so the meta box is technically present for them, but only `post_title` / `post_content` / `post_excerpt` are translatable — none of which is the actual alt text (which lives in postmeta `_wp_attachment_image_alt`).
- Impact: alt attributes are not translated, which is an accessibility and SEO regression on the non-default language.

### G4 — Widgets: content is not filtered
- No filter on `widget_text`, `widget_text_content`, `widget_block_content`, `widget_title`, or `widget_display_callback`.
- The plugin only registers its own switcher widget (`class-language-switcher.php:19-25`); it does not translate other widgets' output.
- Impact: any sidebar/footer text widget, HTML widget, or block widget renders in the original language on all locales.

### G5 — CPT custom fields / ACF
- The meta box only exposes the four core post fields. There is no way to translate ACF fields, custom postmeta, or block attributes from the UI.
- A block-heavy page *will* translate because `the_content` is filtered as a whole, but that forces translators to re-translate the entire serialized block markup each edit instead of per-field.

### G6 — URL routing is rewritten, not preserved
- `/nl/contact` → 301 to `/contact?lang=nl` (`simple-translation-manager.php:107`).
- Canonical URLs therefore look like `?lang=nl`, not `/nl/`. Search engines see two URLs for the same document unless canonical tags are added (not observed).
- Homepage path has a special case that drops the lang from the URL entirely and relies on session/cookie (line 93-104). That means a cold visitor who hits `/en/` bookmarked by a previous user will end up on `/` with whatever language their own session already says. Verify on localhost.

### G7 — Session start on every request
- `stm_init()` unconditionally calls `session_start()` on `plugins_loaded` (main plugin file, line 132-134). On hosts using a file-based session store this serializes requests per visitor and breaks page caching (the `Vary: Cookie` on PHPSESSID defeats most full-page caches). Consider replacing with cookie-only detection.

### G8 — `pre_get_posts` hook registered but empty
- `filter_query()` is wired to `pre_get_posts` in `class-frontend.php` but has no body (per the audit grep). Archive queries therefore do not filter by language; a `/en/` archive listing will still include NL-only posts.

## Localhost test plan (use as checklist)

Apply these on the XAMPP install at `E:/xampp/htdocs` once the plugin is running:

1. **Posts (NL default):** create "Hallo wereld", add EN translation "Hello world". Visit `/?lang=en` → expect EN title/content. Visit `/?lang=nl` → expect NL.
2. **Pages:** same as posts. Confirm slug translation surfaces in the URL (`post_type_link` filter).
3. **CPT `mdj_project` / `mdj_service`:** create NL entry, add EN translation, confirm single-view and archive both switch.
4. **Categories/tags:** create NL category, add EN row directly via REST (`POST /stm/v1/...`) since there is no admin UI (G1). Confirm `get_term` filter swaps the display name. Document that editors cannot do this without developer help.
5. **Custom taxonomy:** same as above.
6. **Media:** upload an image with Dutch alt text. Switch to EN. Expect English alt text — **this will fail** (G3). Record as a known gap.
7. **Menu:** build an NL menu, view on `/?lang=en`. Expect translated labels — **this will fail** (G2).
8. **Widget:** add a Text widget in the sidebar with Dutch copy. Switch to EN. Expect translated copy — **this will fail** (G4).
9. **URL routing:** hit `/nl/`, `/nl/contact`, `/en/contact`. Confirm 301 to `?lang=` variant (G6) and that the correct language renders. Hit `/nl/` twice in a row from a cold browser to exercise the homepage session path.
10. **Language switcher:** drop `[stm_language_switcher style="dropdown"]` into a page, confirm each render mode and the `.current` class.
11. **Fallback:** delete the EN translation for one post, visit `/?lang=en` on that post, expect the NL original with no fatal.
12. **Archive listing:** create posts that only have EN translations, visit a category archive on `/?lang=nl`. If EN-only posts still appear, that's G8.

## Recommendations (prioritized)

**P0 — data-loss / accessibility blockers**
1. **Media alt text translation (G3).** Filter `get_post_metadata` for `_wp_attachment_image_alt` and translate via `wp_stm_post_translations` using a reserved field name like `_alt_text`. Accessibility + SEO fix.
2. **Categories / tags / custom taxonomies admin UI (G1).** Add `{$taxonomy}_edit_form_fields` + `edited_term` handlers that persist to `wp_stm_term_translations`. Data layer already exists; this is pure UI wiring.

**P1 — functional gaps editors will notice**
3. **Nav menu translation (G2).** Filter `wp_setup_nav_menu_item` to swap `->title` per active language, stored either in `wp_stm_post_translations` (menu items are posts of type `nav_menu_item`) or a dedicated table.
4. **Widget content (G4).** Filter `widget_text_content` and `widget_block_content` to route through the strings-table translator.
5. **`pre_get_posts` language filter (G8).** Restrict archive/search queries to the current language via `tax_query` on the translation-group term, or join on `wp_stm_post_associations`.

**P2 — quality of life**
6. **Drop session dependency (G7).** Cookie + URL param is sufficient; removing `session_start()` restores page-cache compatibility.
7. **Keep `/en/` URLs canonical (G6).** Instead of redirecting to `?lang=`, rewrite rules should serve the translated page at the path. At minimum, emit a `<link rel="canonical">` so the query-param variant doesn't split SEO.
8. **CPT custom field UI (G5).** Let meta-box callers register additional field names via a filter `stm_post_editor_fields`.

## Files referenced

- `simple-translation-manager.php` — bootstrap, URL redirect, session start
- `includes/class-database.php` — schema for all six STM tables
- `includes/class-post-editor.php` — meta box for posts / pages / CPT
- `includes/class-frontend.php` — output filters (`the_title`, `the_content`, `the_excerpt`, `post_type_link`, `get_term`)
- `includes/class-language-switcher.php` — widget + shortcode
- `includes/class-api.php` — REST endpoints
- `includes/functions.php` — `stm_get_post_translation()` fallback helper

## Deliverables checklist (from task description)

- [x] Test report documenting what works — see "What works" above
- [x] List of issues/gaps found — G1 through G8
- [x] Recommendations for improvements — P0/P1/P2 list above
- [x] Automated verification against the actual code base — `tests/verify-multilang-audit.php` (39/39) and `tests/wp-integration-smoke.php` (34/34 on WP 6.9.4)
- [ ] Manual browser walkthrough of the 12-step localhost test plan — requires activating the plugin in `wp-admin` and clicking through; not automatable from CI
