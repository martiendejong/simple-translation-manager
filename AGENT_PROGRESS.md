# Agent Progress

## 2026-08-08 — task 869efjuhp
Done: Nonce verification, PR opened. Every state-changing handler in class-admin.php,
class-import-export.php and class-dashboard.php already called `Security::verify_admin_action()`,
which itself calls `check_admin_referer()` — so CSRF protection already existed at runtime. The
real gap was that PHPCS's `WordPress.Security.NonceVerification` sniff (and WordPress Plugin
Check, which uses the same sniff) cannot see through a static-method wrapper — it only recognizes
direct, unqualified calls to `wp_verify_nonce()`/`check_admin_referer()`/`check_ajax_referer()` —
so a real Plugin Check run would still report `NonceVerification.Missing` on all 13 handlers
despite the code being secure. Fixed by inlining `check_admin_referer($nonce)` directly at each
call site (combined with the existing `current_user_can()` check in one condition, matching the
original wrapper's exact semantics) and removing the now-unused `Security::verify_admin_action()`.
The remaining `NonceVerification.Recommended` warnings were all genuinely read-only GET-based list
filters/tab selection/post-redirect-GET display flags (page_translations(), Dashboard::render_page(),
both templates) — suppressed with justified `phpcs:ignore`/`phpcs:disable` comments per the task's
own review guidance, since a nonce is not meaningful for idempotent, bookmarkable filter URLs.
Also added `phpcs.xml.dist` (scoped to only the `WordPress.Security.NonceVerification` sniff on
these 5 files — not the full WordPress-Extra ruleset, to avoid unrelated scope creep), `composer
require --dev` for PHPCS + wp-coding-standards/wpcs, a `composer run phpcs` script, and a
`nonce-verification` CI job so this stays enforced going forward instead of being a one-time check.
Verified: `vendor/bin/phpcs --standard=phpcs.xml.dist` — 0 errors, 0 warnings (was 27 errors + 32
warnings before the fix). `vendor/bin/phpunit` 109/109 pass (no behavior change; one pre-existing
test — `test_toggle_language_active_dies_when_nonce_or_capability_check_fails` — caught a real bug
in an earlier draft of this refactor where the inlined `check_admin_referer()` return value wasn't
being checked, which would have silently dropped nonce enforcement whenever `check_admin_referer`'s
own `$die=true` default didn't fire; fixed by combining both checks in one `if`). `php -l` clean on
every changed file. YAML-parsed the new CI job (not just `bash -n`). `npx jest` not runnable in this
worktree (no `node_modules`, pre-existing gap unrelated to this PHP-only change) — no JS was touched.
Follow-up: the inlined `check_admin_referer()` calls put 7 previously-untested `class-admin.php`
handlers (`save_translation`, `add_string`, `scan_strings`, `import_json`, `add_language`,
`delete_language`, `save_ai_settings` — only `toggle_language_active` had coverage before) onto
CI's diff-coverage gate for the first time, and it failed at 12.5%. Added
`tests/AdminFormHandlersTest.php` (one happy-path + one nonce-denied test per handler, reusing the
FakeWpdb/Brain Monkey pattern from `LanguagesScreenTest.php`); had to introduce a `RedirectInterrupt
extends \Error` interrupt signal instead of `\RuntimeException`, since `save_translation()`/
`add_string()`/`scan_strings()` wrap their success-path `wp_redirect()` in `try { } catch
(\Exception $e)`, which was silently swallowing a `\RuntimeException`-based interrupt and
misreporting it as a DB failure. Verified: PR #27 CI all green (PHP unit tests incl. diff-coverage
gate, JS unit tests, PHP lint, Nonce verification/PHPCS), `vendor/bin/phpunit` 124/124 pass locally.
Left: nothing outstanding for this task.


## 2026-08-07 — task 869efjuhb
Done: PR — added `if (!defined('ABSPATH')) exit;` to the 5 files Plugin Check flagged as
`missing_direct_file_access_protection`: `includes/functions.php`,
`includes/class-language-switcher.php`, `includes/class-cli.php`,
`tests/wp-integration-smoke.php`, `test-bulk-api.php`. Matches the existing
`templates/*.php` convention (single-line, no braces) rather than the task's WP-core-spaced
snippet, for consistency with the rest of the repo. The two `namespace STM;` files
(`class-language-switcher.php`, `class-cli.php`) needed the guard placed *after* the
namespace declaration, not before — PHP requires `namespace` to be the first statement in
the file (only `declare()`/comments may precede it), so a literal "top of file" placement
would have been a fatal parse error.
Verified: `php -l` clean on all 5 changed files and every other `.php` file in the repo.
`vendor/bin/phpunit` 109/109 pass (unchanged — no test logic touched). `npx jest` 18/18 pass
(unchanged). No live WordPress instance to browser-verify the blank-page behavior, matching
every prior STM PR in this repo without one.
Left: `tests/wp-integration-smoke.php` and `test-bulk-api.php` both self-bootstrap WordPress
(`require_once wp-load.php`) when run standalone — adding the guard means neither can be
executed directly anymore (ABSPATH is never defined before they load it themselves). This
was explicit in the task ("test files are not shipped in production zips but should still
have the guard for WP.org compliance"), so left as instructed; a developer needing to run
either script locally will need to temporarily comment out the guard line.

## 2026-08-06 — task 869eecx75
Done: PR — auto-translate failures now surface the backend's real `error` string instead of the
generic "Auto-translate failed" text. `handleAutoTranslate()` in `assets/admin-post-editor.js`
collects the distinct non-empty `r.error` values across all failed fields and joins them (e.g. two
fields hitting the same missing-API-key error show it once; two different provider errors show
both, separated by "; "). True network failures (jQuery `.fail()` on the AJAX call, no response at
all) now resolve with `error: ''` in `translateField()` so they fall through to the existing
generic `i18n.translateFailed` fallback — the one case the task said should keep the generic text.
Verified: `class-auto-translate.php`'s `translate_openai()`/`translate_deepl()` already return the
real per-field error (missing key text, or the live OpenAI/DeepL error) — no backend change needed.
Verified: `npx jest` 18/18 pass (13 existing + 5 new in `tests/js/admin-post-editor.test.js`: single
real error shown, two different real errors joined, one real error survives alongside a no-detail
network failure, pure network failure falls back to the generic message, success path unaffected).
No PHP changed. No live WP instance to click-through, matching every prior STM JS-only PR in this
repo.
Left: nothing outstanding for this task.

## 2026-07-25 — task 869e9a954
Done: PR #19 — switched the 5 `Database::get_languages()` (active-only) call sites in
`class-post-editor.php` (meta box, Gutenberg panel, preview cycler, both post-list columns) to
`get_all_languages()`, so an inactive language's tab still renders and its content can be typed,
saved (the save path never filtered by active status), and previewed. Frontend surfaces
(`class-language-switcher.php`, `class-hreflang.php`, `class-frontend.php`) were left untouched —
confirmed they already only call `get_languages()`. Also found the task's own "How to test" step
("Mark a language inactive on the STM Languages screen") was unactionable: the Languages screen
had no way to deactivate a language at all (`add_language()` always inserted `is_active=1`, no
toggle UI/endpoint existed). Added `Admin::toggle_language_active()` (admin_post handler, guards
the default language from being deactivated, invalidates both language caches) plus an
Activate/Deactivate button per row in `templates/admin-languages.php`.
Verified: `vendor/bin/phpunit` 97/97 pass (14 new: inactive-language coverage for the meta box,
Gutenberg panel, preview cycler and both post-list columns; toggle-active activate/deactivate/
default-guard/not-found/invalid-code/cache-invalidation paths, using a `wp_redirect`-throws-to-
interrupt trick so the handler's trailing `exit;` never kills the test process). `npx jest`
13/13 pass (unaffected — no JS touched). `php -l` clean on every changed file. No coverage driver
(pcov/xdebug) available locally to run `bin/diff-coverage.php` numerically, but manually traced
every touched line against the new tests; the single genuinely uncovered line is the shared
trailing `exit;` in `toggle_language_active()` (unreachable without letting the real process
exit), which the refactor already collapsed from 3 duplicate exit points down to 1. No live
WordPress instance to click-through, matching every prior STM feature PR in this repo.
Left: nothing outstanding for this task.

## 2026-07-24 — task 869e8wk1w
Done: Deploy-time version tracking, PR #18. Plugin already had a manually-bumped `Version:` header + `STM_VERSION` constant (WP's own convention), but nothing JengoAGI's version detector recognizes (it only checks git tags, a VERSION file, package.json, or a csproj `<Version>`) and no automation kept the header/constant/package.json in sync. Added: root `VERSION` file (source of truth), `package.json` `"version"` field, `bin/bump-version.php` (`php bin/bump-version.php <major|minor|patch>` bumps all three files in lockstep via regex — CRLF-safe, preserves existing formatting), and `.github/workflows/release-tag.yml` (auto-creates+pushes a `vX.Y.Z` git tag whenever VERSION changes on a push to master).
Verified: `vendor/bin/phpunit` 83/83 pass (4 new — consistency check + 3 bump-script functional tests using a temp-dir copy, incl. major/minor reset and unknown-part rejection). `npx jest` 13/13 pass (unaffected). `php -l` clean on every PHP file in the repo. `package.json` valid JSON. Workflow YAML parsed with a real YAML parser (not just `bash -n`) and its embedded script's bash syntax checked separately. CLI smoke-tested `php bin/bump-version.php patch` against a scratch copy of the real repo files end-to-end.
Left: nothing outstanding for this task. Retroactively tagging the current `v1.2.0` on master is a follow-up for whoever merges this PR (or the workflow will tag the next real bump automatically).

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

## 2026-07-21 — task 869e6vuk9
Done: PR #17 — a "Preview in language" cycler at the top of the post/page/product/CPT
meta box: prev/next buttons step through every configured language and a "View preview"
link opens WordPress' own preview URL (`get_preview_post_link()` + a `lang` query arg) in
a new tab, so an editor can quickly see how the entity actually renders in each language —
distinct from the existing meta-box translation tabs (869ceqwwn/869cy7b5r) which only edit
fields. Works from the first edit of a brand-new post too (falls back to the global `$post`
auto-draft ID when `$_GET['post']` isn't set yet). The Gutenberg sidebar panel gets a
matching "Preview" quick-link per language next to its existing "Edit" jump-to-tab button.
Verified: `vendor/bin/phpunit` 79/79 pass (10 new, incl. `build_preview_languages()`, the
new-post `$_GET` fallback, and two `render_meta_box()` template-render tests — added
`templates/meta-box-translations.php` to phpunit.xml's tracked `<source><include>` list,
since it had never been coverage-tracked at all before this PR). `npx jest` 13/13 pass (6
new). `php -l` and `node --check` clean on all changed files. No coverage driver
(pcov/xdebug) available locally to run the numeric gate, but manually verified every
touched line against the tests. No live WordPress instance to click-through, matching
every prior STM feature PR in this repo.
Left: nothing outstanding for this task.

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

## 2026-07-25 — task 869e9a954 (round 2, review feedback)
Done: PR #19 review found `class-frontend.php`'s `get_current_language()` accepted any
well-formed language code via `?lang=`, the rewrite query var, or the cookie — so once an
admin saved content for an inactive language, any visitor could see it via `?lang=de`.
Fixed: the resolved language is now only honored when it is active (`Database::get_languages()`),
or when the request is an authenticated preview (`is_preview()` + `current_user_can('edit_post', $id)`
on the queried post) — the mechanism the "Preview in language" cycler already relies on. Otherwise
falls back to the default language, same as an unrecognized code always did.
Verified: `vendor/bin/phpunit` 108/108 pass (11 new in `tests/FrontendTest.php` covering active/inactive/
unknown codes across all three input paths, plus the preview-authorization edge cases; updated 2
pre-existing tests in `SeoGodIntegrationTest.php`/`ElementorIntegrationTest.php` that assumed any
cookie value was honored regardless of active status). `npx jest` 13/13 pass (unchanged). `php -l`
clean on all changed files.
Left: nothing outstanding for this task.

## 2026-07-31 — task 869ebjzzc (Bugatti Insights ClickUp board)
Done: root-caused and fixed the public search REST endpoint 502ing on Bugatti Insights whenever
`?lang=` was present. Not a REST-layer bug at all — `Frontend::get_requested_language()` calls
`setcookie('stm_lang', ...)` every time `get_current_language()` runs, and a search-results loop
calls it once per post (via the `the_title`/`post_type_link` filters in `get_the_title()`/
`get_permalink()`). 12 search results = 12 duplicate `Set-Cookie` headers on one response, which
this host's PHP-FPM/IIS front end turns into a 502 instead of relaying. Confirmed live via
plugin activate/deactivate toggling (STM inactive = 200, active = 502) and a binary-search of
hand-patched live deploys down to the exact `setcookie()` line. Fix: `remember_language_choice()`
guards the cookie write with a `$cookie_written` static flag (once per request) + `headers_sent()`.
Verified: `vendor/bin/phpunit` 109/109 pass (1 new test asserting the guard latches after the
first of 12 simulated calls). Live: `lang=de`/`en`/`fr` each 3/3 return 200 JSON against
test.bugattiinsights.com after deploying this fix; no-`lang` and an unrelated-param control both
still 200.
Left: nothing outstanding for this task. Follow-up 869ebjz6a (wiring `lang` into the search UI)
can now build on this without inheriting the crash.
