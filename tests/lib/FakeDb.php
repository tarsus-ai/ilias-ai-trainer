<?php

/**
 * The part of ilDBInterface the plugin uses, on an in-memory SQLite
 * database. Stricter than SQLite alone where MySQL would be: text values
 * longer than their column and NULL in a NOT NULL column are refused.
 *
 * @license GPL-3.0-or-later
 */

namespace TarsusIlias\Tests;

final class FakeDb implements \ilDBInterface
{
    public \PDO $pdo;

    /** @var array<string, array<string, array<string, mixed>>> Table => column => definition. */
    public array $schema = [];

    /** @var string[] Every statement, for assertions on what was run. */
    public array $statements = [];

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // MySQL hands every value back as a string.
        $this->pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        // MySQL's named locks, which the guardrail state uses.
        $this->pdo->sqliteCreateFunction('GET_LOCK', static fn($name, $timeout) => 1, 2);
        $this->pdo->sqliteCreateFunction('RELEASE_LOCK', static fn($name) => 1, 1);
    }

    // ------------------------------------------------------------------
    // Reading and writing
    // ------------------------------------------------------------------

    public function query(string $sql)
    {
        $this->statements[] = $sql;
        return $this->pdo->query($sql);
    }

    public function queryF(string $sql, array $types, array $values)
    {
        return $this->query($this->bind($sql, $types, $values));
    }

    public function manipulate(string $sql): int
    {
        $this->statements[] = $sql;
        return (int) $this->pdo->exec($sql);
    }

    public function manipulateF(string $sql, array $types, array $values): int
    {
        return $this->manipulate($this->bind($sql, $types, $values));
    }

    public function fetchAssoc($res): ?array
    {
        $row = $res->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param mixed $value
     */
    public function quote($value, string $type = ''): string
    {
        if ($value === null) {
            return 'NULL';
        }
        switch ($type) {
            case 'integer':
                return (string) (int) $value;
            case 'float':
                return var_export((float) $value, true);
            default:
                return $this->pdo->quote((string) $value);
        }
    }

    public function in(string $field, array $values, bool $negate = false, string $type = ''): string
    {
        if ($values === []) {
            return $negate ? ' 1=1 ' : ' 1=2 ';
        }
        $quoted = array_map(fn($value) => $this->quote($value, $type), $values);
        return $field . ($negate ? ' NOT' : '') . ' IN (' . implode(',', $quoted) . ')';
    }

    public function insert(string $table, array $values): int
    {
        $this->check($table, $values);
        $columns = array_keys($values);
        $quoted = array_map(fn($entry) => $this->quote($entry[1], $entry[0]), array_values($values));
        return $this->manipulate('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $quoted) . ')');
    }

    public function update(string $table, array $columns, array $where): int
    {
        $this->check($table, $columns);
        $set = [];
        foreach ($columns as $name => $entry) {
            $set[] = $name . ' = ' . $this->quote($entry[1], $entry[0]);
        }
        return $this->manipulate('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE ' . $this->where($where));
    }

    public function replace(string $table, array $primary, array $other): int
    {
        $this->check($table, $primary + $other);
        $this->manipulate('DELETE FROM ' . $table . ' WHERE ' . $this->where($primary));
        return $this->insert($table, $primary + $other);
    }

    public function nextId(string $table): int
    {
        $sequence = $table . '_seq';
        $this->manipulate('UPDATE ' . $sequence . ' SET sequence = sequence + 1');
        $row = $this->fetchAssoc($this->query('SELECT sequence FROM ' . $sequence));
        return (int) ($row['sequence'] ?? 0);
    }

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    public function tableExists(string $table): bool
    {
        $row = $this->fetchAssoc($this->query(
            "SELECT COUNT(*) cnt FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote($table)
        ));
        return (int) ($row['cnt'] ?? 0) > 0;
    }

    public function tableColumnExists(string $table, string $column): bool
    {
        $res = $this->query('PRAGMA table_info(' . $table . ')');
        while ($row = $this->fetchAssoc($res)) {
            if ((string) $row['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    public function createTable(string $table, array $fields, bool $dropfirst = false, bool $ignoreerrors = false): bool
    {
        $columns = [];
        foreach ($fields as $name => $definition) {
            $columns[] = $this->columnSql($name, $definition);
        }
        $this->manipulate('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')');
        $this->schema[$table] = $fields;
        return true;
    }

    public function addTableColumn(string $table, string $column, array $definition): bool
    {
        $this->manipulate('ALTER TABLE ' . $table . ' ADD COLUMN ' . $this->columnSql($column, $definition));
        $this->schema[$table][$column] = $definition;
        return true;
    }

    public function addPrimaryKey(string $table, array $columns): bool
    {
        $this->manipulate('CREATE UNIQUE INDEX ' . $table . '_pk ON ' . $table . ' (' . implode(', ', $columns) . ')');
        return true;
    }

    public function addIndex(string $table, array $columns, string $name = 'i1', bool $fulltext = false): bool
    {
        $this->manipulate('CREATE INDEX ' . $table . '_' . $name . ' ON ' . $table . ' (' . implode(', ', $columns) . ')');
        return true;
    }

    public function addUniqueConstraint(string $table, array $columns, string $name = 'con'): bool
    {
        $this->manipulate('CREATE UNIQUE INDEX ' . $table . '_' . $name . ' ON ' . $table . ' (' . implode(', ', $columns) . ')');
        return true;
    }

    public function dropTable(string $table, bool $errorifnotexisting = true): bool
    {
        $this->manipulate('DROP TABLE ' . ($errorifnotexisting ? '' : 'IF EXISTS ') . $table);
        unset($this->schema[$table]);
        return true;
    }

    public function createSequence(string $table, int $start = 1): bool
    {
        $this->manipulate('CREATE TABLE ' . $table . '_seq (sequence INTEGER NOT NULL)');
        $this->manipulate('INSERT INTO ' . $table . '_seq (sequence) VALUES (' . ($start - 1) . ')');
        return true;
    }

    public function sequenceExists(string $table): bool
    {
        return $this->tableExists($table . '_seq');
    }

    public function dropSequence(string $table): bool
    {
        $this->manipulate('DROP TABLE IF EXISTS ' . $table . '_seq');
        return true;
    }

    // ------------------------------------------------------------------
    // Helpers for tests
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return mixed
     */
    public function value(string $sql)
    {
        $row = $this->pdo->query($sql)->fetch(\PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    // ------------------------------------------------------------------

    private function bind(string $sql, array $types, array $values): string
    {
        $index = 0;
        $bound = preg_replace_callback('/%s/', function () use (&$index, $types, $values): string {
            if (!array_key_exists($index, $values)) {
                throw new \RuntimeException('queryF: more placeholders than values');
            }
            $quoted = $this->quote($values[$index], (string) ($types[$index] ?? 'text'));
            $index++;
            return $quoted;
        }, $sql);
        if ($index !== count($values)) {
            throw new \RuntimeException('queryF: ' . count($values) . ' values for ' . $index . ' placeholders');
        }
        return (string) $bound;
    }

    private function where(array $where): string
    {
        $parts = [];
        foreach ($where as $name => $entry) {
            $parts[] = $entry[1] === null ? $name . ' IS NULL' : $name . ' = ' . $this->quote($entry[1], $entry[0]);
        }
        return implode(' AND ', $parts);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function columnSql(string $name, array $definition): string
    {
        $map = ['integer' => 'INTEGER', 'float' => 'REAL', 'text' => 'TEXT', 'clob' => 'TEXT', 'timestamp' => 'TEXT', 'date' => 'TEXT', 'blob' => 'BLOB'];
        $type = (string) ($definition['type'] ?? 'text');
        if (!isset($map[$type])) {
            throw new \RuntimeException('unknown column type ' . $type . ' for ' . $name);
        }
        $sql = $name . ' ' . $map[$type];
        if (!empty($definition['notnull'])) {
            $sql .= ' NOT NULL';
        }
        if (array_key_exists('default', $definition) && $definition['default'] !== null) {
            $sql .= ' DEFAULT ' . (is_string($definition['default']) ? $this->pdo->quote($definition['default']) : $definition['default']);
        }
        return $sql;
    }

    /**
     * What MySQL in strict mode refuses: an unknown column, a value too
     * long for its column, NULL where the column forbids it.
     *
     * @param array<string, array{0: string, 1: mixed}> $values
     */
    private function check(string $table, array $values): void
    {
        if (!isset($this->schema[$table])) {
            return;
        }
        foreach ($values as $name => $entry) {
            $definition = $this->schema[$table][$name] ?? null;
            if ($definition === null) {
                throw new \RuntimeException("Unknown column {$table}.{$name}");
            }
            if ($entry[1] === null) {
                if (!empty($definition['notnull'])) {
                    throw new \RuntimeException("Column {$table}.{$name} cannot be null");
                }
                continue;
            }
            if (($definition['type'] ?? '') === 'text' && isset($definition['length'])
                    && mb_strlen((string) $entry[1]) > (int) $definition['length']) {
                throw new \RuntimeException("Data too long for column {$table}.{$name}");
            }
        }
    }
}
