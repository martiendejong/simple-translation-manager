# Editor's Guide — Multilanguage Features

A step-by-step guide for **content editors and site administrators** who publish
or maintain multilingual content with the Simple Translation Manager plugin.

No coding knowledge is required. If you can use the WordPress post editor and
the block/classic editor, you can translate content with this plugin.

> **Who this is for:** WordPress editors, authors, and administrators.
> **Not covered here:** theme integration, REST API, WP-CLI — see
> [`../API.md`](../API.md) and [`../CLI-COMMANDS.md`](../CLI-COMMANDS.md).

---

## Table of Contents

1. [Basic Concepts](#1-basic-concepts)
2. [Adding Translations to a Post or Page](#2-adding-translations-to-a-post-or-page)
3. [Translating Custom Fields (ACF / Pods) & Page Builders (Elementor)](#3-translating-custom-fields-acf--pods--page-builders-elementor)
4. [Best Practices](#4-best-practices)
5. [Where to Go Next](#5-where-to-go-next)

For problems, see [`TROUBLESHOOTING.md`](./TROUBLESHOOTING.md).

---

## 1. Basic Concepts

Before you translate your first post, it helps to understand three ideas the
plugin is built around: **languages**, **translation groups**, and
**language detection**.

### 1.1 Languages

The plugin ships with **English** and **Dutch** enabled by default. Any number
of additional languages can be configured under
**WP Admin → Translations → Languages**.

Each language has:

| Field | Example | Purpose |
|---|---|---|
| Code | `en`, `nl`, `fr` | Internal ID used in URLs and the database |
| Name | `English` | Label shown in admin menus |
| Native name | `Español` | Label shown in language switchers |
| Flag emoji | 🇪🇸 | Optional visual in the switcher |
| Default | ✅ / ⬜ | One language must be marked as default |

> **Screenshot placeholder** — `screenshots/01-languages-list.png`
> _Caption: The **Languages** screen lists every language, its flag, code, and
> which one is the default._

### 1.2 Translation Groups

A **translation group** is an invisible link that ties together all versions
of the same piece of content.

Example: you write a post in English called **"About Us"**. You then add a
Dutch translation called **"Over Ons"** and a French translation called
**"À Propos"**. The plugin assigns all three a shared translation group ID
(a UUID), so it knows they represent the same page in three languages.

Translation groups are created **automatically** the first time you save a
translation. You never need to manage them manually.

**What the translation group enables:**

- The language switcher on the frontend can jump between the three versions.
- If a visitor requests the French version of "About Us", WordPress knows to
  serve the French post instead of the English one.
- Editing any one of them keeps the link intact — you don't need to
  re-associate them.

### 1.3 Language Detection & URL Structure

When a visitor loads your site, the plugin decides which language to show in
this priority order:

1. **`?lang=` URL parameter** — highest priority, e.g. `/about/?lang=nl`
2. **Session** — a temporary memory for the current browser session
3. **Cookie** — remembers the visitor's choice for 30 days
4. **Default language** — the one marked as default in **Languages**

**URL patterns you will see:**

| URL | Language served |
|---|---|
| `https://example.com/about/` | Default language (typically English) |
| `https://example.com/about/?lang=nl` | Dutch |
| `https://example.com/about/?lang=fr` | French |

> **Screenshot placeholder** — `screenshots/02-url-switching.png`
> _Caption: The same post served in English and Dutch by appending `?lang=nl`._

A language switcher widget/shortcode handles this for visitors automatically —
they click a flag, and the `?lang=` parameter is appended for them.

---

## 2. Adding Translations to a Post or Page

This is the everyday workflow. It works for **posts, pages, and any public
custom post type** on your site.

### 2.1 Step-by-Step: Translate an Existing Post

**Step 1 — Open the post in the editor**

Go to **Posts → All Posts** (or **Pages → All Pages**) and click the post you
want to translate. A new column called **Language** shows you the current
language of each post at a glance.

> **Screenshot placeholder** — `screenshots/03-posts-list-language-column.png`
> _Caption: The **Language** column on the All Posts screen — look for the
> flag and language code next to each post title._

**Step 2 — Locate the "Translations" meta box**

Scroll down below the main editor area. You will see a meta box titled
**"Translations"**.

> **Screenshot placeholder** — `screenshots/04-translations-metabox.png`
> _Caption: The Translations meta box sits below the content editor. The
> top of the box shows the current post's language._

**Step 3 — Confirm the post's current language**

At the top of the meta box, the field **"This post is written in:"** shows the
current language. If the default is wrong (e.g. you wrote the post in Dutch
but the dropdown says English), correct it here before adding translations.

> **Screenshot placeholder** — `screenshots/05-current-language-selector.png`
> _Caption: Set the source language correctly — translations are added
> **into other languages**, so this dropdown tells the plugin what the
> original is._

**Step 4 — Click a language tab**

Below the source-language selector, you see one tab per additional language —
e.g. **🇳🇱 Dutch**, **🇫🇷 French**. Click the tab for the language you want
to translate **into**.

> **Screenshot placeholder** — `screenshots/06-language-tabs.png`
> _Caption: One tab per target language. The active tab is highlighted._

**Step 5 — Fill in the translated fields**

Each tab contains four fields:

| Field | What to enter |
|---|---|
| **Title** | The translated post title |
| **Slug** | The URL-friendly slug in that language (e.g. `over-ons`) |
| **Excerpt** | The translated short description |
| **Content** | The full translated body — uses the rich text editor |

> **Screenshot placeholder** — `screenshots/07-translation-tab-fields.png`
> _Caption: All four fields for a target language, including a rich-text
> editor for the content body._

Leave a field empty if you don't have a translation yet — see
[Partial Translations](#43-partial-translations) in Best Practices.

**Step 6 — Click Update (or Publish)**

Click WordPress's normal **Update** or **Publish** button at the top right.
The translations are saved at the same time as the rest of the post.

**Step 7 — Verify on the frontend**

Open the post on the frontend, then append `?lang=<code>` to the URL to check
the translated version. Example: `https://example.com/about/?lang=nl`.

### 2.2 Creating a New Post in a Non-Default Language

You can also author a post directly in, say, French:

1. Create the post as usual with **Add New**.
2. In the Translations meta box, change **"This post is written in:"** to
   **French**.
3. Add translations into English, Dutch, etc. in the other tabs.
4. Publish.

The post's primary language will be French; other languages become the
translations.

### 2.3 Translating Categories, Tags, and Taxonomies

Category and tag translations are edited from
**WP Admin → Translations → Strings**, or by editing the term itself when
the plugin's term-translation fields are present. The frontend automatically
swaps term names and slugs based on the current language.

---

## 3. Translating Custom Fields (ACF / Pods) & Page Builders (Elementor)

The Simple Translation Manager plugin translates the **core WordPress post
fields** — title, slug, excerpt, and `post_content` — out of the box. It does
not ship with dedicated integrations for ACF, Pods, or Elementor, but there
are two supported approaches for content editors:

### 3.1 Approach A — One Full Translation Per Language (Recommended)

This is the simplest approach and works for **every** field type, including
Elementor layouts and ACF repeaters.

1. Create the original post (e.g. in English) with all its ACF fields,
   Pods fields, or Elementor layout filled in.
2. In the Translations meta box, click the tab for the target language.
3. In the **Content** field for that language, paste the translated
   equivalent. For Elementor-built pages this usually means creating a
   **separate page** (see Approach B).
4. Update.

> **Tip:** If you use Elementor extensively on a page, treat each language as
> its own page linked through the translation group — see Approach B below.

### 3.2 Approach B — Separate Pages Joined by a Translation Group

For Elementor-heavy pages or complex ACF layouts, it is often cleaner to
create one WordPress page per language and let the translation group link
them.

1. Build the original page in Elementor (e.g. `/about/` in English).
2. Create a new page for each target language — e.g. `/over-ons/` for Dutch,
   `/a-propos/` for French. Design each page in that language's Elementor
   canvas.
3. On each page's Translations meta box:
   - Set **"This post is written in:"** to that page's language.
   - The plugin's admin tooling (**Translations → Strings**) lets admins
     assign them to the same translation group so the language switcher
     links them correctly.

> **Screenshot placeholder** — `screenshots/08-separate-pages-strategy.png`
> _Caption: Three separate pages — one per language — joined by a shared
> translation group. The language switcher on the frontend moves the visitor
> between them._

### 3.3 ACF / Pods Field Notes

- **Text, textarea, WYSIWYG fields** — translate by duplicating the page
  (Approach B) and re-entering the field content in the target language.
- **Image, number, date, true/false fields** — usually don't need
  translation; leave them identical.
- **Repeater / flexible content** — Approach B is strongly recommended;
  repeater data is stored per-post and is not auto-translated.
- **Shared field groups** — if you use ACF options pages, their values are
  global and not translated by this plugin. Contact your developer for an
  options-page translation strategy.

### 3.4 Elementor-Specific Notes

- Elementor stores its layout in a single `_elementor_data` meta field as
  JSON. Translating it reliably requires **Approach B** — a separate page
  per language.
- Templates and global widgets are **not** automatically translated. Use
  Elementor's own template library to create per-language copies if needed.

---

## 4. Best Practices

### 4.1 When Should I Create a Translation?

Create a translation when **the visitor experience is better with the
translated version than with the original**. Common triggers:

- You publish content in your site's default language and have staff who
  can translate it reliably.
- You run paid ads or landing pages targeting a specific language/region.
- Legal/marketing copy must exist in every language you serve.

You do **not** need a translation for:

- Short-lived posts (news flashes, internal announcements).
- Content that is identical in every language (e.g. a product image gallery
  with no text).

### 4.2 Keep Slugs Localized

Use localized slugs — e.g. `/over-ons/` instead of `/about/?lang=nl`.
Localized slugs help SEO and are easier for visitors to recognize and share.

### 4.3 Partial Translations

A post does not need to be fully translated in every language. If a field
(title, excerpt, slug, content) is empty for a given language, the frontend
**falls back to the original-language content** for that field.

**Practical consequence:** you can launch with title + excerpt translated and
fill in the full content later. Visitors will still see your translated title
on listing pages.

### 4.4 Review in the Frontend After Every Save

After publishing a translation, always open the page with `?lang=<code>`
appended and verify:

- The title appears in the correct language.
- The slug is what you entered.
- The content is complete and correctly formatted.
- The language switcher moves between translations correctly.

### 4.5 Use the Translations Dashboard for Bulk Work

**Translations → Strings** gives you a searchable list of every translatable
string on the site. It is the fastest way to:

- Spot missing translations (empty rows).
- Update a string globally (e.g. changing "Home" → "Dashboard" in all
  languages).
- Import/export translations in JSON via **Import/Export**.

> **Screenshot placeholder** — `screenshots/09-strings-dashboard.png`
> _Caption: The Strings dashboard — search, filter by context, and inline-edit
> any translation._

### 4.6 Keep One Editor per Language Where Possible

If your team is large, assigning one editor per language keeps tone and
terminology consistent across translations. The plugin doesn't enforce this,
but the Language column on the Posts list makes it easy to filter each
editor's workload.

### 4.7 Clear Cache After Bulk Edits

If your site uses a caching plugin (WP Rocket, W3 Total Cache, LiteSpeed
Cache, object cache via Redis, etc.), flush the cache after a large batch of
translation edits. See [`TROUBLESHOOTING.md`](./TROUBLESHOOTING.md#cache-issues).

### 4.8 Don't Use Machine Translation Without Review

The plugin supports programmatic translation import, but machine-translated
content should always be reviewed by a human speaker before publishing.
Search engines penalize low-quality auto-translations.

---

## 5. Where to Go Next

- **Something isn't working?** → [`TROUBLESHOOTING.md`](./TROUBLESHOOTING.md)
- **Developer integration (theme / REST API)** → [`../API.md`](../API.md)
- **Bulk imports via WP-CLI** → [`../CLI-COMMANDS.md`](../CLI-COMMANDS.md)
- **Overall plugin overview** → [`../../README.md`](../../README.md)
- **Detailed blog translation reference** → [`../../README-BLOG.md`](../../README-BLOG.md)

---

## Appendix A — Screenshots & Video

All screenshots referenced above live in `./screenshots/`. File naming
convention: `NN-short-description.png` (two-digit prefix for ordering).

A **5–10 minute video walkthrough** of the end-to-end editor workflow is a
deliverable for this documentation pack. Once recorded, place it at
`./screenshots/editor-walkthrough.mp4` (or link to an internal video-hosting
URL from this section). Suggested chapter markers:

| Time | Chapter |
|---|---|
| 0:00 | Languages screen tour |
| 1:00 | Translating your first post |
| 3:00 | Adding a second translation and verifying on the frontend |
| 4:30 | Strings dashboard and bulk workflow |
| 6:00 | Elementor/ACF considerations |
| 8:00 | Troubleshooting and cache clearing |

A PDF version of this guide can be generated with any markdown-to-PDF tool
(e.g. `pandoc EDITORS_GUIDE.md -o editors-guide.pdf`) and distributed to
non-technical stakeholders who prefer offline reading.
