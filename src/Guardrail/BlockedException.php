<?php

namespace TarsusIlias\Guardrail;

/**
 * Raised when a guardrail gate refuses an outbound call.
 *
 * The reason ('killswitch', 'breaker', 'budget', 'ratelimit') lets callers
 * decide whether to hold, back off, or surface an error.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
class BlockedException extends \RuntimeException
{
    public string $reason;

    public function __construct(string $reason)
    {
        $this->reason = $reason;
        parent::__construct('Tarsus call blocked: ' . $reason);
    }
}
