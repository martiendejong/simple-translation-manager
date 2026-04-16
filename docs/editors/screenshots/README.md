# Screenshot Assets

This directory holds the screenshot assets referenced from
[`../EDITORS_GUIDE.md`](../EDITORS_GUIDE.md) and the video walkthrough.

## Naming convention

`NN-short-description.png` — two-digit prefix enforces ordering when the
directory is listed alphabetically.

| Slot | File name | Captured screen |
|---|---|---|
| 01 | `01-languages-list.png` | WP Admin → Translations → Languages |
| 02 | `02-url-switching.png` | Same post rendered in two languages via `?lang=` |
| 03 | `03-posts-list-language-column.png` | Posts → All Posts — Language column |
| 04 | `04-translations-metabox.png` | Post editor — Translations meta box in context |
| 05 | `05-current-language-selector.png` | Meta box — "This post is written in:" dropdown |
| 06 | `06-language-tabs.png` | Meta box — target-language tab strip |
| 07 | `07-translation-tab-fields.png` | Meta box — Title / Slug / Excerpt / Content fields |
| 08 | `08-separate-pages-strategy.png` | Diagram — 3 pages joined by one translation group |
| 09 | `09-strings-dashboard.png` | Translations → Strings — searchable dashboard |

## Annotation guidelines

- Use red rectangles (2px stroke) to outline the element being discussed.
- Add a numbered badge matching the step number in the guide when the
  screenshot shows a multi-step action.
- Keep screenshots wide enough to show surrounding context but cropped
  so the highlighted UI occupies at least 50% of the frame.
- Export as PNG at 1600px wide maximum. Compress with pngquant or tinypng
  before committing.

## Video walkthrough

- File: `editor-walkthrough.mp4` (or link to internal host from the guide).
- Length target: **5–10 minutes**.
- Chapter markers: see
  [`../EDITORS_GUIDE.md` Appendix A](../EDITORS_GUIDE.md#appendix-a--screenshots--video).
