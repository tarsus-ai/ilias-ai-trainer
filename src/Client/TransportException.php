<?php

namespace TarsusIlias\Client;

/**
 * A failed call to Tarsus: no answer at all (status 0: connection failed,
 * timeout), or an HTTP status outside 2xx with the start of Tarsus's own
 * message in the body.
 *
 * Only an outage (no answer, 5xx) counts toward the circuit breaker; a 4xx
 * is Tarsus answering that this one request cannot be served, which says
 * nothing about the service. The mock raises this to exercise those paths
 * deterministically.
 *
 * @author  Tarsus GmbH
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

    /**
     * Whether Tarsus could not be reached or failed itself: no answer, a
     * timeout or a 5xx. Only these trip the circuit breaker.
     */
    public function isOutage(): bool
    {
        return $this->httpstatus === 0 || $this->httpstatus >= 500;
    }

    /**
     * Whether Tarsus's message contains a phrase (case-insensitive). Tarsus
     * answers refusals with an upper-case sentence such as "UNKNOWN OR
     * INACTIVE API KEY" as the whole body.
     */
    public function says(string $phrase): bool
    {
        return $phrase !== '' && stripos($this->body, $phrase) !== false;
    }
}
