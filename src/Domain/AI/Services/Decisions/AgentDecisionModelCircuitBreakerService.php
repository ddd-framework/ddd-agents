<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Decisions;

use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The circuit breaker that keeps the decision model OUT of the loop after a transport failure: one
 * Redis key shared by every worker — process-static state does not cross the 8 turn workers (the same lesson as the
 * budget-check kill switch), so the open/closed state lives in the shared cache.
 *
 * - {@see trip()} opens it for N seconds after a timeout, a connection error or an HTTP 429 / 5xx / 529.
 * - While open, {@see isOpen()} is one sub-millisecond GET and the caller skips the HTTP call entirely.
 * - After the TTL the key is gone (half-open): the next call is allowed; its success calls {@see close()}, its
 *   failure trips it again.
 * - A cache outage never blocks: every method fails to the CLOSED reading, so the decision call is attempted and
 *   its own timeout bounds the damage.
 *
 * NOT a trip: HTTP 401 / 422 — our key or our question is wrong; the caller logs that loudly instead, because the
 * breaker would hide a misconfiguration.
 *
 * HARNESS MODE ({@see $harnessMode}, set only while a recorded-run comparison runs in a console process): the breaker
 * is never open and never touches the shared key — a recorded timeout must not bench the decision model for the
 * replay that follows (or for another run on the workspace), and a replay's played failure must not trip it either.
 * Off in every normal run; the worker-state reset clears it unless the harness's own message queue runs.
 *
 * Obtained via `DDDService::instance()->getService(AgentDecisionModelCircuitBreakerService::class)`.
 */
class AgentDecisionModelCircuitBreakerService extends Service
{
    public const string CACHE_KEY_OPEN_UNTIL = 'agent.decisionModel.breaker.openUntil';

    /** True only while a recorded-run comparison runs in this process: never open, the shared key never read or written. */
    public static bool $harnessMode = false;

    public function isOpen(): bool
    {
        if (self::$harnessMode) {
            return false;
        }
        try {
            $openUntil = $this->cache()->get(self::CACHE_KEY_OPEN_UNTIL);
            return is_numeric($openUntil) && (int)$openUntil > time();
        } catch (Throwable) {
            return false;
        }
    }

    public function trip(int $seconds, string $reason): void
    {
        if (self::$harnessMode) {
            DDDService::instance()->getLogger()->info('[AgentDecisionModel] circuit breaker trip SKIPPED (recorded-run harness)', ['seconds' => $seconds, 'reason' => $reason]);
            return;
        }
        $openUntil = time() + max(1, $seconds);
        // The reason is logged FIRST: a cache outage must not hide why the model was benched.
        DDDService::instance()->getLogger()->notice('[AgentDecisionModel] circuit breaker OPENED', [
            'seconds' => $seconds,
            'openUntil' => date('Y-m-d H:i:s', $openUntil),
            'reason' => $reason,
        ]);
        try {
            $this->cache()->set(self::CACHE_KEY_OPEN_UNTIL, $openUntil, max(1, $seconds));
        } catch (Throwable) {
            // the cache is unavailable — nothing to remember, the next call simply tries again
        }
    }

    public function close(): void
    {
        if (self::$harnessMode) {
            return;
        }
        try {
            if (!$this->isOpen()) {
                return;
            }
            $this->cache()->delete(self::CACHE_KEY_OPEN_UNTIL);
            DDDService::instance()->getLogger()->notice('[AgentDecisionModel] circuit breaker CLOSED');
        } catch (Throwable) {
            // ignore — the TTL closes it anyway
        }
    }

    protected function cache(): Cache
    {
        return Cache::instance(Cache::CACHE_GROUP_REDIS);
    }
}
