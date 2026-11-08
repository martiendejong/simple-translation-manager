<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.entry

declare(strict_types=1);

/**
 * Provenance checks for WordPress plugins (task 5184). Development tooling: it is not part of any plugin ZIP,
 * never runs inside WordPress and never talks to the network. See README.md in this folder.
 *
 *   php tools/wp-provenance/provenance.php check --config=provenance.json
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/src/autoload.php';

exit(\WpProvenance\Cli::main($argv));
