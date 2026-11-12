<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Conversations;

use RuntimeException;

/**
 * Internal control-flow signal: the worker driving an agent conversation turn no longer
 * holds the run-claim — either a token-guarded append found the claim gone (another worker took over, e.g. the reaper
 * stole a genuinely-overrun turn), or a concurrent drive collided on the `UNIQUE(aiConversationId, indexInConversation)`
 * backstop. It is thrown from the append path and CAUGHT inside {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService}'s
 * loop, which then stops driving SILENTLY (the new owner continues + appends). Deliberately a plain RuntimeException,
 * NOT a DDD HTTP exception: it must never surface as a 500 and must not be confused with a turn FAILURE (it does NOT
 * bump `resumeAttempts` — losing the claim is not a failure).
 */
class AgentRunClaimLostException extends RuntimeException
{
}
