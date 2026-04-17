# Editor Documentation

Documentation for **content editors and WordPress administrators** who work
with the Simple Translation Manager plugin day-to-day.

| Document | When to use it |
|---|---|
| [**EDITORS_GUIDE.md**](./EDITORS_GUIDE.md) | Learn how translations work, how to add and publish them, and best practices |
| [**TROUBLESHOOTING.md**](./TROUBLESHOOTING.md) | Diagnose a specific problem (missing translation, cache, language switcher, etc.) |

## Available formats

The same two documents are shipped in three formats; pick whichever suits your workflow:

| Format | Location | Best for |
|---|---|---|
| Markdown (source of truth) | `./EDITORS_GUIDE.md`, `./TROUBLESHOOTING.md` | Reading on GitHub, editing, diffing |
| WordPress HTML + WXR import | [`./wordpress/`](./wordpress/) | Publishing the docs as pages inside your own WordPress site |
| PDF | [`./pdf/`](./pdf/) | Distributing offline, emailing, training handouts, printing |

The HTML, WXR, and PDF files are generated from the markdown by [`./build-docs.mjs`](./build-docs.mjs). Re-run after editing the markdown:

```
npm install --no-save marked
node docs/editors/build-docs.mjs
```

---

## How to read this pack

1. If you are new to the plugin, start with **EDITORS_GUIDE.md §1 (Basic
   Concepts)**, then jump to §2 for the hands-on walkthrough.
2. Keep **TROUBLESHOOTING.md** handy — it is organized by symptom, not by
   feature, so you can skim for the sentence that matches what you are
   seeing.

## Screenshots & video walkthrough

- Screenshot assets live in [`./screenshots/`](./screenshots/) with the
  naming convention `NN-short-description.png`. Each screenshot slot is
  called out inline in EDITORS_GUIDE.md as
  `> **Screenshot placeholder** — screenshots/NN-*.png`.
- A **5–10 minute video walkthrough** is part of the deliverable. Drop it
  (or a link to your internal video host) in
  `./screenshots/editor-walkthrough.mp4`. Suggested chapter markers are
  listed in [EDITORS_GUIDE.md Appendix A](./EDITORS_GUIDE.md#appendix-a--screenshots--video).

## Developer / technical references

If you're looking for code-level documentation, see one directory up:

- [`../API.md`](../API.md) — REST API endpoints
- [`../CLI-COMMANDS.md`](../CLI-COMMANDS.md) — WP-CLI commands
- [`../../README.md`](../../README.md) — plugin overview
- [`../../README-BLOG.md`](../../README-BLOG.md) — full blog translation reference
