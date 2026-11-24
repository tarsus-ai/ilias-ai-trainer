<?php

namespace TarsusIlias\Guardrail;

use TarsusIlias\Client\ReplayException;
use TarsusIlias\Client\TransportException;
use TarsusIlias\Support\Budget;
use TarsusIlias\Support\Config;

/**
 * Client-side guardrails for every outbound Tarsus call.
 *
 * Gate order: kill switch -> circuit breaker -> monthly credit limit ->
 * rate limit -> send. A success resets the failure counter; repeated
 * outages (no answer, 5xx) trip the breaker, a refused request (4xx) does
 * not. The monthly limit only gates calls that may cost credits, judged
 * by the brain's own usage figure: reading the feed or the figures stays
 * possible when the month's credits are spent. The pipeline never sleeps: retries
 * are the caller's business.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
class Pipeline
{
    /** Consecutive transport failures that trip the breaker. */
    public const FAILURE_THRESHOLD = 5;

    /** Seconds the breaker stays open before a trial call. */
    public const BREAKER_COOLDOWN = 300;

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
     * @param bool $charged Whether the call may cost AI credits: it is
     *                      refused once the month's limit is reached, by
     *                      the brain's own usage figure. Reading back
     *                      (feed, statistics, sources) is never refused.
     * @return T
     * @throws BlockedException  When a gate refuses the call.
     * @throws TransportException When the call fails at the transport level.
     */
    public function execute(callable $call, bool $charged = false)
    {
        $this->guardKillswitch();
        $this->guardBreaker();
        $this->guardBudget($charged);
        $this->guardRatelimit();

        try {
            $result = $call();
        } catch (TransportException $e) {
            // Only an outage (no answer, 5xx) trips the breaker; a 4xx is
            // Tarsus refusing this one request and says nothing about the
            // service.
            if ($e->isOutage()) {
                $this->recordFailure();
            }
            throw $e;
        } catch (ReplayException $e) {
            // HTTP 409 is a normal answer of Build knowledge.
            $this->recordSuccess();
            throw $e;
        }

        $this->recordSuccess();

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
     * Refuse a call that may cost credits once the month's limit is
     * reached - by what the brain says it charged, not by an estimate.
     */
    private function guardBudget(bool $charged): void
    {
        if ($charged && Budget::exhausted(true)) {
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

    private function recordSuccess(): void
    {
        $this->store->mutate('breaker', function (\stdClass $row): void {
            $row->failurecount = 0;
            $row->state = 'closed';
        });
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
