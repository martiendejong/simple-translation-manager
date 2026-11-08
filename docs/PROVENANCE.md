# Source provenance in STM

STM is GPL software and forks are welcome. This page explains the small set of
conventions that make it possible to answer "where did this code come from?"
for a given release. They are **indicators for a human reviewer**. They do not
prove copying, intent, AI use or infringement, and independently written code
that behaves the same way is expected to match none of them.

Nothing here is obfuscation, telemetry or an intentional bug. Every item is
either a normal engineering practice (license headers, error codes, design
notes) or a test fixture.

## What a contributor sees

| Convention | Where | Notes |
|---|---|---|
| License + copyright header | first lines of every own PHP/JS file | `SPDX-License-Identifier`, `SPDX-FileCopyrightText`, `Source-Id`. Third-party code (`vendor/`, `node_modules/`) keeps its own notices and is never touched. |
| Source-Id | one per file, in the header | Stable module identifier (`stm.<module>`). Written once; renaming a file does not change it. Must be unique. |
| Diagnostic codes | REST errors and `error_log` lines | `STM-E-<MODULE>-<FUNCTION>-<REASON>`. REST errors carry it as `data.stm_diag`; existing `code`, `message` and `status` are unchanged. Log lines keep the `[STM]` prefix. |
| Design notes | comments at real decision points | `[STM-DN-nn]` followed by the reason the code is the way it is. Add one only where a future reader would otherwise ask "why?". |
| Symbol docs | named constants | `[STM-SYM-nn]` on constants that replaced a literal spelled in several places. Values are identical to the old literals. |
| Synthetic fixtures | `tests/fixtures/` | Invented sentences, `STM-FX-*` ids. Never shipped in the plugin ZIP. |
| Project prefix | globals, hooks, options, REST namespace | `stm_` / `STM_` / `STM\`, REST namespace `stm/v1`. Existing names are public API and are not renamed. |

## Rights holder

Headers name **Martien de Jong** (the plugin header's `Author`, and the author
of the repository history). No other party is named as rights holder. If
another legal entity (for example ProsperGenics) holds the rights, confirm the
exact legal name first, update `rights.holder` in `provenance.json`, and run
`php tools/wp-provenance/provenance.php check --config=provenance.json` to find
every header that still needs updating. Do not add third-party names or
copyrights without verifying them.

## Tooling (`tools/wp-provenance/provenance.php`, development only)

The config-driven tool introduced in task 5184 replaces the earlier `bin/provenance.php`.
Each plugin keeps its own `provenance.json` config (see [wp-provenance docs](wp-provenance-tool.md)).

```
php tools/wp-provenance/provenance.php check --config=provenance.json              # all checks
php tools/wp-provenance/provenance.php apply-headers --config=provenance.json      # add missing headers (idempotent)
php tools/wp-provenance/provenance.php manifest --config=provenance.json --out=FILE  # private manifest
php tools/wp-provenance/provenance.php release --config=provenance.json --out-dir=DIR  # ZIP + release record
php tools/wp-provenance/provenance.php verify-release RECORD --config=provenance.json  # re-verify ZIP
php tools/wp-provenance/provenance.php compare --config=provenance.json --candidate=DIR  # comparison
php tools/wp-provenance/provenance.php demo                                         # fixture walk-through
```

* `manifest` and `release` refuse to write inside the repository. The manifest
  lists every recognition point with file, symbol, introduction commit and
  release; keep it in a private location, not next to the code it describes.
* The release ZIP is built from the `release.dist_paths` allowlist in `provenance.json`
  with `git archive`, so tests, fixtures, tooling and `vendor/` cannot end up in it.
  The same commit gives the same bytes; `verify-release` proves it.
* The release record ties together commit, tree, ZIP SHA-256 and the recorded
  publication date. The hash identifies **that exact file only**. It says
  nothing about a rewritten or repackaged copy.
* Tag signing is reported, never assumed: the record says whether a signing key
  is available on the machine that built the release and whether the tag is
  signed.
* `compare` and `demo` classify a candidate as `strong-indicators` (verbatim or
  prefix-renamed registered markers, byte-identical files), `weak-indicators`
  (single renamed marker, partial overlap), or `none`. A kept copyright notice,
  the same brand name, the same feature or standard WordPress boilerplate are
  informational only and never raise the assessment. The report lists
  observations; it never concludes that copying happened.

## Adding a new module

1. Create the file with the three header lines (or run `apply-headers`).
2. Run `php tools/wp-provenance/provenance.php check --config=provenance.json`.
3. Add a diagnostic code to every new error return, and a design note where the
   behaviour is not obvious. Do not add either as decoration.
