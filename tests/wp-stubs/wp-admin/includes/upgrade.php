<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.wp-stubs-upgrade

/**
 * Stand-in for WordPress's wp-admin/includes/upgrade.php, reached through the
 * ABSPATH that tests/bootstrap.php points at tests/wp-stubs/.
 *
 * dbDelta() here does not touch a database: it records every statement it is
 * given so a test can assert what a plugin update would ask WordPress to run
 * (and, just as important for translation safety, what it would not).
 * Set $GLOBALS['stm_test_dbdelta_throw'] to make it throw, to exercise the
 * log-don't-throw path in Database::create_tables().
 */

if (!function_exists('dbDelta')) {
    function dbDelta($queries = '', $execute = true)
    {
        if (!empty($GLOBALS['stm_test_dbdelta_throw'])) {
            throw new \RuntimeException('simulated dbDelta failure');
        }
        $GLOBALS['stm_test_dbdelta_log'][] = $queries;
        return [];
    }
}
