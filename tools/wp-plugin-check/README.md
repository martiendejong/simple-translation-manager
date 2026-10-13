# wp-plugin-check

Runs the **official WordPress Plugin Check** (`wp plugin check`, static and runtime checks) on a plugin
**release ZIP** inside a throwaway WordPress, and turns the result into a report, a comparison between
two scans and, if you want, Jengo Work tasks. It works for any plugin ZIP; STM is only the first user.

Nothing here copies Plugin Check's rules. The only judgement the tool adds is about the scan itself:
was it complete, did runtime checks really run, is the scanned code the code in the ZIP.

## What a scan does

1. Reads the ZIP (no `ext-zip` needed): one plugin folder, main file header, slug, version.
2. Builds a sandbox under the work dir: its own MariaDB data directory and port, WordPress, WP-CLI and
   the Plugin Check plugin. Every download is checked against the hash in `pins.json` before use.
3. Installs the ZIP exactly as it is and verifies every installed file byte for byte against the ZIP.
4. Runs a synthetic **canary plugin** that must trigger one known runtime-check finding. If it does not,
   runtime checks did not run and the scan is reported as failed, never as clean.
5. Runs `wp plugin check <slug> --format=strict-json` with no `--checks`, `--exclude-checks`,
   `--ignore-codes`, `--exclude-directories`, `--exclude-files` or `--severity` (the report records this).
6. Writes `report.json` (+ raw output) and deletes the sandbox.

Only synthetic data is created. The scan needs the network for the first download of each pinned
component; later runs use the cache in `<work-dir>/cache`.

## Requirements

- PHP 8.5 as `php` (the minor version is pinned in `pins.json`; `--php=<binary>` selects another binary). The
  extensions WordPress needs (for example `mysqli`) only have to exist in the PHP install's extension folder:
  the sandbox writes its own `php.ini` and does not depend on the host's.
- A MariaDB server install (`mariadbd` and `mariadb-install-db`): `--mariadb-bin=<dir>` or `WPC_MARIADB_BIN`.
  A private server is started on a free port; an installed service is never touched.
- No WP-CLI, Docker or composer: the tool downloads WP-CLI as a phar.

## Commands

```
php tools/wp-plugin-check/scan.php scan  <plugin.zip> --out-dir=DIR [--commit=SHA] [--php=BIN] [--mariadb-bin=DIR] [--work-dir=DIR] [--keep-sandbox]
php tools/wp-plugin-check/scan.php check <plugin.zip> --reports-dir=DIR [--board=ID --key-file=FILE [--api=URL] [--apply] [--max-new=N]] [scan options]
php tools/wp-plugin-check/scan.php compare <old-report.json> <new-report.json> [--format=json|md]
php tools/wp-plugin-check/scan.php import <report.json> --board=ID --key-file=FILE [--api=URL] [--apply] [--max-new=N] [--state=FILE]
php tools/wp-plugin-check/scan.php status <slug> --reports-dir=DIR [--board=ID --key-file=FILE] [--format=json|md]
php tools/wp-plugin-check/scan.php lifecycle <plugin.zip> --script=FILE --out-dir=DIR [--from=old1.zip,old2.zip]
```

- **`check`** is the one entry point for people, the Jengo action and PR/release checks: it runs `scan`
  into the report store (`<reports-dir>/<slug>/<scan time>-<zip hash prefix>/report.json`), prints the delta
  against the previous complete scan, imports into the board when `--board` is given (dry run unless
  `--apply`) and prints the status. Its exit code is the scan's.
- **`status`** is what a status screen shows: last attempt, last complete scan, delta, linked tasks.
- **`lifecycle`** installs the ZIP in a throwaway site (clean install, update from older ZIPs, uninstall
  with and without "keep data") and runs a plugin-specific script between the steps; see
  `tests/lifecycle/stm-lifecycle.php` for STM's.

Exit codes of `scan` / `check`: `0` clean, `1` findings, `2` scan failed, `3` partial (a `--checks` subset;
never valid as release evidence). `import` exits `3` when the report may not be imported.

The Jengo Work key is read from the first line of `--key-file` or from `JENGOWORK_API_KEY`, never from an
argument.

## The report

`report.json` (`wp-plugin-check-report/1`): scan id and status with the reason, ZIP name, size and SHA-256,
plugin version and commit, the pinned and the actually used versions (WordPress, Plugin Check, WP-CLI, PHP,
MariaDB), the exact command, every finding (check code, type, severity, file, line, message, fingerprint,
root cause), the inventory of `phpcs:ignore` / `phpcs:disable` comments with their written reasons
(unjustified, file-wide or unbounded ones are listed as problems), ZIP hygiene (development files,
secret-looking strings), the external hosts the code talks to, and the install verification.

A scan is `complete` only if Plugin Check ran to the end, the canary proved runtime checks, and the installed
files equal the ZIP. Anything else is `failed` with the reason. A failed or partial scan never counts as clean
and is never compared or imported.

## Findings become tasks (phase B)

- A finding's **fingerprint** is plugin + check code + file + enclosing function/class + normalised message +
  the trimmed flagged line. Line numbers are not part of it, so code that only moved keeps its identity.
- A **root cause** is one check in one file. One bounded task is made per root cause (at most 20 findings in
  a task; a larger group is split per function). The task carries the evidence: Plugin Check, WordPress and
  PHP versions, ZIP hash, commit and the first 20 locations.
- The task is found again by a marker in its description, not by title or a local state file. Importing the
  same report again, or a new scan with the same result, changes nothing: no task, no comment.
- A finding that is gone from a **complete** scan makes its open task a **Review candidate**: one comment, no
  status change. A person checks the fix and closes the task. The importer never closes anything.
- A root cause reported again after its task was closed gets a new task that points back.
- A failed or partial scan is refused before the board is even read. New tasks start in the board's intake
  status (`backlog` by default), with no priority, so the board's own refinement and gates apply.

## Pins

`pins.json` holds WordPress, Plugin Check and WP-CLI versions with download hashes and the PHP minor
version. Changing a pin changes what "clean" means: bump deliberately and save a new baseline.

## Tests

`vendor/bin/phpunit tests/WpPluginCheckToolTest.php tests/WpPluginCheckImporterTest.php`: ZIP reading,
suppression inventory, fingerprints, report parsing, comparison, the importer against an in-memory board,
the `check` and `status` commands. They need no network and no WordPress.
