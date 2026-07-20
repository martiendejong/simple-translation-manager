# Agent Progress

## 2026-07-20 — task 869cay7k8
Done: PR #4's editor docs (EDITORS_GUIDE.md/TROUBLESHOOTING.md + PDFs) shipped in April but were never linked anywhere in the plugin UI — a 2026-07-20 verification comment confirmed zero PHP/JS references. Added a new "Documentation" submenu (`Admin::page_documentation()`, `templates/admin-documentation.php`) under WP Admin → Translations, linking both PDFs via `STM_PLUGIN_URL . 'docs/editors/pdf/...'` as the reviewer specified.
Verified: `vendor/bin/phpunit` 74/74 pass (3 new: menu registration + both PDF links render). `php -l` clean on all changed/added files. Added `includes/class-admin.php` to phpunit.xml's diff-coverage `<source><include>` list (it was previously untracked, same silent-gate class of bug fixed for class-string-scanner.php in 869e6vpgg). No coverage driver (pcov/xdebug) available locally to re-run `bin/diff-coverage.php` numerically, but both new code paths (menu registration line, page render) are directly exercised by the new tests.
Left: nothing outstanding for this task. Original deliverables (EDITORS_GUIDE.md, TROUBLESHOOTING.md, screenshots index, WordPress HTML/WXR, PDFs, build script) were already merged via PR #4.

## 2026-07-20 — task 869cay7hb
Done: Elementor widget translation integration, PR #15 (`STM\ElementorIntegration` — translated content stored separately from Elementor's own `_elementor_data`, frontend overlay filter, editor translation panel, 3 REST endpoints).
Verified: `vendor/bin/phpunit` 54/54 pass, `npx jest` 7/7 pass, `php -l` clean, CI green (lint + PHP tests + JS tests) on the PR.
Left: no live Elementor click-through — no reachable WordPress+Elementor install exists in Jengo's infrastructure. Task's original "install XAMPP" ask was declined (see PR body/ClickUp comment for why); ships through this repo's existing PHPUnit-only workflow instead, matching all 12 prior merged STM feature PRs.

## 2026-07-20 — task 869e6vndz
Done: `Database::get_all_languages()` added (same query as `get_languages()`, no `is_active` filter); `Admin::page_languages()` now uses it so wp-admin > STM > Languages lists inactive languages too, shown via the existing Active column. Every other caller (`get_languages()`) untouched. PR opened.
Verified: `vendor/bin/phpunit` 18/18 tests pass (3 new: active-only query unchanged, all-languages includes inactive, Languages screen template renders an inactive row without the active checkmark). `php -l` clean on all changed files.
Left: nothing.

## 2026-07-20 — task 869e6vndz (review round 2)
Done: PR #13 got CHANGES REQUESTED — CI's diff-coverage gate failed because the new `wp_cache_delete('stm_all_languages')` line in `Api::create_language()` (`includes/class-api.php:204`) had zero test coverage. Added `test_create_language_clears_both_language_caches` to `tests/LanguagesScreenTest.php`, calling `API::create_language()` with a spy on `wp_cache_delete` asserting both `stm_active_languages` and `stm_all_languages` are cleared.
Verified: `vendor/bin/phpunit` 19/19 tests pass (1 new). `php -l` clean. No coverage driver (pcov/xdebug) available locally to re-run `bin/diff-coverage.php`, but the new test executes line 204 directly (no branching in `create_language()` between lines 186-207 for valid input), which is the only touched line the gate flagged.
Left: nothing.

## 2026-07-20 — task 869e6vpgg
Done: PR #14 — `includes/class-string-scanner.php` tokenizes the active theme (child+parent)
and the plugin's own `templates/` dir for literal `__stm()`/`_e_stm()` calls, registers each
new key/context in `wp_stm_strings`, and seeds its default-language translation with the
discovered fallback text. Runs on `stm_activate()` and via a new "Scan theme & plugin for
strings" button (`Admin::scan_strings()`) on the Strings screen. Both paths check for an
existing row before inserting, so re-scanning never duplicates strings or overwrites a
translation a human has since edited.
Verified: `php -l` clean on all changed files; `vendor/bin/phpunit` 28/28 passing (15 baseline
+ 13 new StringScanner tests covering token parsing, comment/dynamic-arg exclusion, dedup,
idempotent re-run, and manual-edit preservation). Added `class-string-scanner.php` to
phpunit.xml's tracked `<source><include>` list (it was missing, which made the CI diff-coverage
gate silently report "nothing to gate" instead of actually measuring the new code — the same
gap that bounced a sibling PR in this repo); after the fix CI's diff-coverage gate genuinely
measures 89.01% (162/182 lines) on the new file, comfortably over the 80% threshold. PR CI
(PHP unit tests, JS unit tests, PHP lint) all green. No live WordPress instance in this
environment, so the actual wp-admin Strings screen was not visually verified.
Left: nothing outstanding for this task.
