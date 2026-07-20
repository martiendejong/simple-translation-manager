# Agent Progress

## 2026-07-20 — task 869e6vndz
Done: `Database::get_all_languages()` added (same query as `get_languages()`, no `is_active` filter); `Admin::page_languages()` now uses it so wp-admin > STM > Languages lists inactive languages too, shown via the existing Active column. Every other caller (`get_languages()`) untouched. PR opened.
Verified: `vendor/bin/phpunit` 18/18 tests pass (3 new: active-only query unchanged, all-languages includes inactive, Languages screen template renders an inactive row without the active checkmark). `php -l` clean on all changed files.
Left: nothing.
