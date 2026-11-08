# Plugin Check baseline: master 08c3ea0 (before any 5144 fix)

What this is: the official WordPress Plugin Check result for the release ZIP built from
`master` at `08c3ea0` (STM 1.3.2), before any change made for task 5144. It is the starting
point every later scan is compared against.

| | |
|---|---|
| Release ZIP | `simple-translation-manager-1.3.2.zip`, 408106 bytes, 56 entries |
| ZIP SHA-256 | `9c1f4a4947a4d2bb6f3596d2d735c1f0df601222fe561c80b1ac0d590b1ba9ae` |
| Built from | `master` `08c3ea0` (the same bytes come out of `3d9970c`, the PR branch before any plugin change) |
| Scan id / status | `20261010T041154Z-360b03` / `complete` (runtime checks proven by the canary plugin, installed files identical to the ZIP) |
| Official command | `wp plugin check simple-translation-manager --format=strict-json --fields=file,line,column,type,code,message,docs,severity` |
| Versions | WordPress 7.1.3, Plugin Check 2.1.0, WP-CLI 2.12.0, PHP 8.5.6, MariaDB 12.3.2 |
| Result | **11 errors, 481 warnings** |

Files in this folder:

- `report.json`: the normalised report (status, every finding with check, type, severity, file, line, fingerprint, the suppression inventory, ZIP hygiene, external hosts).
- `raw/plugin-check.stdout.txt`: the unmodified output of `wp plugin check`. The `file` paths inside it point into the throwaway sandbox that was deleted after the scan.
- `raw/canary.stdout.txt`: output for the synthetic canary plugin that proves runtime checks ran.

## Where the findings are

| Check | Count | Type |
|---|---:|---|
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | 143 | warning |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | 105 | warning |
| `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` | 91 | warning |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 57 warnings, 3 errors | |
| `WordPress.Security.NonceVerification.Recommended` | 26 | warning |
| `WordPress.Security.ValidatedSanitizedInput.*` | 38 | warning |
| `WordPress.PHP.DevelopmentFunctions.error_log_error_log` | 7 | warning |
| `WordPress.Security.SafeRedirect.wp_redirect_wp_redirect` | 5 | warning |
| `WordPress.DB.PreparedSQLPlaceholders.*` | 5 | warning |
| `WordPress.WP.I18n.TextDomainMismatch` | 4 | error |
| `wp_function_not_compatible_with_requires_wp` | 2 | error |
| `outdated_tested_upto_header` | 1 | error |
| `WordPress.WP.AlternativeFunctions.parse_url_parse_url` | 1 | error |
| others (`EnqueuedStylesScope`, `Squiz.PHP.DiscouragedFunctions`, `PluginCheck.CodeAnalysis.AIProvider.DirectIntegration`, `SchemaChange`) | 4 | warning |

The 11 errors: three unescaped SQL fragments (`class-admin.php` x2, `class-field-values.php`),
four `stm` text domains in `templates/admin-languages.php`, `wp_sitemaps_get_max_urls()` used with
"Requires at least: 5.0", "Tested up to: 6.7", and one `parse_url()`.

Also found outside Plugin Check by the scanner's own ZIP inspection: 10 `phpcs:ignore`
annotations with no written reason, no development files in the ZIP, no secret-looking strings,
external hosts: `api.openai.com`, `api.deepl.com`, `api-free.deepl.com` (readme has no
"External services" section), `martiendejong.nl` (plugin URI).

Reproduce: `php bin/provenance.php release . --out-dir=<dir> --commit=08c3ea0 --published=2026-10-10`
then `php tools/wp-plugin-check/scan.php scan <dir>/simple-translation-manager-1.3.2.zip --out-dir=<report-dir>`.
