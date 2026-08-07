<?php
/**
 * FakeWpdb variant that records every final SQL string handed to a query
 * method, so tests can assert on the *exact* text sent to the database
 * (e.g. that user input was escaped/quoted, not raw-interpolated) without
 * depending on FakeWpdb::select()'s minimal single-table parser, which
 * cannot parse the JOINs these queries use.
 */

namespace STM\Tests\Fakes;

class RecordingWpdb extends FakeWpdb {

    /** @var string[] every query string passed to get_results/get_var/get_row, in call order */
    public $queries = [];

    public function get_results($query, $output = OBJECT) {
        $this->queries[] = $query;
        return parent::get_results($query, $output);
    }

    public function get_var($query) {
        $this->queries[] = $query;
        // FakeWpdb::select() has no real COUNT(*) aggregation (it only
        // projects columns), so a raw COUNT(*) call always returns null.
        // Rewrite to `*` and count the matching rows instead, so callers
        // that gate follow-up queries on "count > 0" can be exercised.
        // (Still returns 0 for queries containing a JOIN — FakeWpdb's
        // single-table parser can't match those either way.)
        if (stripos($query, 'COUNT(*)') !== false) {
            $rewritten = str_ireplace('COUNT(*)', '*', $query);
            return (string) count(parent::get_results($rewritten));
        }
        return parent::get_var($query);
    }

    public function get_row($query, $output = OBJECT) {
        $this->queries[] = $query;
        return parent::get_row($query, $output);
    }

    public function lastQuery() {
        return end($this->queries);
    }
}
