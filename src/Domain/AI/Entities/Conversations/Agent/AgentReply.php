<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentLoopStopReason;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The loop's verdict for one thread, transient — replaces the `{text, completed}` array returned by
 * runConversationLoop: the final assistant text and whether the thread ran to a REAL answer (`true`) vs exhausting
 * its step budget / aborting (`false`). For a sub-thread this is the verdict that crosses back as the delegate
 * call's result.
 *
 * ValueObject, not Entity: no identity, never persisted (same rationale as {@see AgentModelInput}).
 */
class AgentReply extends ValueObject
{
    public ?string $text = null;
    public bool $completed = false;

    /**
     * @var bool The invocation YIELDED at a chunk boundary: its step batch was exhausted by productive
     * steps while the turn's total step budget still allows more — a TURN_CONTINUATION signal is the trailing
     * message and the next worker invocation continues the SAME user turn. No final answer yet: the caller must
     * skip the per-settle side work (display-summary refresh) until a non-yielded invocation ends the turn.
     */
    public bool $yielded = false;

    /** @var AgentLoopStopReason|null Why the loop stopped; {@see $completed} and {@see $yielded} are read from it. */
    public ?AgentLoopStopReason $loopStopReason = null;
}
