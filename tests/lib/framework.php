<?php

/**
 * A minimal test framework: no dependencies, one process, plain PHP.
 *
 * Case files call test('name', function () { ... }). Every test starts
 * from a fresh world (database, configuration, user, repository) - see
 * World::reset(). Assertions throw; the runner reports and exits non-zero
 * when anything failed.
 *
 * @license GPL-3.0-or-later
 */

namespace TarsusIlias\Tests;

final class AssertionFailed extends \Exception
{
}

final class Skipped extends \Exception
{
}

final class Registry
{
    /** @var array<int, array{group: string, name: string, body: callable}> */
    public static array $tests = [];
    public static string $group = '';
    public static int $assertions = 0;
}

function test(string $name, callable $body): void
{
    Registry::$tests[] = ['group' => Registry::$group, 'name' => $name, 'body' => $body];
}

/**
 * @param mixed $value
 */
function dump($value): string
{
    if (is_string($value)) {
        return '"' . (strlen($value) > 400 ? substr($value, 0, 400) . '…' : $value) . '"';
    }
    $text = var_export($value, true);
    return strlen($text) > 600 ? substr($text, 0, 600) . '…' : $text;
}

function fail(string $message): void
{
    throw new AssertionFailed($message);
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function same($expected, $actual, string $what = ''): void
{
    Registry::$assertions++;
    if ($expected !== $actual) {
        fail(($what !== '' ? $what . ': ' : '') . 'expected ' . dump($expected) . ', got ' . dump($actual));
    }
}

/**
 * @param mixed $condition
 */
function ok($condition, string $what = ''): void
{
    Registry::$assertions++;
    if ($condition !== true) {
        fail(($what !== '' ? $what : 'condition') . ' is not true (' . dump($condition) . ')');
    }
}

/**
 * @param mixed $condition
 */
function not($condition, string $what = ''): void
{
    Registry::$assertions++;
    if ($condition !== false) {
        fail(($what !== '' ? $what : 'condition') . ' is not false (' . dump($condition) . ')');
    }
}

function contains(string $needle, string $haystack, string $what = ''): void
{
    Registry::$assertions++;
    if ($needle === '' || strpos($haystack, $needle) === false) {
        fail(($what !== '' ? $what . ': ' : '') . dump($needle) . ' not found in ' . dump($haystack));
    }
}

function lacks(string $needle, string $haystack, string $what = ''): void
{
    Registry::$assertions++;
    if ($needle !== '' && strpos($haystack, $needle) !== false) {
        fail(($what !== '' ? $what . ': ' : '') . dump($needle) . ' unexpectedly found in ' . dump($haystack));
    }
}

/**
 * Run a callable and return what it threw.
 *
 * @template T of \Throwable
 * @param class-string<T> $class
 * @return T
 */
function throws(string $class, callable $body, string $what = ''): \Throwable
{
    Registry::$assertions++;
    try {
        $body();
    } catch (\Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        fail(($what !== '' ? $what . ': ' : '') . 'expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
    }
    fail(($what !== '' ? $what . ': ' : '') . 'expected ' . $class . ', nothing was thrown');
    throw new \LogicException('unreachable');
}

function skip(string $reason): void
{
    throw new Skipped($reason);
}

/**
 * Run every registered test. Returns the process exit code.
 *
 * @param string[] $filters Substrings a "group: name" must contain (any).
 */
function run(array $filters = [], bool $verbose = false): int
{
    $passed = 0;
    $failed = [];
    $skipped = [];
    $started = microtime(true);
    $group = null;

    foreach (Registry::$tests as $test) {
        $label = $test['group'] . ': ' . $test['name'];
        if ($filters !== []) {
            $match = false;
            foreach ($filters as $filter) {
                if (stripos($label, $filter) !== false) {
                    $match = true;
                }
            }
            if (!$match) {
                continue;
            }
        }
        if ($verbose && $group !== $test['group']) {
            $group = $test['group'];
            echo "\n" . $group . "\n";
        }
        try {
            World::reset();
            ($test['body'])();
            $passed++;
            echo $verbose ? '  ok   ' . $test['name'] . "\n" : '.';
        } catch (Skipped $e) {
            $skipped[] = $label . ' (' . $e->getMessage() . ')';
            echo $verbose ? '  skip ' . $test['name'] . ' - ' . $e->getMessage() . "\n" : 's';
        } catch (\Throwable $e) {
            $where = '';
            foreach ($e->getTrace() as $frame) {
                if (isset($frame['file']) && strpos($frame['file'], DIRECTORY_SEPARATOR . 'cases' . DIRECTORY_SEPARATOR) !== false) {
                    $where = ' (' . basename($frame['file']) . ':' . $frame['line'] . ')';
                    break;
                }
            }
            $kind = $e instanceof AssertionFailed ? '' : get_class($e) . ': ';
            $failed[] = $label . $where . "\n      " . $kind . $e->getMessage()
                . ($e instanceof AssertionFailed ? '' : "\n      at " . $e->getFile() . ':' . $e->getLine());
            echo $verbose ? '  FAIL ' . $test['name'] . "\n" : 'F';
        }
    }

    echo "\n\n";
    foreach ($skipped as $entry) {
        echo 'SKIPPED ' . $entry . "\n";
    }
    foreach ($failed as $entry) {
        echo 'FAILED  ' . $entry . "\n";
    }
    printf(
        "%s%d passed, %d failed, %d skipped, %d assertions, %.1f s\n",
        ($skipped !== [] || $failed !== []) ? "\n" : '',
        $passed,
        count($failed),
        count($skipped),
        Registry::$assertions,
        microtime(true) - $started
    );
    return $failed === [] ? 0 : 1;
}
