<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.scan


declare(strict_types=1);

/**
 * wp-plugin-check: run the official WordPress Plugin Check on a plugin ZIP.
 *
 *   php tools/wp-plugin-check/scan.php scan <plugin.zip> --out-dir=DIR [--commit=SHA] [--php=BIN] [--mariadb-bin=DIR] [--work-dir=DIR] [--keep-sandbox]
 *   php tools/wp-plugin-check/scan.php compare <old-report.json> <new-report.json> [--format=json|md]
 *   php tools/wp-plugin-check/scan.php import <report.json> --board=ID --api=URL --key-file=FILE [--state=FILE] [--apply] [--max-new=N]
 *   php tools/wp-plugin-check/scan.php status <slug> --reports-dir=DIR
 *
 * Exit codes of `scan`: 0 clean, 1 findings, 2 scan failed, 3 partial scan (not valid as release evidence).
 * See README.md.
 */

require_once __DIR__ . '/src/autoload.php';

use WpPluginCheck\Cli;

exit(Cli::main($argv, __DIR__));
