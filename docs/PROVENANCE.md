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
exact legal name first, then change `STM_PROV_HOLDER` in `bin/provenance.php`
and run `php bin/provenance.php check` to find every header that still needs
updating. Do not add third-party names or copyrights without verifying them.

## Tooling (`bin/provenance.php`, development only)

```
php bin/provenance.php check                 # headers + unique Source-Ids
php bin/provenance.php apply-headers         # add missing headers (idempotent)
php bin/provenance.php manifest --out=FILE   # private manifest of all recognition points
php bin/provenance.php compare REF CAND      # comparison report for two source trees
php bin/provenance.php release --out-dir=DIR # exact ZIP + release record
php bin/provenance.php verify-release RECORD # rebuild the ZIP and compare SHA-256
php bin/provenance.php demo                  # report on literal / prefix-only / independent copies
```

* `manifest` and `release` refuse to write inside the repository. The manifest
  lists every recognition point with file, symbol, introduction commit and
  release; keep it in a private location, not next to the code it describes.
* The release ZIP is built from an allowlist (`STM_PROV_DIST_PATHS`) with
  `git archive`, so tests, fixtures, tooling and `vendor/` cannot end up in it.
  The same commit gives the same bytes; `verify-release` proves it.
* The release record ties together commit, tree, ZIP SHA-256 and the recorded
  publication date. The hash identifies **that exact file only**. It says
  nothing about a rewritten or repackaged copy.
* Tag signing is reported, never assumed: the record says whether a signing key
  is available on the machine that built the release and whether the tag is
  signed.
* `compare` and `demo` classify a candidate as `verbatim-indicators`,
  `prefix-renamed-indicators`, `partial-overlap-only`, `copyright-notice-only`
  or `no-recognition-points-found`. A kept copyright notice alone is not an
  indicator (the GPL requires keeping it). The report lists observations; it
  never concludes that copying happened.

## Adding a new module

1. Create the file with the three header lines (or run `apply-headers`).
2. Run `php bin/provenance.php check`.
3. Add a diagnostic code to every new error return, and a design note where the
   behaviour is not obvious. Do not add either as decoration.
