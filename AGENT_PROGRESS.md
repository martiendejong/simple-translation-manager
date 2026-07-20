# Agent Progress

## 2026-07-20 — task 869e6vndz
Done: `Database::get_all_languages()` added (same query as `get_languages()`, no `is_active` filter); `Admin::page_languages()` now uses it so wp-admin > STM > Languages lists inactive languages too, shown via the existing Active column. Every other caller (`get_languages()`) untouched. PR opened.
Verified: `vendor/bin/phpunit` 18/18 tests pass (3 new: active-only query unchanged, all-languages includes inactive, Languages screen template renders an inactive row without the active checkmark). `php -l` clean on all changed files.
Left: nothing.

## 2026-07-20 — task 869e6vndz (review round 2)
Done: PR #13 got CHANGES REQUESTED — CI's diff-coverage gate failed because the new `wp_cache_delete('stm_all_languages')` line in `Api::create_language()` (`includes/class-api.php:204`) had zero test coverage. Added `test_create_language_clears_both_language_caches` to `tests/LanguagesScreenTest.php`, calling `API::create_language()` with a spy on `wp_cache_delete` asserting both `stm_active_languages` and `stm_all_languages` are cleared.
Verified: `vendor/bin/phpunit` 19/19 tests pass (1 new). `php -l` clean. No coverage driver (pcov/xdebug) available locally to re-run `bin/diff-coverage.php`, but the new test executes line 204 directly (no branching in `create_language()` between lines 186-207 for valid input), which is the only touched line the gate flagged.
Left: nothing.
