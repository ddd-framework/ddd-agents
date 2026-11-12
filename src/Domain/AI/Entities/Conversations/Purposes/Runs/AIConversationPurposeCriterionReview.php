<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\Runs;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One done-criterion key across the runs of a purpose (`reviewPurposeCriteria`): how many runs carry a
 * plan item with the key, and in how many it stands done / open / blocked / not applicable — with up to
 * {@see MAX_EXAMPLE_RUN_IDS} run ids per open and blocked state as evidence.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationPurposeCriterionReview extends ValueObject
{
    /** Run ids kept per state as evidence. */
    public const int MAX_EXAMPLE_RUN_IDS = 5;

    public string $doneCriterionKey = '';

    /** True when the purpose registers a criterion class for the key (a gate); false = a legacy key, no gate. */
    public bool $gates = false;

    /** The item text of the first run that carried the key, cut to 80 characters. */
    public string $itemText = '';

    public int $runCount = 0;

    public int $done = 0;

    public int $open = 0;

    public int $blocked = 0;

    public int $notApplicable = 0;

    /** @var int[] Runs where the key stands open. */
    public array $openRunIds = [];

    /** @var int[] Runs where the key stands blocked. */
    public array $blockedRunIds = [];

    public static function forKey(string $doneCriterionKey, bool $gates, string $itemText): static
    {
        $criterionReview = new static();
        $criterionReview->doneCriterionKey = $doneCriterionKey;
        $criterionReview->gates = $gates;
        $itemText = trim(preg_replace('/\s+/', ' ', $itemText) ?? $itemText);
        $criterionReview->itemText = mb_strlen($itemText) > 80 ? mb_substr($itemText, 0, 79) . '…' : $itemText;
        return $criterionReview;
    }

    /** Counts the item of one run with the key. A leaf under a blocked ancestor counts blocked (the blocker covers it). */
    public function countItemOfRun(int $runId, string $itemStatus, bool $hasBlockedAncestor): void
    {
        $this->runCount++;
        $effectiveStatus = $hasBlockedAncestor ? TaskPlanItem::STATUS_BLOCKED : $itemStatus;
        switch ($effectiveStatus) {
            case TaskPlanItem::STATUS_DONE:
                $this->done++;
                break;
            case TaskPlanItem::STATUS_BLOCKED:
                $this->blocked++;
                if (count($this->blockedRunIds) < self::MAX_EXAMPLE_RUN_IDS) {
                    $this->blockedRunIds[] = $runId;
                }
                break;
            case TaskPlanItem::STATUS_NOT_APPLICABLE:
                $this->notApplicable++;
                break;
            default:
                $this->open++;
                if (count($this->openRunIds) < self::MAX_EXAMPLE_RUN_IDS) {
                    $this->openRunIds[] = $runId;
                }
        }
    }

    /** The key of a done criterion's review: the same for every class derived from this one, so a set finds it by the criterion key. */
    public static function uniqueKeyOfDoneCriterionKey(string $doneCriterionKey): string
    {
        return self::class . '_' . $doneCriterionKey;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyOfDoneCriterionKey($this->doneCriterionKey);
    }

    public function toLlmMarkdownRow(): string
    {
        $cells = [
            $this->doneCriterionKey,
            $this->gates ? 'gate' : 'no gate (legacy)',
            $this->itemText,
            (string)$this->runCount,
            (string)$this->done,
            (string)$this->open,
            (string)$this->blocked,
            (string)$this->notApplicable,
            implode(', ', $this->openRunIds),
            implode(', ', $this->blockedRunIds),
        ];
        return '| ' . implode(' | ', array_map(static fn (string $cell): string => str_replace('|', '/', $cell), $cells)) . ' |';
    }
}
