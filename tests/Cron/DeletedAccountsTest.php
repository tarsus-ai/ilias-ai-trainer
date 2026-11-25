<?php

declare(strict_types=1);

namespace TarsusIlias\Tests\Cron;

use TarsusIlias\Activity\Scoring;
use TarsusIlias\Client\ClientFactory;
use TarsusIlias\Client\ClientInterface;
use TarsusIlias\Client\TransportException;
use TarsusIlias\Cron\DeletedAccounts;
use TarsusIlias\Guardrail\Pipeline;
use TarsusIlias\Guardrail\StateStore;
use TarsusIlias\Support\UsageMeter;
use TarsusIlias\Tests\PluginTestCase;

/**
 * The scheduled cleanup of learners whose ILIAS account was deleted: local
 * data goes only once the brain has erased (or never had) the conversations.
 */
final class DeletedAccountsTest extends PluginTestCase
{
    private const GONE = 999901;

    /** @var array<int, array{0: string, 1: array<int, mixed>}> Statements run through manipulateF. */
    private array $writes = [];

    /** @var string[] Contexts the fake brain was asked to erase. */
    private array $erased = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writes = [];
        $this->erased = [];
    }

    protected function tearDown(): void
    {
        ClientFactory::override(null);
        parent::tearDown();
    }

    public function testErasesInTheBrainThenForgetsLocally(): void
    {
        $this->departedRows([[self::GONE, 333], [self::GONE, 339]], [[self::GONE, 333], [self::GONE, 0]]);
        $this->brain(null);

        $this->assertSame(['accounts' => 1, 'failed' => 0], DeletedAccounts::clean($this->pipeline()));
        $this->assertCount(2, $this->erased, 'one erasure per activity, none for the site-wide row');
        $this->assertSame(
            [
                ['DELETE FROM ' . Scoring::TABLE . ' WHERE usr_id = %s', [self::GONE]],
                ['UPDATE ' . UsageMeter::TABLE . ' SET usr_id = 0 WHERE usr_id = %s', [self::GONE]],
            ],
            $this->writes
        );
    }

    public function testKeepsEverythingWhileTheBrainIsDown(): void
    {
        $this->departedRows([[self::GONE, 333]], []);
        $this->brain(new TransportException(503, 'down'));

        $this->assertSame(['accounts' => 0, 'failed' => 1], DeletedAccounts::clean($this->pipeline()));
        $this->assertSame([], $this->writes);
    }

    public function testNothingStoredInTheBrainCountsAsErased(): void
    {
        $this->departedRows([[self::GONE, 333]], []);
        $this->brain(new TransportException(400, '', 'CONTEXT DOES NOT EXIST'));

        $this->assertSame(['accounts' => 1, 'failed' => 0], DeletedAccounts::clean($this->pipeline()));
        $this->assertCount(2, $this->writes);
    }

    public function testKillSwitchKeepsEverything(): void
    {
        $this->configure(['killswitch' => '1']);
        $this->departedRows([[self::GONE, 333]], []);
        $this->brain(null);

        $this->assertSame(['accounts' => 0, 'failed' => 1], DeletedAccounts::clean($this->pipeline()));
        $this->assertSame([], $this->erased);
        $this->assertSame([], $this->writes);
    }

    public function testNothingToDoWithoutDeletedAccounts(): void
    {
        $this->departedRows([], []);
        $this->brain(null);

        $this->assertSame(['accounts' => 0, 'failed' => 0], DeletedAccounts::clean($this->pipeline()));
        $this->assertSame([], $this->writes);
    }

    /**
     * The fake brain: erasures succeed, or throw the given exception.
     */
    private function brain(?\Throwable $failure): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('resetConversation')->willReturnCallback(function (string $contextid, string $userid) use ($failure): void {
            if ($failure !== null) {
                throw $failure;
            }
            $this->erased[] = $contextid;
        });
        ClientFactory::override($client);
    }

    /**
     * Guardrails with an in-memory state (closed breaker, full bucket).
     */
    private function pipeline(): Pipeline
    {
        $rows = [];
        $store = $this->createMock(StateStore::class);
        $store->method('mutate')->willReturnCallback(function (string $name, callable $mutator) use (&$rows): \stdClass {
            $row = $rows[$name] ?? (object) ['state' => 'closed', 'failurecount' => 0, 'opensince' => 0, 'tokens' => 0.0, 'lastrefill' => 0];
            $mutator($row);
            return $rows[$name] = $row;
        });
        return new Pipeline($store);
    }

    /**
     * Wrap the fake database: the departed-account query answers with the
     * given (usr_id, obj_id) pairs per table, and writes are recorded.
     *
     * @param array<int, array{0: int, 1: int}> $progress
     * @param array<int, array{0: int, 1: int}> $usage
     */
    private function departedRows(array $progress, array $usage): void
    {
        $inner = $GLOBALS['DIC']['ilDB'];
        $pending = new \SplObjectStorage();
        $db = $this->createMock(\ilDBInterface::class);
        $db->method('query')->willReturnCallback(function (string $sql) use ($inner, $pending, $progress, $usage): \ilDBStatement {
            if (strpos($sql, 'LEFT JOIN usr_data') === false) {
                return $inner->query($sql);
            }
            $pairs = strpos($sql, Scoring::TABLE) !== false ? $progress : $usage;
            $statement = $this->createMock(\ilDBStatement::class);
            $pending[$statement] = array_map(static fn(array $pair): array => ['usr_id' => $pair[0], 'obj_id' => $pair[1]], $pairs);
            return $statement;
        });
        $db->method('fetchAssoc')->willReturnCallback(function (\ilDBStatement $statement) use ($inner, $pending): ?array {
            if (!isset($pending[$statement])) {
                return $inner->fetchAssoc($statement);
            }
            $rows = $pending[$statement];
            $row = array_shift($rows);
            $pending[$statement] = $rows;
            return $row;
        });
        $db->method('manipulateF')->willReturnCallback(function (string $sql, array $types, array $values): int {
            $this->writes[] = [$sql, $values];
            return 1;
        });
        $db->method('queryF')->willReturnCallback(static fn(string $sql, array $types, array $values): \ilDBStatement => $inner->queryF($sql, $types, $values));
        $db->method('replace')->willReturnCallback(static fn(string $table, array $primary, array $other): int => $inner->replace($table, $primary, $other));
        $db->method('quote')->willReturnCallback(static fn($value, string $type): string => $inner->quote($value, $type));
        $GLOBALS['DIC']['ilDB'] = $db;
    }
}
