# Troubleshooting — Multilanguage Features

Practical fixes for the most common problems content editors hit when working
with translations. Each section lists **symptoms**, then **what to check**
from quickest to slowest.

If nothing here resolves your issue, collect:

1. The URL that's misbehaving (including the `?lang=` parameter if any).
2. A screenshot of the Translations meta box for the post in question.
3. Your browser and WordPress version.

Send them to your developer or plugin maintainer.

---

## Table of Contents

1. [Missing Translations](#1-missing-translations)
2. [Wrong Language is Served](#2-wrong-language-is-served)
3. [Cache Issues](#3-cache-issues)
4. [Language Switcher Problems](#4-language-switcher-problems)
5. [Meta Box Not Showing](#5-meta-box-not-showing)
6. [URL & Slug Problems](#6-url--slug-problems)
7. [ACF / Pods / Elementor Content Not Translating](#7-acf--pods--elementor-content-not-translating)
8. [Permissions & Saving Errors](#8-permissions--saving-errors)
9. [Getting More Help](#9-getting-more-help)

---

## 1. Missing Translations

### Symptom

A translation you saved doesn't appear on the frontend. Instead the visitor
sees the original-language content (or nothing at all) when using
`?lang=<code>`.

### Checklist

1. **Confirm the source language of the post.**
   Open the post. In the **Translations** meta box, is
   **"This post is written in:"** set to the correct original language?
   If it says `English` but you wrote the post in Dutch, the plugin thinks
   your Dutch version **is** the original, and there is no translation for
   English to serve.
2. **Confirm the target-language tab was saved.**
   Switch to the target language tab (e.g. `🇳🇱 Dutch`) and verify the Title,
   Slug, Excerpt, and Content fields contain your translation. Empty fields
   fall back to the original post — that's by design
   ([partial translations](./EDITORS_GUIDE.md#43-partial-translations)).
3. **Did you click Update / Publish after filling in the tab?**
   Translations save with the rest of the post. Clicking a tab without
   clicking **Update** loses your input.
4. **Is the `?lang=` code correct?**
   Language codes are case-sensitive and must match exactly what is in
   **Translations → Languages**. `NL` is **not** the same as `nl`.
5. **Is the visible language actually different from the default?**
   If English is default and you're viewing the English post without
   `?lang=nl`, you'll see the English version — which is the desired
   behavior.

### Fix

- Re-open the target-language tab, re-enter the fields, and click **Update**.
- If the Language column on **Posts → All Posts** shows the wrong flag,
  correct the source language selector first, then re-save.

---

## 2. Wrong Language is Served

### Symptom

You visit `/about/?lang=fr` but see the English version. Or you visit the
homepage and get Dutch even though you expected English.

### Checklist

1. **Check the URL for a stray `?lang=` parameter.**
   The plugin uses `?lang=` as the highest-priority signal. If the URL
   contains `?lang=en`, the visitor gets English regardless of cookie or
   session.
2. **Clear your session and cookie.**
   The plugin remembers a visitor's language choice in:
   - A session variable (cleared when the browser closes).
   - A cookie called `stm_lang` (30-day lifetime).

   To test a fresh visitor experience, open an **incognito / private
   browsing** window, or clear cookies for your domain.
3. **Confirm a translation exists for that language.**
   If the post has no French translation, the plugin falls back to the
   original language. That's expected — add the translation, then retry.
4. **Confirm the language is active in Translations → Languages.**
   A language that has been removed or deactivated won't be served.

### Fix

- Clear cookies for your site, then retry with a fresh browser.
- If a translation is missing, add it via the Translations meta box.

---

## 3. Cache Issues

### Symptom

You updated a translation, but the frontend still shows the old version. Or
changes appear inconsistently depending on which page you visit.

### What's happening

WordPress and most hosting stacks layer multiple caches:

- **Browser cache** (your own browser).
- **Page cache** (WP Rocket, W3 Total Cache, LiteSpeed Cache, Cloudflare APO,
  host-level Varnish/Nginx page caching).
- **Object cache** (Redis / Memcached — the plugin uses this internally).
- **CDN edge cache** (Cloudflare, BunnyCDN, etc.).

A stale translation is almost always one of these, not the plugin itself.

### Checklist & Fix

1. **Hard-reload your browser.** Windows: `Ctrl + F5`. macOS: `Cmd + Shift + R`.
2. **Flush your WordPress object cache.**
   If you have a caching plugin, use its "Clear all cache" button.
   On servers with Redis: ask your developer/host to run
   `wp cache flush` via WP-CLI.
3. **Purge your page cache.**
   Each caching plugin has a "Purge all pages" option. Do this after bulk
   translation updates.
4. **Purge your CDN.**
   Cloudflare: **Caching → Configuration → Purge Everything** (or purge
   by URL).
5. **Verify with a URL-busting query parameter.**
   Append a random `&cb=123` to the URL to bypass most page caches:
   `/about/?lang=nl&cb=123`. If the new content appears now, the problem
   was cache, not the translation.

### Prevention

- After every batch of translation edits, purge the caches above.
- Ask your developer to add a cache-purge hook so the plugin's own object
  cache is flushed when a translation saves (the plugin does this internally
  for WordPress's object cache, but third-party plugins need their own hook).

---

## 4. Language Switcher Problems

### Symptom

- The switcher doesn't appear.
- The switcher lists languages, but clicking them doesn't change the page.
- The switcher points to the wrong translation.

### Checklist

1. **Widget placement.**
   Go to **Appearance → Widgets** and confirm the **Language Switcher**
   widget is in a visible sidebar or widget area. Some themes only show
   specific sidebars on specific templates.
2. **Shortcode syntax.**
   In a post or page, the shortcode must be exactly:
   `[stm_language_switcher]`, `[stm_language_switcher style="dropdown"]`, or
   `[stm_language_switcher style="flags"]`.
3. **At least two active languages exist.**
   The switcher hides itself if there is only one language.
4. **The current page has a translation group.**
   If the page has never been saved with the Translations meta box, it may
   not belong to a translation group, so the switcher cannot link it to its
   peers. Save the post once to create the group automatically.
5. **Switcher points to wrong page.**
   If clicking "French" lands on the English page, the French post is likely
   missing from the translation group. Open each language's version and
   verify they share the same group — the simplest way is to save each
   translation from the original post's Translations meta box (instead of
   editing each page separately).

---

## 5. Meta Box Not Showing

### Symptom

The **Translations** meta box is missing when editing a post.

### Checklist

1. **Post type must be public.**
   The plugin only adds the meta box to public post types. Private /
   internal CPTs won't see it. Ask your developer to mark the CPT `public`
   or register it against the plugin.
2. **Screen options.**
   Click **Screen Options** at the top right of the editor and confirm
   **Translations** is checked.
3. **User role / capability.**
   Users without the `edit_posts` capability (e.g. Subscribers) won't see
   the meta box. Assign the user a role of Author or higher.
4. **Plugin conflict.**
   Deactivate other translation / multilingual plugins (WPML, Polylang,
   TranslatePress). Running multiple multilingual plugins at once is not
   supported and often hides meta boxes from each other.
5. **Block editor vs. classic editor.**
   The Translations meta box renders in both, but some block-editor
   extensions collapse meta boxes by default. Scroll all the way down or
   check the three-dots menu → **Options → Advanced panels**.

---

## 6. URL & Slug Problems

### Symptom

- `/about/?lang=nl` works but `/over-ons/` gives a 404.
- Pretty permalinks don't reflect the translated slug.
- A translated post shares the same slug as its original.

### Checklist

1. **Permalinks flushed?**
   Go to **Settings → Permalinks** and click **Save Changes** (no other
   change needed). This re-registers rewrite rules.
2. **Translated slug entered?**
   Open the post → Translations meta box → target language tab → confirm
   the **Slug** field is filled in. An empty translated slug means the
   post has no URL of its own in that language.
3. **Slug collision with another post.**
   Two posts can't share the same slug in the same post type. If you enter
   `over-ons` and another post already uses it, WordPress will silently
   append `-2`. Check the actual saved slug after Update.
4. **Per-language URL routing setting.**
   Under **Translations → Settings**, pretty language URLs (`/en/`, `/nl/`)
   may or may not be enabled depending on your setup. If they are disabled,
   all translations use the `?lang=` query parameter and there is no
   `/over-ons/` URL — only `/about/?lang=nl`.

---

## 7. ACF / Pods / Elementor Content Not Translating

### Symptom

The post's title and body are translated, but ACF fields / Pods fields /
Elementor widgets still show the original language.

### Why

This plugin translates core WordPress post fields (title, slug, excerpt,
content) natively. Custom-field plugins and page builders store their data
in **separate meta fields** that are outside this plugin's translation
scope.

### Fix (editor approach)

Use the **separate pages joined by a translation group** strategy described
in [`EDITORS_GUIDE.md` §3.2](./EDITORS_GUIDE.md#32-approach-b--separate-pages-joined-by-a-translation-group):

1. Create one page per language.
2. Build the Elementor / ACF layout natively in each page.
3. Link them via the Translations meta box so the language switcher knows
   about all versions.

### Fix (developer approach)

If you need ACF / Pods field translation without duplicating pages, ask your
developer to add a custom bridge between those plugins and
`STM\Database` / `STM\PostEditor`. See [`../API.md`](../API.md) for the
available hooks.

---

## 8. Permissions & Saving Errors

### Symptom

- "You do not have permission to edit this translation."
- "Nonce verification failed."
- Changes silently revert after clicking Update.

### Checklist

1. **Re-login.**
   Nonce and session errors usually mean your WordPress login expired
   in another tab. Log out and log back in, then retry.
2. **User role.**
   Translation editing requires `edit_posts` at minimum. Importing JSON
   requires `manage_options` (Administrator).
3. **Browser extensions.**
   Aggressive privacy extensions (some ad-blockers, uBlock Origin custom
   rules) can strip the nonce from the save request. Try in an incognito
   window with extensions disabled.
4. **Server error logs.**
   If saving genuinely fails (500 error, blank screen), ask your
   developer to check `wp-content/debug.log` or the host's PHP error log
   for a stack trace.

---

## 9. Getting More Help

### Self-service

1. Re-read [`EDITORS_GUIDE.md`](./EDITORS_GUIDE.md) — most "it doesn't work"
   questions are answered in the Best Practices section.
2. Skim [`../../README-BLOG.md`](../../README-BLOG.md) for the end-to-end
   translation flow.
3. Check the browser's developer console (F12 → Console tab) for red error
   messages — copy them verbatim if you escalate.

### Escalating to developers

When opening a ticket or contacting the plugin maintainer, include:

- **URL** where the issue occurs (with `?lang=` if relevant).
- **Post ID** of the post you were editing.
- **Expected behavior** and **actual behavior**.
- **Steps to reproduce** from a logged-out or incognito window.
- **Screenshot** of the Translations meta box.
- **Browser & WordPress version.**
- **Other active plugins** (especially other caching or multilingual plugins).

### Developer references

- REST API: [`../API.md`](../API.md)
- WP-CLI: [`../CLI-COMMANDS.md`](../CLI-COMMANDS.md)
- Plugin source: `includes/class-post-editor.php`, `includes/class-frontend.php`
