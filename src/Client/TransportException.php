<?php

namespace TarsusIlias\Client;

/**
 * Transport-level failure from the Tarsus client (429 / 5xx / timeout).
 *
 * Carries the HTTP status so the guardrails pipeline can decide whether to
 * retry, back off, or trip the circuit breaker. The mock raises this to
 * exercise those paths deterministically.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
class TransportException extends \RuntimeException
{
    /** @var int The HTTP status code (0 for a timeout / connection failure). */
    public int $httpstatus;

    /** @var string The start of the response body on an HTTP error, for callers that recognise the brain's messages. */
    public string $body;

    public function __construct(int $httpstatus, string $detail = '', string $body = '')
    {
        $this->httpstatus = $httpstatus;
        $this->body = $body;
        parent::__construct(
            'Tarsus transport error (HTTP ' . $httpstatus . ')' . ($detail !== '' ? ': ' . $detail : ''),
            $httpstatus
        );
    }
}
