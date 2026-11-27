<?php

declare(strict_types=1);

namespace TarsusIlias\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use TarsusIlias\Client\TransportException;
use TarsusIlias\Service\Messages;
use TarsusIlias\Tests\PluginTestCase;

/**
 * "Test connection" names a refused key as such: Tarsus answers a key,
 * brain or license key it does not accept with an upper-case sentence; an
 * outage or any other refusal keeps the message "try again in a moment".
 */
final class MessagesTest extends PluginTestCase
{
    /**
     * @return array<string, array{int, string, bool}>
     */
    public static function answers(): array
    {
        return [
            'key unknown or inactive' => [400, 'UNKNOWN OR INACTIVE API KEY', true],
            'key unavailable' => [400, 'UNAVAILABLE API KEY', true],
            'brain unavailable' => [400, 'UNAVAILABLE BRAIN ID', true],
            'brain missing' => [400, 'BRAIN IS EITHER INEXISTENT OR NOT ACCESSIBLE', true],
            'brain memory missing' => [404, 'BRAIN MEMORY DOES NOT EXIST', true],
            'lower case' => [400, 'unknown or inactive api key', true],
            'no connection' => [0, '', false],
            'server error' => [500, 'UNABLE TO TRANSCRIBE AUDIO NOW. TRY AGAIN LATER', false],
            'other refusal' => [400, 'NO BITS WERE FOUND', false],
        ];
    }

    #[DataProvider('answers')]
    public function testRefusesAccessOnlyForAKeyBrainOrLicenseTarsusDoesNotAccept(int $status, string $body, bool $refused): void
    {
        $e = new TransportException($status, 'unexpected HTTP status calling awareness/list/memories', $body);
        self::assertSame($refused, Messages::refusesAccess($e));
    }
}
