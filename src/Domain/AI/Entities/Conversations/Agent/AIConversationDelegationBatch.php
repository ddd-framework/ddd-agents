<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatch;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * A delegation fork-join: one orchestrator conversation forks N parallel sub-agent delegations and waits
 * on them. The batch is the first-class coordinator — it knows how many children it expects, the resume condition
 * ({@see AIConversationDelegationResumeCondition}: ALL / QUORUM / ANY), and the exactly-once parent-resume gate ({@see $settledAt}).
 *
 * The parent-resume decision is a DERIVED `COUNT(*)` over the children (each child is an {@see AIConversation} carrying this
 * batch's id in {@see AIConversation::$delegationBatchId}), claimed exactly once by the `settledAt IS NULL` gate under
 * a lock-first batch-row lock — crash/rerun-proof (no maintained counter to drift). The whole settle transaction lives
 * on {@see AIConversationDelegationBatchesService::claimParentResumeOnChildTerminal()} (repo-bound); the pure join predicates
 * live here on the object.
 *
 * @method static AIConversationDelegationBatchesService getService()
 */
// Reaper sweep: WHERE settledAt IS NULL AND deadlineAt < :now (timeout), and finished-but-unread re-fire.
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['settledAt', 'deadlineAt'])]
#[LazyLoadRepo(LazyLoadRepo::DB, repoClass: DBAIConversationDelegationBatch::class)]
class AIConversationDelegationBatch extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var int|null Id of the orchestrator conversation that forked this batch and waits on it (the resume target). */
    #[NotNull]
    public ?int $parentConversationId;

    /** @var AIConversation|null The orchestrator conversation — the resume target AND the read-rights anchor. */
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AIConversation $parentConversation;

    /** @var int|null Id of the assistant turn message that emitted the delegate calls; NULL when service-created. */
    public ?int $spawnedByMessageId;

    /** @var AIConversationMessage|null The assistant turn message that forked this batch (traceability). */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AIConversationMessage $spawnedByMessage;

    /** @var int The number of delegation children this batch waits on (set once at fork, before any dispatch). */
    #[NotNull]
    public int $expectedDelegationCount;

    /** @var string When to resume the parent — an {@see AIConversationDelegationResumeCondition} value (ALL | QUORUM | ANY). */
    public string $delegateWakeCondition = AIConversationDelegationResumeCondition::ALL;

    /** @var int|null The k successes that resume the parent under QUORUM (1..N); NULL for ALL/ANY. */
    public ?int $quorumSuccessThreshold;

    /** @var int The wall-clock budget in seconds (informational; {@see $deadlineAt} is the queried anchor). */
    public int $timeoutSeconds = 300;

    /** @var DateTime|null Absolute deadline = createdAt + timeoutSeconds; the reaper times the batch out past this. */
    public ?DateTime $deadlineAt;

    /** @var DateTime|null Set once when the resume condition is first met — the exactly-once parent-resume gate. */
    public ?DateTime $settledAt;

    /** @var DateTime|null Set when the parent has folded this settled batch's verdicts into a turn. */
    public ?DateTime $foldedAt;

    /** @var AIConversations|null The delegation children of this batch (inspection; the settle uses a raw COUNT). */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AIConversations $delegationChildren;

    /**
     * Whether this batch's join settles only when EVERY child is terminal (the default barrier). BACKGROUND
     * shares ALL's join math — every child terminal — it only differs in what happens AFTER the settle (the
     * parent is never parked/resumed). Mirrors the settle SQL's `isAll` branch in
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService::claimParentResumeOnChildTerminal()}.
     */
    public function isAllCondition(): bool
    {
        return $this->delegateWakeCondition === AIConversationDelegationResumeCondition::ALL
            || $this->delegateWakeCondition === AIConversationDelegationResumeCondition::BACKGROUND;
    }

    /** A BACKGROUND batch: settles like ALL, but its parent is never parked or resumed for it. */
    public function isBackgroundCondition(): bool
    {
        return $this->delegateWakeCondition === AIConversationDelegationResumeCondition::BACKGROUND;
    }

    /** The success threshold for a non-ALL batch: QUORUM → its threshold, ANY → 1; null for ALL. */
    public function effectiveQuorum(): ?int
    {
        if ($this->isAllCondition()) {
            return null;
        }
        return $this->delegateWakeCondition === AIConversationDelegationResumeCondition::QUORUM
            ? ($this->quorumSuccessThreshold ?? null)
            : 1;
    }

    /**
     * Whether the wake should fire NOW given the committed child counts — the in-memory mirror of the child-finish SQL's
     * WHERE (success barrier OR quorum/any unreachability). Pure.
     */
    public function isJoinSatisfiedByCounts(int $completedCount, int $failedCount): bool
    {
        if ($this->isAllCondition()) {
            return $completedCount + $failedCount >= $this->expectedDelegationCount;
        }
        return $completedCount >= (int)$this->effectiveQuorum() || $this->isQuorumUnreachable($failedCount);
    }

    /** Whether a non-ALL batch can no longer reach its success threshold (too many children FAILED) → fail-fast. Pure. */
    public function isQuorumUnreachable(int $failedCount): bool
    {
        if ($this->isAllCondition()) {
            return false;
        }
        return $this->expectedDelegationCount - $failedCount < (int)$this->effectiveQuorum();
    }

    /** Whether the wake has already been claimed (the exactly-once gate is set). */
    public function isParentResumeConditionMet(): bool
    {
        // isset() guards the un-defaulted property: false when uninitialized (bare instance) AND when explicitly null.
        return isset($this->settledAt);
    }

    /** Whether the parent has already folded this settled batch's verdicts. */
    public function hasParentReadChildResults(): bool
    {
        return isset($this->foldedAt);
    }

    /**
     * The DELEGATIONS_SETTLED signal text injected into the PARENT at results-reading time: an EXPLICIT "your sub-agents
     * finished, answer now" cue so the orchestrator does not have to NOTICE the now-populated `delegate` tool-result on
     * its own (an observed weak-model failure). Names the resume condition that fired + the terminal child tally, and
     * points at the verdicts already sitting in the delegate result above — it does NOT restate them (re-billed context).
     * Pure: the counts are passed in (the repo COUNT lives on the service); everything else is this batch's own state.
     */
    public function buildChildrenFinishedSignalText(AgentDelegationChildCounts $terminalChildCounts, ?string $foldNextStep = null): string
    {
        $completedCount = $terminalChildCounts->countCompleted();
        $failedCount = $terminalChildCounts->countFailed();
        $cancelledCount = $terminalChildCounts->countCancelled();
        $condition = match ($this->delegateWakeCondition) {
            AIConversationDelegationResumeCondition::ALL => 'all sub-agents finished',
            AIConversationDelegationResumeCondition::QUORUM => 'the quorum of ' . (int)$this->effectiveQuorum() . ' succeeded',
            AIConversationDelegationResumeCondition::ANY => 'the first sub-agent succeeded',
            default => 'the sub-agents settled',
        };
        $tally = [$completedCount . ' completed'];
        if ($failedCount > 0) {
            $tally[] = $failedCount . ' failed';
        }
        if ($cancelledCount > 0) {
            $tally[] = $cancelledCount . ' cancelled';
        }
        $head = 'Your ' . $this->expectedDelegationCount . ' delegated sub-agents have finished — ' . $condition
            . ' (' . implode(', ', $tally) . '). Their verdicts are in the `delegate` tool-result above. ';
        // A delegating TOOL that knows the turn is not done when its children have finished (a LARGE edit still needs
        // its rebuild) supplies the continuation; the generic "answer now" only when no tool said otherwise.
        if ($foldNextStep !== null && trim($foldNextStep) !== '') {
            return $head . 'NEXT: ' . trim($foldNextStep) . ' Do NOT call check_delegations again, and do NOT re-run any tool a '
                . 'sub-agent already ran.';
        }
        return $head . 'Answer the user '
            . 'now directly from those verdicts: do NOT call check_delegations again, and do NOT re-run any tool a '
            . 'sub-agent already ran (e.g. re-fetching the same data).';
    }
}
