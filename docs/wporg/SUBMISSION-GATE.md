# WordPress.org submission gate for Simple Translation Manager

This is the checklist that must be green before anyone submits STM to the WordPress.org plugin directory. It is the
release parent for the provenance work (`docs/PROVENANCE.md`, the provenance check): those tasks feed this gate, they are
not a second release track.

Nothing in this repository submits the plugin. **A person (Martien) approves the submission.**

Current state is for ZIP `0856487161318e89a63c6152410861d18abd0f47b2cf4f0897fd68d2b89846f3` (STM 1.3.2). Any change to a
shipped file (`includes/`, `templates/`, `assets/`, `readme.txt`, the main file, `uninstall.php`, `docs/editors/pdf`)
changes the hash and turns every "evidence" line below back to open until it is run again.

| # | Gate | Evidence | State |
|---|---|---|---|
| 1 | Current official Plugin Check result for the exact ZIP: 0 errors, every warning fixed or individually justified, no global suppression | `docs/wporg/final-08564871/report.json`, `FINAL.md`; re-run: `php tools/wp-plugin-check/scan.php check <zip> --reports-dir=<dir>` | Green for the hash above |
| 2 | Readme, headers, version and license agree | Stable tag = header Version = `STM_VERSION` = `VERSION` = 1.3.2; `Tested up to: 7.1`; `Requires at least: 6.0`; `Requires PHP: 7.4`; `License: GPLv2 or later` in the header, in `readme.txt` and as SPDX in every own file; changelog 1.3.2 to 1.0.0; Plugin Check passes the readme and header checks | Green |
| 3 | Reproducible release artifact | `php bin/provenance.php release . --commit=<sha> --out-dir=<dir outside the repo>` gives the same bytes for the same shipped content; `verify-release` rebuilds and compares; the ZIP is an allowlist of runtime paths | Green; the tag `v1.3.2` does not exist yet and no signing key exists on the build host (see below) |
| 4 | Privacy and security review | REST permission matrix for anonymous, subscriber, editor and administrator, in process and over HTTP; draft, private and password-protected content not exposed; nonce-protected admin forms; XLIFF without external entities; uninstall removes tables, options, API keys, transients (`final-08564871/lifecycle.md`, 280 of 280). External services (OpenAI, DeepL) and the `stm_lang` cookie are disclosed in `readme.txt` | Green for what is automated; a human read of the readme wording is still open |
| 5 | No site-specific secrets or endpoints in the ZIP | Scanner ZIP hygiene: 0 development files, 0 secret-looking strings; external hosts are only the two translation providers, their documentation links, `www.gnu.org` and the Plugin URI; a customer name was removed from one code comment | Green |
| 6 | Install and update keep translations | Clean install, update from 1.3.0 and 1.3.1, uninstall with and without "Keep data", reinstall over kept data, in a throwaway WordPress with synthetic data | Green (1.2.x and older cannot be built: the 1.2.1 tag has no `readme.txt`) |
| 7 | Open STM pull requests that touch shipped files are merged or excluded before the final scan | #39 (Pods) and #43 (stale detection) are **excluded**: the ZIP is built from `master` plus PR #45 only. If either merges first, rebuild the ZIP and run gate 1 and 6 again | Excluded; decision for Martien |
| 8 | A human approves the submission | Martien | **Open** |

## Open items for a human (not done, not automatable here)

1. **Approve the submission** (gate 8) and decide on #39 and #43 (gate 7) before the final ZIP is built.
2. **WordPress.org account:** `Contributors: martiendejong` in `readme.txt` must be the real WordPress.org username that
   will own the plugin. Plugin Check cannot confirm who owns an account.
3. **Directory assets:** icon, banner and the two screenshots that `readme.txt` lists live in the SVN `assets/` folder of
   the plugin on WordPress.org, not in the ZIP. None are prepared. Without them the screenshot captions show without
   images.
4. **Tag and signature:** create and sign the `v1.3.2` tag from the commit that the final ZIP was built from. This host has
   no signing key; the release record states that.
5. **Rights holder wording** for the copyright header (task 5182): ProsperGenics is not named until its legal name is
   confirmed.
6. **Review by the plugin team:** the WordPress.org review is a separate human gate. The 184 written suppression
   reasons are there so that a reviewer can see why each one exists; expect questions about direct queries on custom
   tables and about the one AI-provider warning.

## How to re-run everything

```
php bin/provenance.php release . --commit=<sha> --out-dir=<private dir outside the repo> --published=<YYYY-MM-DD>
php tools/wp-plugin-check/scan.php check <private dir>/simple-translation-manager-<version>.zip --reports-dir=<reports dir> --commit=<sha>
php tools/wp-plugin-check/scan.php lifecycle <zip> --script=tests/lifecycle/stm-lifecycle.php --from=<old 1.3.0 zip>,<old 1.3.1 zip> --out-dir=<dir>
vendor/bin/phpunit
```

`check` prints the delta against the previous complete scan; with `--board=<id> --key-file=<file>` it also imports the
findings into Jengo Work (a dry run unless `--apply`). See `tools/wp-plugin-check/README.md`.
