<?php

/**
 * Starts tests/server/router.php on a free local port and gives the tests
 * the base URL, the recorded requests and a way to make endpoints fail.
 *
 * @license GPL-3.0-or-later
 */

namespace TarsusIlias\Tests;

final class TarsusServer
{
    /** @var resource|null */
    private static $process = null;
    private static string $url = '';
    private static string $log = '';
    private static string $control = '';

    /**
     * The base URL of the fake API, starting the server on first use.
     */
    public static function url(): string
    {
        if (self::$process === null) {
            self::start();
        }
        return self::$url;
    }

    private static function start(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException('no free port: ' . $error);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        self::$log = (string) tempnam(sys_get_temp_dir(), 'xtarlog');
        self::$control = (string) tempnam(sys_get_temp_dir(), 'xtarctl');
        file_put_contents(self::$control, '{}');

        $command = [PHP_BINARY, '-d', 'enable_post_data_reading=0', '-d', 'display_errors=0',
            '-S', '127.0.0.1:' . $port, PLUGIN_ROOT . '/tests/server/router.php'];
        $env = ['TARSUS_FAKE_LOG' => self::$log, 'TARSUS_FAKE_CONTROL' => self::$control, 'PATH' => (string) getenv('PATH')];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the fake Tarsus API');
        }
        self::$process = $process;
        self::$url = 'http://127.0.0.1:' . $port;
        register_shutdown_function([self::class, 'stop']);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('the fake Tarsus API did not come up');
    }

    public static function stop(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
        foreach ([self::$log, self::$control] as $file) {
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Forget the recorded requests and every arranged failure.
     */
    public static function clear(): void
    {
        if (self::$process === null) {
            return;
        }
        file_put_contents(self::$log, '');
        file_put_contents(self::$control, '{}');
    }

    /**
     * Arrange how the server answers: 'fail' => [path => [status, body]],
     * 'raw' => [path => literal body], 'usage' => [...], 'received' =>
     * bool, 'trackstatus' => 'done'|'pending'|'failed'.
     *
     * @param array<string, mixed> $control
     */
    public static function arrange(array $control): void
    {
        self::url();
        file_put_contents(self::$control, (string) json_encode($control));
    }

    /**
     * The requests received since the last clear(), oldest first.
     *
     * @return array<int, array{method: string, path: string, fields: array<string, string>, files: array<string, array<string, mixed>>, agent: string}>
     */
    public static function requests(?string $pathcontains = null): array
    {
        if (self::$process === null) {
            return [];
        }
        $requests = [];
        foreach (file(self::$log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $request = json_decode($line, true);
            if (is_array($request) && ($pathcontains === null || strpos($request['path'], $pathcontains) !== false)) {
                $requests[] = $request;
            }
        }
        return $requests;
    }

    /**
     * The only request received (or the only one whose path matches).
     *
     * @return array{method: string, path: string, fields: array<string, string>, files: array<string, array<string, mixed>>, agent: string}
     */
    public static function only(?string $pathcontains = null): array
    {
        $requests = self::requests($pathcontains);
        if (count($requests) !== 1) {
            fail('expected exactly one request' . ($pathcontains !== null ? ' to ' . $pathcontains : '') . ', got ' . count($requests));
        }
        return $requests[0];
    }
}
