<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\Runs;

use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * One page of purposed runs (`findPurposedRuns`), newest first, with the total that matches the filter
 * and the scope it covers. Renders itself as one table; open item texts are seeded plan texts (platform-written).
 *
 * @property AIConversationPurposedRun[] $elements
 * @method AIConversationPurposedRun[] getElements()
 */
class AIConversationPurposedRuns extends ObjectSet implements LlmMarkdownRenderable
{
    /** How many runs match the filter in all (the paging denominator). */
    public int $totalCount = 0;

    /** The scope line of the filter ({@see AIConversationPurposedRunsFilter::describeScope()}). */
    public string $scope = '';

    public int $skip = 0;

    public int $top = 0;

    /** The title of the table column that shows the id of the space a run works for (an application may name its own term). */
    protected const string AGENT_SPACE_COLUMN_TITLE = 'space';

    public function toLlmMarkdown(): string
    {
        $shownCount = $this->count();
        $lines = ["# Purposed runs — {$this->scope}", ''];
        if ($shownCount === 0) {
            $lines[] = "No run matches ({$this->totalCount} in all).";
            return implode("\n", $lines);
        }
        $lines[] = 'Rows ' . ($this->skip + 1) . '–' . ($this->skip + $shownCount) . " of {$this->totalCount}, newest first (skip {$this->skip}, top {$this->top})."
            . ($this->skip + $shownCount < $this->totalCount ? ' More rows follow: skip ' . ($this->skip + $shownCount) . ' reads the next page.' : '');
        $lines[] = 'Stall reasons: ' . implode('; ', array_map(
            static fn (string $stallReason): string => $stallReason . ' = ' . AIConversationPurposedRunStallReason::describe($stallReason),
            AIConversationPurposedRunStallReason::ORDER
        )) . '. Open items marked "no gate" carry a legacy criterion key the purpose no longer checks.';
        $lines[] = '';
        $lines[] = '| id | purpose | ' . static::AGENT_SPACE_COLUMN_TITLE . ' | created | state | stalled | plan | open items [criterion] | owed | heartbeat | cost USD |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|---|---|';
        foreach ($this->getElements() as $purposedRun) {
            $lines[] = $purposedRun->toLlmMarkdownRow();
        }
        return implode("\n", $lines);
    }
}
