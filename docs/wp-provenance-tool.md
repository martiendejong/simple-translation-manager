# wp-provenance tool

Config-driven provenance checks for WordPress plugins. Lives in
`tools/wp-provenance/`; run with `php tools/wp-provenance/provenance.php`.

Each plugin keeps its own `provenance.json` in its repository root. The same
command works across repos; there is no plugin-specific logic in the tool.

## Quick start

```sh
# Check headers, rights holder, ZIP allowlist, markers (read-only)
php tools/wp-provenance/provenance.php check --config=provenance.json

# Add missing SPDX/copyright/Source-Id headers (safe to re-run)
php tools/wp-provenance/provenance.php apply-headers --config=provenance.json

# Write a private manifest (recognition points, never in the repo or ZIP)
php tools/wp-provenance/provenance.php manifest --config=provenance.json \
    --out=/path/outside/repo/manifest.json

# Build a release ZIP + release record
php tools/wp-provenance/provenance.php release --config=provenance.json \
    --out-dir=/path/outside/repo/

# Verify a release record (re-build ZIP, compare SHA-256)
php tools/wp-provenance/provenance.php verify-release /path/to/record.json \
    --config=provenance.json

# Compare a candidate tree
php tools/wp-provenance/provenance.php compare --config=provenance.json \
    --candidate=/path/to/candidate

# Full demo with broken and comparison fixtures
php tools/wp-provenance/provenance.php demo
```

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | No errors (warnings are allowed) |
| 1 | At least one ERROR finding; use `--strict` to treat warnings the same way |
| 2 | Usage error or tool failure |

## provenance.json reference

```jsonc
{
  "schema": "wp-provenance-config/1",
  "plugin": {
    "slug": "my-plugin",             // WordPress plugin slug
    "name": "My Plugin",
    "dir": ".",                       // plugin root relative to --repo
    "main_file": "my-plugin.php",    // Plugin Name header lives here
    "version_from": { "file": "VERSION" }  // or { "header": true }
  },
  "rights": {
    "holder": "Martien de Jong",
    "holder_status": "verified",     // "verified" | "unconfirmed"
    "holder_evidence": "...",        // how you confirmed the holder
    "year": "2026",
    "license": "GPL-2.0-or-later"   // SPDX expression; MIT, GPL-2.0+, etc.
  },
  "source": {
    "extensions": ["php", "js", "mjs"],
    "skip_dirs": ["vendor", "node_modules", "tests", ".git"],
    "third_party_paths": [],          // paths exempt from header checks
    "source_id": {
      "required": true,              // false = skip Source-Id checks
      "prefix": "myplugin",          // ids must start with this prefix
      "overrides": {                 // per-file id when filename would clash
        "my-plugin.php": "myplugin.bootstrap"
      }
    }
  },
  "markers": [
    // Recognition-point kinds. Remove the entire array if not needed.
    { "kind": "diag-code",   "pattern": "MP-[EWI]-[A-Z0-9]+(?:-[A-Z0-9]+)+", "scope": "distributed", "min": 1 },
    { "kind": "design-note", "pattern": "\\[(MP-DN-\\d{2,3})\\]", "id_group": 1, "needle": "[{id}]", "scope": "distributed", "min": 1, "context": true },
    { "kind": "fixture",     "pattern": "MP-FX-[A-Z0-9]+(?:-[A-Z0-9]+)*",    "scope": "dir:tests/fixtures", "min": 0, "compare": false }
  ],
  "release": {
    "zip_root": "my-plugin",         // top-level directory inside the ZIP
    "dist_paths": [                  // allowlist: everything else is excluded
      "my-plugin.php",
      "uninstall.php",
      "readme.txt",
      "includes",
      "assets"
    ],
    "forbidden_extra": ["vendor", "composer.json"],  // always block these
    "tag_prefix": "v"
  },
  "brand": { "names": ["My Plugin"] },  // brand names that are informational only in compare
  "private": {
    "dir": "${WP_PROVENANCE_PRIVATE_DIR}/my-plugin",
    "manifest": "${WP_PROVENANCE_PRIVATE_DIR}/my-plugin/manifest-my-plugin.json"
  }
}
```

### marker scope values

| scope | meaning |
|-------|---------|
| `distributed` | marker must appear somewhere in the distributed source (not in `skip_dirs`) |
| `dir:<path>` | marker is confined to that directory (fixtures, test-only) |

### source_id.required = false

When set to `false` the tool does not require `Source-Id` lines in headers. Use
this for plugins that have not yet had Source-Ids introduced. The check still
verifies SPDX and copyright headers.

## Relationship to task #5144 (Plugin Check scanner)

Task #5144 runs the **official WordPress.org Plugin Check** (Plugin Check CLI) and
imports findings as bounded Work tasks. This tool is complementary, not a
replacement:

| Concern | wp-provenance | Plugin Check (#5144) |
|---------|--------------|---------------------|
| Copyright / SPDX headers | yes | no |
| Source-Id uniqueness | yes | no |
| Rights-holder confirmation | yes | no |
| Release ZIP allowlist | yes | partially (file size) |
| Registration markers / comparison | yes | no |
| WordPress coding standards | no | yes |
| Security / best-practice rules | no | yes |
| Import to Work tasks | report-only until #5144 importer ships | yes |

Both tools use a **finding fingerprint** (`file:rule` for this tool, `check:file:line`
for Plugin Check) so identical re-scans produce 0 new Work tasks once the #5144
importer is wired up.

## Edge cases

**Plugin without Source-Ids yet** — set `source_id.required = false` in the
config. The check only validates SPDX and copyright headers. Introduce Source-Ids
in a separate task; set `required` back to `true` afterwards.

**Third-party files** — list their paths in `source.third_party_paths`. The tool
skips header checks for those paths and does not name them in `apply-headers`.

**Unconfirmed rights holder** — set `holder_status = "unconfirmed"`. The tool
emits an ERROR and `apply-headers` refuses to run until the holder is confirmed.
Never add a copyright notice for a holder whose status is unconfirmed.

**Embedded copy in another repo** — do not create a `provenance.json` for the
embedded copy; use the parent repo's config. Use `compare` to track drift from the
canonical version. The `--repo` flag points to the directory containing the copy.

**ZIP built outside the repo** — `release` and `manifest` always refuse to write
inside the plugin repo or the release ZIP. Use `${WP_PROVENANCE_PRIVATE_DIR}` (an
env var) or an explicit absolute path for the private location.

**CI usage** — pass `--format=github` for GitHub Actions annotation output. Exit
code 1 fails the workflow; 0 passes it.

**Private-license plugins** — set `license: "Private"` in the config. The tool
checks that headers match the config; it does not enforce open-source rules for
private code. Comparison mode still works.
