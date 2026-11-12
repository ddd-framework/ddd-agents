<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Traits\Conversations;

use DDD\Domain\AI\Entities\Environment\AgentApplicationNames;
use DDD\Domain\AI\Entities\Environment\AgentEnvironmentVariables;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Libs\Config;

/**
 * Universal async-trace logging for the agent's AI services + message handlers. Emits ONE greppable trace line
 * ({@see AgentApplicationNames::getTraceLinePrefix()}) per call — but ONLY when the `AGENT_TRACE` env flag (or the
 * further name the application declares for it, {@see AgentApplicationNames::getFurtherEnvironmentVariableName()}) is on.
 *
 * WHY THE FLAG (different logging local vs dev/prod + the no-spam guarantee): the flag is passed ONLY for a local run —
 * inline on the command (`AGENT_TRACE=1 php bin/console …`), so it is absent on the servers (their supervisor commands
 * never set it). There, every `aiTrace()` call is a cheap early-return → zero output, zero cost. Defense-in-depth: even
 * if it leaked, this logs at INFO while a production monolog handler at `level: error` drops it. LOCALLY each async
 * boundary emits a structured `<prefix> <event> {context}` line — surface it by running a worker in the foreground
 * (`messenger:consume <transport> -vv`) and grepping the prefix.
 *
 * READING THE FLAG (load-bearing — get it wrong and tracing silently never fires): with PHP's `variables_order=GPCS`
 * an inline var lands in `$_SERVER` and `getenv()` but NOT in `$_ENV`. {@see Config::getEnv()} reads ONLY `$_ENV` /
 * `Config::$env`, so it MISSES an inline value — it only sees a value set via a `.env*` FILE. So the raw process env
 * (`$_SERVER` / `getenv`) is read FIRST and `Config::getEnv` is the fallback for a value set via a `.env*` file.
 * Truthy = `1` / `'1'` / `true` / `'true'`.
 *
 * USE at every async boundary — dispatch, handler entry, the decision points, persist, defer, error — so the entire
 * async path is debuggable end-to-end locally. Keep $event a short stable dotted token (`curate.received`,
 * `turn.settled`, `embedding.persisted`) and put the variable detail in $context (greppable + structured).
 */
trait AITraceLogTrait
{
    /**
     * @param array<string, mixed> $context
     */
    protected function aiTrace(string $event, array $context = []): void
    {
        if (!self::isAgentTraceEnabled()) {
            return; // local-only: AGENT_TRACE is passed inline for a local run — absent on dev/prod → no-op there
        }
        $suffix = $context !== []
            ? ' ' . (string)json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';
        DDDService::instance()->getLogger()->info(AgentApplicationNames::getTraceLinePrefix() . ' ' . $event . $suffix);
    }

    /**
     * True when the inline `AGENT_TRACE` flag (or the further name the application declares for it) is truthy. Reads the RAW process env (`$_SERVER` / `getenv`) where an inline
     * `VAR=val php …` prefix actually lands under `variables_order=GPCS`, then falls back to {@see Config::getEnv()} for
     * a `.env*`-file value. See the trait docblock for why Config::getEnv alone is insufficient for the inline case.
     */
    protected static function isAgentTraceEnabled(): bool
    {
        $rawValue = AgentEnvironmentVariables::readRawProcessValue(AgentEnvironmentVariables::TRACE);
        if ($rawValue !== null) {
            $normalizedValue = strtolower((string)$rawValue);
            return $normalizedValue === '1' || $normalizedValue === 'true';
        }
        // Fallback: value set the old way via a .env* file (Config::getEnv coerces '1'/'true' → int/bool).
        foreach (AgentEnvironmentVariables::getNamesInReadOrder(AgentEnvironmentVariables::TRACE) as $environmentVariableName) {
            $configValue = Config::getEnv($environmentVariableName);
            if ($configValue !== null && $configValue !== '') {
                return $configValue === 1 || $configValue === true || $configValue === '1';
            }
        }
        return false;
    }
}
