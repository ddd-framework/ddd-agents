<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Every violation of ONE `update_task_plan` call, in the order the check met them — a single one
 * rejects the whole call and nothing changes. {@see renderBlock()} is the call's result text: the header, one line per
 * violation, the plan's keys with their texts and the valid shapes.
 */
class TaskPlanUpdateRejections extends ValueObject
{
    /** The first line of a rejected call's result. */
    public const string REJECTION_HEADER = 'Task plan NOT updated — nothing changed. Fix every line below and send the whole plan again:';

    /** The last line: the three valid element shapes. */
    public const string VALID_SHAPES_LINE = 'Send an existing item as {"key": "2.1"} (its text is fixed), a new item as {"text": "…"} without a key, a dropped item as {"key": "3", "remove": true}; an existing item sent without "subtasks" keeps its subtasks.';

    /** The key list without a standing plan. */
    public const string NO_PLAN_KEY_LINE = 'You have no plan yet — every item is new and carries no key.';

    /** How many characters of an item's text the key list shows. */
    public const int MAX_KEY_LIST_TEXT_CHARS = 50;

    /** @var TaskPlanUpdateRejection[] */
    public array $rejections = [];

    public function addRejection(TaskPlanUpdateRejection $taskPlanUpdateRejection): void
    {
        $this->rejections[] = $taskPlanUpdateRejection;
    }

    public function hasRejections(): bool
    {
        return $this->rejections !== [];
    }

    /** @return string[] the codes, in order (one per line) */
    public function getCodes(): array
    {
        return array_map(static fn (TaskPlanUpdateRejection $taskPlanUpdateRejection): string => $taskPlanUpdateRejection->code, $this->rejections);
    }

    /** @return string[] the lines, in order */
    public function getLines(): array
    {
        return array_map(static fn (TaskPlanUpdateRejection $taskPlanUpdateRejection): string => $taskPlanUpdateRejection->line, $this->rejections);
    }

    public function hasCode(string $code): bool
    {
        return in_array($code, $this->getCodes(), true);
    }

    /**
     * The result text of the rejected call: the header, one `- ` line per violation, `Your plan's keys: 1 '…' · 2 '…'`
     * (every item in plan order whose key names exactly one item, each text clipped to 50) — or the no-plan line —
     * and the valid shapes.
     */
    public function renderBlock(?TaskPlanItems $standingTaskPlanItems): string
    {
        $keyLines = $standingTaskPlanItems?->getKeyLinesNamingOneItem(self::MAX_KEY_LIST_TEXT_CHARS) ?? [];
        return self::REJECTION_HEADER . "\n"
            . implode("\n", array_map(static fn (string $rejectionLine): string => '- ' . $rejectionLine, $this->getLines())) . "\n"
            . ($keyLines !== [] ? "Your plan's keys: " . implode(' · ', $keyLines) : self::NO_PLAN_KEY_LINE) . "\n"
            . self::VALID_SHAPES_LINE;
    }
}
