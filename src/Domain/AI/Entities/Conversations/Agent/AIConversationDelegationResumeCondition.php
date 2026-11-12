<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

/**
 * When a delegation fork-join ({@see AIConversationDelegationBatch}) resumes its parent orchestrator conversation.
 * String constants (not a native enum) to match the project's {@see \DDD\Domain\AI\Entities\Conversations\AIConversationRunState}
 * style and to key the child-finish UPDATE directly.
 */
class AIConversationDelegationResumeCondition
{
    /** Wake when EVERY child is terminal (COMPLETED or FAILED) — the default barrier. */
    public const string ALL = 'ALL';

    /** Wake when {@see AIConversationDelegationBatch::$quorumSuccessThreshold} children have COMPLETED. */
    public const string QUORUM = 'QUORUM';

    /** Wake on the FIRST child to COMPLETE (≡ QUORUM with a threshold of 1). */
    public const string ANY = 'ANY';

    /**
     * NEVER wake the parent: the batch settles silently when every child
     * is terminal (join math = ALL) and stamps `foldedAt` in the same write — no parking, no resume dispatch,
     * no fold turn owed. The results sit on the delegate-call message; a persisted note informs the model on
     * its NEXT invocation. LOOP-dispatched background work only (prefetch, mechanical jobs) — the model's
     * `delegate` tool never emits this condition.
     */
    public const string BACKGROUND = 'BACKGROUND';
}
