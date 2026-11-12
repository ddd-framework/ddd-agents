<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\OutboundRecipients;

/**
 * One open hold of "this process is executing a tool call of an eval run" — taken by
 * {@see \DDD\Domain\AI\Services\Eval\EvalOutboundRecipientsService::openEvalRunScopeForConversation()} around a tool
 * call of a conversation whose tree carries `runSettings.evalRun`, given back with {@see close()} in the caller's
 * `finally`. The transports (e-mail, SMS, calls, push) know no conversation; they ask {@see isAnyScopeOpen()}, so an
 * eval run's send is recognized even on a space that belongs to no eval account. A hold of a conversation that is
 * no eval run takes nothing and has nothing to give back.
 *
 * @phpstan-consistent-constructor
 */
class EvalRunOutboundScope
{
    /** @var int How many eval-run holds are open in this process right now (nested tool calls open one each) */
    protected static int $openScopeCount = 0;

    /** Whether this hold counted itself and has not been closed yet. */
    protected bool $isCloseOwed = false;

    /** Whether a tool call of an eval run is executing in this process right now. */
    public static function isAnyScopeOpen(): bool
    {
        return self::$openScopeCount > 0;
    }

    /** The hold of a tool call of an eval run — the process is in eval context until {@see close()}. */
    public static function openedForEvalRun(): static
    {
        $evalRunOutboundScope = new static();
        $evalRunOutboundScope->isCloseOwed = true;
        self::$openScopeCount++;
        return $evalRunOutboundScope;
    }

    /** The hold of a tool call of a conversation that is no eval run: it changes nothing. */
    public static function notOpened(): static
    {
        return new static();
    }

    /** Gives the hold back; a second call, or a hold that opened nothing, changes nothing. */
    public function close(): void
    {
        if (!$this->isCloseOwed) {
            return;
        }
        $this->isCloseOwed = false;
        self::$openScopeCount = max(0, self::$openScopeCount - 1);
    }

    /** Forget every open hold — a test (or a new job) starts outside eval context whatever an earlier one left open. */
    public static function forgetScopesOpenInThisProcess(): void
    {
        self::$openScopeCount = 0;
    }
}
