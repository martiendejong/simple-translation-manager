<?php
/**
 * Minimal in-memory stand-in for $wpdb.
 *
 * insert()/update()/delete() take structured arrays exactly like the real
 * wpdb, so no SQL is parsed for those. get_var()/get_results()/get_col()
 * receive a raw (already-`prepare()`d) SQL string, so this class implements
 * just enough of a SELECT parser (single table, `col = val` conditions
 * joined by AND) to cover the queries this plugin actually issues.
 */

namespace STM\Tests\Fakes;

class FakeWpdb {

    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    private $tables = [];
    private $nextId = [];

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[dsf]/', function ($m) use (&$i, $args) {
            $val = $args[$i++] ?? '';
            switch ($m[0]) {
                case '%d': return (string) intval($val);
                case '%f': return (string) floatval($val);
                default:   return "'" . addslashes((string) $val) . "'";
            }
        }, $query);
    }

    public function insert($table, $data) {
        $table = $this->stripPrefix($table);
        $id = ($this->nextId[$table] ?? 0) + 1;
        $this->nextId[$table] = $id;
        $this->tables[$table][$id] = array_merge(['id' => $id], $data);
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where) {
        $table = $this->stripPrefix($table);
        $count = 0;
        foreach ($this->tables[$table] ?? [] as $id => $row) {
            if ($this->matches($row, $where)) {
                $this->tables[$table][$id] = array_merge($row, $data);
                $count++;
            }
        }
        return $count;
    }

    public function delete($table, $where) {
        $table = $this->stripPrefix($table);
        $count = 0;
        foreach ($this->tables[$table] ?? [] as $id => $row) {
            if ($this->matches($row, $where)) {
                unset($this->tables[$table][$id]);
                $count++;
            }
        }
        return $count;
    }

    public function get_var($query) {
        $rows = $this->select($query);
        if (!$rows) {
            return null;
        }
        $row = (array) $rows[0];
        return reset($row);
    }

    public function get_col($query) {
        $rows = $this->select($query);
        $col = [];
        foreach ($rows as $row) {
            $arr = (array) $row;
            $col[] = reset($arr);
        }
        return array_values(array_unique($col));
    }

    public function get_results($query, $output = OBJECT) {
        $rows = $this->select($query);
        if ($output === ARRAY_A) {
            return array_map(function ($r) { return (array) $r; }, $rows);
        }
        return array_map(function ($r) { return (object) $r; }, $rows);
    }

    /** Seed a row directly, bypassing insert()'s auto-id bookkeeping quirks. */
    public function seed($table, array $row) {
        $table = $this->stripPrefix($table);
        $id = $row['id'] ?? (($this->nextId[$table] ?? 0) + 1);
        $this->nextId[$table] = max($id, $this->nextId[$table] ?? 0);
        $this->tables[$table][$id] = array_merge(['id' => $id], $row);
        return $id;
    }

    public function all($table) {
        $table = $this->stripPrefix($table);
        return array_values($this->tables[$table] ?? []);
    }

    private function stripPrefix($table) {
        return (strpos($table, $this->prefix) === 0) ? substr($table, strlen($this->prefix)) : $table;
    }

    private function matches($row, $where) {
        foreach ($where as $col => $val) {
            if (!array_key_exists($col, $row) || (string) $row[$col] !== (string) $val) {
                return false;
            }
        }
        return true;
    }

    private function select($sql) {
        if (!preg_match(
            '/SELECT\s+(?:DISTINCT\s+)?(.+?)\s+FROM\s+(\S+)(?:\s+WHERE\s+(.+?))?(?:\s+ORDER\s+BY.*)?(?:\s+LIMIT.*)?$/is',
            trim($sql),
            $m
        )) {
            return [];
        }

        $colsRaw  = trim($m[1]);
        $table    = $this->stripPrefix(trim($m[2]));
        $whereRaw = isset($m[3]) ? trim($m[3]) : '';

        $rows = array_values($this->tables[$table] ?? []);

        if ($whereRaw !== '') {
            foreach (preg_split('/\s+AND\s+/i', $whereRaw) as $cond) {
                if (!preg_match('/^(\w+)\s*=\s*(.+)$/', trim($cond), $cm)) {
                    continue;
                }
                $col = $cm[1];
                $val = trim($cm[2]);
                if (preg_match("/^'(.*)'$/", $val, $vm)) {
                    $val = stripslashes($vm[1]);
                } elseif (is_numeric($val)) {
                    $val = $val + 0;
                }
                $rows = array_values(array_filter($rows, function ($row) use ($col, $val) {
                    return array_key_exists($col, $row) && (string) $row[$col] === (string) $val;
                }));
            }
        }

        if ($colsRaw !== '*') {
            $cols = array_map('trim', explode(',', $colsRaw));
            $rows = array_map(function ($row) use ($cols) {
                $out = [];
                foreach ($cols as $c) {
                    $out[$c] = $row[$c] ?? null;
                }
                return $out;
            }, $rows);
        }

        return $rows;
    }
}
