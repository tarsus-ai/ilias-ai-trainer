<?php

declare(strict_types=1);

namespace TarsusIlias\Tests;

use PHPUnit\Framework\TestCase;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Ilias;

/**
 * A test case with a fake ILIAS around the plugin: an in-memory
 * configuration table behind the database interface, and a plugin whose
 * texts are their keys. Enough for everything in Support/ that reads the
 * configuration; the ledger and the brain stay out (the end-to-end suite
 * covers them against real ILIAS installations).
 */
abstract class PluginTestCase extends TestCase
{
    /** @var array<string, string> The fake rep_robj_xtar_cfg table. */
    protected array $cfg = [];

    /** @var \SplObjectStorage<object, array<int, array<string, mixed>>> Rows behind each statement. */
    private \SplObjectStorage $rows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = [];
        $this->rows = new \SplObjectStorage();
        Config::reset();
        $this->installFakeIlias();
    }

    /**
     * Store configuration values as the plugin would and forget the
     * request cache, so the next read sees them.
     *
     * @param array<string, string|null> $values
     */
    protected function configure(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($this->cfg[$key]);
            } else {
                $this->cfg[$key] = $value;
            }
        }
        Config::reset();
    }

    private function installFakeIlias(): void
    {
        $db = $this->createMock(\ilDBInterface::class);
        $db->method('query')->willReturnCallback(function (string $sql): \ilDBStatement {
            if (stripos($sql, 'FROM ' . Config::TABLE) !== false) {
                $rows = [];
                foreach ($this->cfg as $key => $value) {
                    $rows[] = ['cfg_key' => $key, 'cfg_value' => $value];
                }
                return $this->statement($rows);
            }
            return $this->statement([]);
        });
        $db->method('queryF')->willReturnCallback(function (string $sql, array $types, array $values): \ilDBStatement {
            if (stripos($sql, 'FROM ' . Config::TABLE) !== false) {
                $key = (string) ($values[0] ?? '');
                return $this->statement(array_key_exists($key, $this->cfg) ? [['cfg_value' => $this->cfg[$key]]] : []);
            }
            // Anything else (the ledger) is empty in unit tests.
            return $this->statement([['total' => 0, 'cnt' => 0]]);
        });
        $db->method('fetchAssoc')->willReturnCallback(function (\ilDBStatement $statement): ?array {
            $rows = $this->rows[$statement] ?? [];
            if ($rows === []) {
                return null;
            }
            $row = array_shift($rows);
            $this->rows[$statement] = $rows;
            return $row;
        });
        $db->method('replace')->willReturnCallback(function (string $table, array $primary, array $other): int {
            if ($table === Config::TABLE) {
                $this->cfg[(string) $primary['cfg_key'][1]] = (string) $other['cfg_value'][1];
            }
            return 1;
        });
        $db->method('manipulateF')->willReturnCallback(function (string $sql, array $types, array $values): int {
            if (stripos($sql, 'DELETE FROM ' . Config::TABLE) !== false) {
                unset($this->cfg[(string) ($values[0] ?? '')]);
            }
            return 1;
        });
        $db->method('quote')->willReturnCallback(static fn($value, string $type): string => $type === 'integer' ? (string) (int) $value : "'" . addslashes((string) $value) . "'");

        $plugin = $this->createMock(\ilPlugin::class);
        $plugin->method('txt')->willReturnCallback(static fn(string $key): string => $key);

        $dic = new \ILIAS\DI\Container();
        $dic['ilDB'] = $db;
        $GLOBALS['DIC'] = $dic;
        Ilias::setPlugin($plugin);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function statement(array $rows): \ilDBStatement
    {
        $statement = $this->createMock(\ilDBStatement::class);
        $this->rows[$statement] = $rows;
        return $statement;
    }
}
