# Plugin Check final result: STM 1.3.2, ZIP 08564871

What this is: the official WordPress Plugin Check result for the release ZIP that is meant to be submitted to the
WordPress.org directory, and the install, update and runtime test of the same ZIP. Compare with the starting point in
[`../baseline-9c1f4a49/BASELINE.md`](../baseline-9c1f4a49/BASELINE.md) (11 errors, 481 warnings).

| | |
|---|---|
| Release ZIP | `simple-translation-manager-1.3.2.zip`, 418326 bytes, 56 entries |
| ZIP SHA-256 | `0856487161318e89a63c6152410861d18abd0f47b2cf4f0897fd68d2b89846f3` |
| Built from | commit `7bafa8174e77a9cd4c6988b2d30cf891195a5be2` on branch `feature/5144-wporg-plugin-check` (PR #45); see `release-record.json` |
| Scan | `20261013T181730Z-ca6d11`, status **complete** (runtime checks proven by the canary, 49 installed files identical to the ZIP) |
| Result | **0 errors, 0 warnings**, 0 suppression problems, 0 ZIP hygiene problems |
| Official command | `wp plugin check simple-translation-manager --format=strict-json --fields=file,line,column,type,code,message,docs,severity` (no `--checks`, `--exclude-checks`, `--ignore-codes`, `--exclude-directories`, `--exclude-files`, `--severity`) |
| Versions | WordPress 7.1.3, Plugin Check 2.1.0, WP-CLI 2.12.0, PHP 8.5.6, MariaDB 12.3.2 (pinned in `tools/wp-plugin-check/pins.json`) |
| Install, update and runtime test | `20261013T181732Z-64d014`, **280 of 280 checks passed** (`lifecycle.md`) |

Files: `report.json` (normalised report with the suppression inventory), `raw/` (unmodified Plugin Check output and the
canary), `lifecycle.json` and `lifecycle.md`, `release-record.json`.

## Same ZIP, byte for byte

The scan and the lifecycle run both name ZIP SHA-256 `08564871...` in their reports. The ZIP is built from the commit's
tree with `php bin/provenance.php release . --commit=<sha>`, limited to an allowlist of runtime paths, with entry times
fixed to the last commit that touched a shipped path. A later commit that only changes docs, tests or tooling gives the
same bytes. Any change to a shipped file changes the hash and needs a new scan.

Not in the ZIP: tests, tools, `bin/`, `vendor/`, `composer.*`, `package*.json`, `phpunit.xml`, `AGENT_PROGRESS.md`,
`.git*`. What ships: the main plugin file, `uninstall.php`, `readme.txt`, `includes/`, `templates/`, `assets/` and the two
editor PDFs the Documentation screen links to.

## Warnings: fixed or individually justified

There is no global suppression: no `--ignore-codes`, no `--exclude-*`, no file-wide `phpcs:disable`, no `phpcs.xml`
exclusion. The scanner lists every `phpcs:ignore` and `phpcs:disable`/`phpcs:enable` comment in the ZIP; all 184 carry a
written reason after ` -- ` and each one covers a single statement or a short bounded block (the scanner reports a
comment without a reason, a file-wide one, an unbalanced `disable` or a long span as a problem; there are none).

| Baseline finding (count) | Final state |
|---|---|
| `DirectDatabaseQuery.DirectQuery` (143), `NoCaching` (105) | Justified, one written reason per statement or short block: the plugin owns seven tables (`wp_stm_*`) that no WordPress API reads or writes. The reason names the table and what the query is for. |
| `PreparedSQL.InterpolatedNotPrepared` (91), `DirectDB.UnescapedDBParameter` (60, 3 of them errors), `PreparedSQLPlaceholders` (5) | Fixed in code where a value was interpolated (table names are written as `{$wpdb->prefix}stm_x`, values go through `$wpdb->prepare()` placeholders). What remains is suppressed with a reason: 11 + 4 + 2 + 1 + 4 comments for dynamic `IN (%d,%d,...)` lists built with `array_fill()` and for fragments that are the output of `prepare()`. |
| `NonceVerification.Recommended` (26) | 14 comments remain, each for a read-only admin list filter or view selector that changes no state (6 of them existed at baseline without a reason). |
| `ValidatedSanitizedInput.*` (38) | Request values now go through `wp_unslash()` and a sanitiser. 8 comments remain, each naming why: the plugin's own sanitiser runs on the next line, the value must stay byte-exact for a hash comparison, or the upload array is never stored. |
| `error_log` (7) | Justified: logs a failed database operation with a stable `[STM-E-...]` diagnostic code, not debug output. |
| `wp_redirect` (5) | Fixed: `wp_safe_redirect()`; no `wp_redirect` is left in the shipped code. |
| `EscapeOutput.OutputNotEscaped` (7 comments) | Justified: CSV and file downloads, not HTML. |
| `AIProvider.DirectIntegration` (1) | Justified: the plugin supports WordPress 6.0+, which has no WordPress AI Client; the site owner chooses the provider and supplies the key, and the request only runs after an administrator action. |
| `SchemaChange` (1), `DiscouragedFunctions` (1) | Justified: dropping the plugin's own tables on uninstall (only with "Keep data" off); a raised time limit for one admin request that calls an external API per missing value. |
| 11 errors: text domain `stm` (4), `Tested up to` (1), `wp_sitemaps_get_max_urls` with "Requires at least 5.0" (2), `parse_url` (1), unescaped SQL (3) | All gone: text domain is the slug, `Tested up to: 7.1`, `Requires at least: 6.0`, `wp_parse_url()`, SQL restructured. |

## Release files

`readme.txt` and the main file header agree: version 1.3.2 = `Stable tag` = `STM_VERSION` = `VERSION`; `Requires at least:
6.0`, `Requires PHP: 7.4`, `Tested up to: 7.1`; `License: GPLv2 or later` with the GNU URI in both. The changelog now
covers 1.3.2 down to 1.0.0. The readme has an **External services** section for OpenAI and DeepL (what is sent, when, to
which URL, terms and privacy links), a **Privacy** section (the `stm_lang` cookie, API keys in the options table, no
tracking) and a REST FAQ that lists exactly which routes are public.

External hosts found in the ZIP by the scanner (all expected): `api.openai.com`, `api.deepl.com`, `api-free.deepl.com`
(auto-translate, only after an administrator enters a key), `platform.openai.com`, `www.deepl.com`, `www.gnu.org` (links
in the settings screen and header), `martiendejong.nl` (Plugin URI).

## What the install, update and runtime test covers

Run in a throwaway WordPress with synthetic data only (`tests/lifecycle/`):

- **Update keeps translations:** installs 1.3.0 and 1.3.1 from their release tags, fills them, updates to 1.3.2: every
  row of all seven tables and every option survives unchanged, the database version moves to 1.3.2, a stored
  translation is served by the new code.
- **Clean install:** seven tables, English and Dutch seeded, one default language, rewrite rules.
- **Uninstall:** with "Keep data" on, data stays and a reinstall picks it up; with it off (from WP-CLI, no user logged
  in) tables, options, API keys, the per-user preference and transients are gone.
- **REST permissions:** every route is checked in process for anonymous, subscriber, editor and administrator, and over
  HTTP with real logins and nonces. Anonymous visitors may call exactly the documented public read routes; no
  subscriber, editor or anonymous request can write, import, export or flush; a nonce without the login cookie does not
  authenticate; drafts, private posts, password-protected posts and draft translations are not readable by anyone who
  cannot edit them.
- **Admin, frontend, import and export:** all seven admin screens open for administrators and not for lower roles;
  forms refuse a missing or foreign nonce; JSON, PO and XLIFF import and export round trip; XLIFF does not resolve
  external entities; an upload with a bad extension or a malformed file is a clean 400; frontend pages render without
  PHP errors, one `stm_lang` cookie per response, hreflang alternates are present.
- A PHP notice, warning, fatal error, database error or `[STM-E-]` line that the plugin writes to `debug.log` during any
  step fails that step.

## Not covered

- Update from 1.2.x and older: the 1.2.1 tag has no `readme.txt`, so the release builder cannot make a ZIP of it; the
  update path is tested from 1.3.0 and 1.3.1.
- Open STM pull requests #39 (Pods) and #43 (stale detection) are **not** part of this ZIP. Whatever merges into `master`
  later changes the shipped files, so the release scan must be run again on the new ZIP.
- A scan only covers what Plugin Check checks. The human review by the WordPress.org plugin team is a separate gate; see
  [`../SUBMISSION-GATE.md`](../SUBMISSION-GATE.md).
