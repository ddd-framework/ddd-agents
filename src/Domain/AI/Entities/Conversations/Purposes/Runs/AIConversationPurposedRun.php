<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\Runs;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One purposed run as a population read shows it (`findPurposedRuns`): the conversation row's state
 * columns (run state, heartbeat, the owed stamps), the stall verdict of {@see AIConversationPurposedRunStallReason},
 * the plan progress n/m and the open leaves with their done-criterion keys, and the tree's cost. Built from the read
 * service's column rows; the task plan is applied by {@see applyTaskPlan()} (pure).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationPurposedRun extends ValueObject
{
    /** The most open leaves a row lists; the rest is counted in {@see $openItemsNotListed}. */
    public const int MAX_LISTED_OPEN_ITEMS = 5;

    /** The conversation id (open it with the conversation tools). */
    public int $aiConversationId = 0;

    public ?int $accountId = null;

    /** The id of the space the run works for; null = a run at account level (no space). */
    public ?int $agentSpaceId = null;

    public ?string $purposeName = null;

    public ?string $runState = null;

    /** Who started the run (`initiatorType`: USER, SCHEDULE, DAY_CONTEXT, ADMIN_TASK, …). */
    public ?string $initiatorType = null;

    public ?DateTime $createdAt = null;

    /** The newest heartbeat of a turn of this run. */
    public ?DateTime $runHeartbeatAt = null;

    /** The newest inbound message (owner text or signal). */
    public ?DateTime $lastInboundMessageAt = null;

    /** Failed attempts of the current turn (the resume cap is 3). */
    public int $resumeAttempts = 0;

    /** The turn handover marker: a turn is owed (set by a yield or a failed turn under the cap, cleared by the next claim). */
    public ?DateTime $agentTurnOwedAt = null;

    /** Set while the plan has an open item. */
    public ?DateTime $taskPlanOpenAt = null;

    /** A report obligation was stamped (an abnormal or unattended terminal). */
    public ?DateTime $reportOwedAt = null;

    /** The owed report was delivered. */
    public ?DateTime $reportDeliveredAt = null;

    /** Why the platform suppressed the run's work (an ineligible scope), or null. */
    public ?string $suppressionReason = null;

    /** The stall reason ({@see AIConversationPurposedRunStallReason}), or null when the run is not stalled. */
    public ?string $stalledReason = null;

    /** Whether the run is an eval run. */
    public bool $isEvalRun = false;

    /** The environment the run belongs to (null = production rows of before the stamp). */
    public ?string $environment = null;

    /** Plan nodes per effective state (null without a plan). */
    public ?int $planDone = null;

    public ?int $planOpen = null;

    public ?int $planBlocked = null;

    /** The open leaves, at most {@see MAX_LISTED_OPEN_ITEMS}. */
    public ?AIConversationPurposedRunOpenItems $openItems = null;

    /** Open leaves beyond the listed ones. */
    public int $openItemsNotListed = 0;

    /** The cost of the run's whole tree (the run, its sub-agents, its supervisor) in USD. */
    public ?float $treeCostInUSD = null;

    /**
     * The run of one column row of the read service: the conversation columns plus `stalledReason` and `isEvalRun`,
     * both computed in SQL.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        // the application's class of the same path when it has one (it may add its own facts to the row)
        $purposedRun = static::newInstance();
        $purposedRun->aiConversationId = (int)$row['id'];
        $purposedRun->accountId = isset($row['accountId']) ? (int)$row['accountId'] : null;
        $purposedRun->agentSpaceId = isset($row['agentSpaceId']) ? (int)$row['agentSpaceId'] : null;
        $purposedRun->purposeName = $row['purposeName'] ?? null;
        $purposedRun->runState = $row['runState'] ?? null;
        $purposedRun->initiatorType = $row['initiatorType'] ?? null;
        $purposedRun->createdAt = self::toDateTime($row['created'] ?? null);
        $purposedRun->runHeartbeatAt = self::toDateTime($row['runHeartbeatAt'] ?? null);
        $purposedRun->lastInboundMessageAt = self::toDateTime($row['lastInboundMessageAt'] ?? null);
        $purposedRun->resumeAttempts = (int)($row['resumeAttempts'] ?? 0);
        $purposedRun->agentTurnOwedAt = self::toDateTime($row['agentTurnOwedAt'] ?? null);
        $purposedRun->taskPlanOpenAt = self::toDateTime($row['taskPlanOpenAt'] ?? null);
        $purposedRun->reportOwedAt = self::toDateTime($row['reportOwedAt'] ?? null);
        $purposedRun->reportDeliveredAt = self::toDateTime($row['reportDeliveredAt'] ?? null);
        $purposedRun->suppressionReason = $row['suppressionReason'] ?? null;
        $purposedRun->stalledReason = $row['stalledReason'] ?? null;
        $purposedRun->isEvalRun = (bool)($row['isEvalRun'] ?? false);
        return $purposedRun;
    }

    /**
     * The plan progress and the open leaves of `$taskPlan`, each open leaf with its criterion key and whether the
     * purpose's registry gates it (`$taskPlanDoneCriteria`; a key without a class is no gate). Pure.
     */
    public function applyTaskPlan(?TaskPlan $taskPlan, ?TaskPlanDoneCriteria $taskPlanDoneCriteria): void
    {
        if ($taskPlan?->items === null || $taskPlan->items->count() === 0) {
            return;
        }
        $statusCounts = $taskPlan->items->countItemsByStatus();
        $this->planDone = $statusCounts->done;
        $this->planOpen = $statusCounts->open;
        $this->planBlocked = $statusCounts->blocked;
        $this->openItems = new AIConversationPurposedRunOpenItems();
        foreach ($taskPlan->items->buildItemAddresses()->getLeaves() as $leafAddress) {
            if (!$leafAddress->itemIsOpen || $leafAddress->hasBlockedAncestor) {
                continue;
            }
            if ($this->openItems->count() >= self::MAX_LISTED_OPEN_ITEMS) {
                $this->openItemsNotListed++;
                continue;
            }
            $doneCriterion = $leafAddress->item->doneCriterion;
            $openItem = AIConversationPurposedRunOpenItem::fromItem(
                $leafAddress->key,
                $leafAddress->item->text,
                $doneCriterion,
                $doneCriterion !== null && $taskPlanDoneCriteria?->forKey($doneCriterion) !== null
            );
            $this->openItems->add($openItem);
        }
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->aiConversationId);
    }

    /**
     * The text of the table cell below the set's space column title
     * ({@see AIConversationPurposedRuns::AGENT_SPACE_COLUMN_TITLE}): the id of the space, empty for a run at account
     * level. An application that names its own term in that title returns its own id of the space here.
     *
     * @return string
     */
    protected function getAgentSpaceCellText(): string
    {
        return (string)($this->agentSpaceId ?? '');
    }

    /** One table row of the markdown render; the space cell is empty for a run at account level. */
    public function toLlmMarkdownRow(): string
    {
        $planCell = $this->planOpen === null ? 'no plan' : "{$this->planDone} done / {$this->planOpen} open / {$this->planBlocked} blocked";
        $openItemLines = [];
        foreach ($this->openItems?->getElements() ?? [] as $openItem) {
            $openItemLines[] = $openItem->toLlmMarkdownLine();
        }
        if ($this->openItemsNotListed > 0) {
            $openItemLines[] = "+{$this->openItemsNotListed} more";
        }
        $owedStamps = [];
        if ($this->agentTurnOwedAt !== null) {
            $owedStamps[] = 'turn owed ' . $this->agentTurnOwedAt->format('m-d H:i');
        }
        if ($this->reportOwedAt !== null) {
            $owedStamps[] = 'report owed ' . $this->reportOwedAt->format('m-d H:i') . ($this->reportDeliveredAt !== null ? ' (delivered)' : '');
        }
        if ($this->resumeAttempts > 0) {
            $owedStamps[] = 'attempts ' . $this->resumeAttempts;
        }
        if ($this->suppressionReason !== null) {
            $owedStamps[] = 'suppressed ' . $this->suppressionReason;
        }
        $cells = [
            (string)$this->aiConversationId,
            ($this->purposeName ?? '') . ($this->isEvalRun ? ' (eval)' : ''),
            $this->getAgentSpaceCellText(),
            $this->createdAt?->format('m-d H:i') ?? '',
            (string)$this->runState,
            $this->stalledReason ?? '',
            $planCell,
            implode('; ', $openItemLines),
            implode(', ', $owedStamps),
            $this->runHeartbeatAt?->format('m-d H:i') ?? '',
            $this->treeCostInUSD !== null ? number_format($this->treeCostInUSD, 3) : '',
        ];
        return '| ' . implode(' | ', array_map(static fn (string $cell): string => str_replace('|', '/', $cell), $cells)) . ' |';
    }

    protected static function toDateTime(mixed $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }
        return new DateTime((string)$value);
    }
}
