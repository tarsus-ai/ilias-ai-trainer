<?php

namespace TarsusIlias\Guardrail;

use TarsusIlias\Client\TransportException;
use TarsusIlias\Support\Config;

/**
 * Client-side guardrails for every outbound Tarsus call.
 *
 * Gate order: kill switch -> circuit breaker -> credit budget -> rate
 * limit -> send. A success resets the failure counter and charges the
 * budget; repeated transport failures trip the breaker. The pipeline never
 * sleeps: retries are the caller's business.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
class Pipeline
{
    /** Consecutive transport failures that trip the breaker. */
    public const FAILURE_THRESHOLD = 5;

    /** Seconds the breaker stays open before a trial call. */
    public const BREAKER_COOLDOWN = 300;

    /** Length of a credit-budget period in seconds. */
    public const BUDGET_PERIOD = 86400;

    private StateStore $store;

    public function __construct(?StateStore $store = null)
    {
        $this->store = $store ?? new StateStore();
    }

    /**
     * Run a guarded outbound call.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     * @throws BlockedException  When a gate refuses the call.
     * @throws TransportException When the call fails at the transport level.
     */
    public function execute(callable $call, int $estimatedcredits = 0)
    {
        $this->guardKillswitch();
        $this->guardBreaker();
        $this->guardBudget($estimatedcredits);
        $this->guardRatelimit();

        try {
            $result = $call();
        } catch (TransportException $e) {
            $this->recordFailure();
            throw $e;
        }

        $credits = (is_array($result) && isset($result['credits'])) ? (int) $result['credits'] : 0;
        $this->recordSuccess($credits);

        return $result;
    }

    private function guardKillswitch(): void
    {
        if (Config::killswitch()) {
            throw new BlockedException('killswitch');
        }
    }

    /**
     * Fail fast while the circuit breaker is open; close it after the cooldown.
     */
    private function guardBreaker(): void
    {
        $this->store->mutate('breaker', function (\stdClass $row): void {
            if ($row->state !== 'open') {
                return;
            }
            if (time() - (int) $row->opensince < self::BREAKER_COOLDOWN) {
                throw new BlockedException('breaker');
            }
            // Cooldown elapsed: allow a trial call by closing the breaker.
            $row->state = 'closed';
            $row->failurecount = 0;
        });
    }

    /**
     * Refuse the call when it would exceed the credit budget of the period.
     */
    private function guardBudget(int $estimatedcredits): void
    {
        $budget = Config::creditBudget();

        $row = $this->store->mutate('budget', function (\stdClass $row): void {
            $now = time();
            if ((int) $row->periodstart === 0 || $now - (int) $row->periodstart >= self::BUDGET_PERIOD) {
                $row->creditsused = 0;
                $row->periodstart = $now;
            }
        });

        if ((int) $row->creditsused + $estimatedcredits > $budget) {
            throw new BlockedException('budget');
        }
    }

    /**
     * Take a token from the refilling bucket, or refuse the call.
     */
    private function guardRatelimit(): void
    {
        $capacity = Config::rateLimit();
        $ratepersecond = $capacity / 60;

        $this->store->mutate('bucket', function (\stdClass $row) use ($capacity, $ratepersecond): void {
            $now = time();
            $last = (int) $row->lastrefill;
            $tokens = (float) $row->tokens;

            if ($last <= 0) {
                // First use: start with a full bucket.
                $tokens = $capacity;
                $last = $now;
            }

            $tokens = min((float) $capacity, $tokens + max(0, $now - $last) * $ratepersecond);
            $row->lastrefill = $now;

            if ($tokens < 1) {
                $row->tokens = $tokens;
                throw new BlockedException('ratelimit');
            }

            $row->tokens = $tokens - 1;
        });
    }

    private function recordFailure(): void
    {
        $this->store->mutate('breaker', function (\stdClass $row): void {
            $row->failurecount = (int) $row->failurecount + 1;
            if ($row->failurecount >= self::FAILURE_THRESHOLD) {
                $row->state = 'open';
                $row->opensince = time();
            }
        });
    }

    private function recordSuccess(int $credits): void
    {
        $this->store->mutate('breaker', function (\stdClass $row): void {
            $row->failurecount = 0;
            $row->state = 'closed';
        });

        if ($credits > 0) {
            $this->store->mutate('budget', function (\stdClass $row) use ($credits): void {
                $row->creditsused = (int) $row->creditsused + $credits;
            });
        }
    }

    /**
     * A snapshot of the guardrail state for the admin status page.
     *
     * @return array<string, \stdClass>
     */
    public function snapshot(): array
    {
        return [
            'breaker' => $this->store->get('breaker'),
            'budget' => $this->store->get('budget'),
            'bucket' => $this->store->get('bucket'),
        ];
    }

    /**
     * Close the breaker by hand (admin action).
     */
    public function resetBreaker(): void
    {
        $this->store->mutate('breaker', function (\stdClass $row): void {
            $row->failurecount = 0;
            $row->state = 'closed';
            $row->opensince = 0;
        });
    }
}
