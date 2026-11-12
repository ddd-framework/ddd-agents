<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Reports;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The report an EARLIER run of the same schedule wrote for the owner, as the next run reads it
 * (`getPreviousTaskRunReports`): the run, its start, the report (subject + body with
 * its widget blocks and their values) and whether the owner changed the task since — then the structure answers a task
 * that no longer exists.
 */
class AIConversationEarlierRunReport extends ValueObject implements LlmMarkdownRenderable
{
    /** @var int The earlier run's conversation id. */
    public int $conversationId = 0;

    /** @var DateTime|null When the earlier run started. */
    public ?DateTime $startedAt = null;

    /** @var bool The owner changed the schedule's task after this run: its structure answers the old task. */
    public bool $taskChangedSince = false;

    /** @var AIConversationOwnerReport|null The report the run's model wrote (subject + body Markdown). */
    public ?AIConversationOwnerReport $ownerReport = null;

    public function toLlmMarkdown(): string
    {
        $headline = '## Earlier report — run #' . $this->conversationId
            . ($this->startedAt !== null ? ', ' . AgentClock::formatDay($this->startedAt) : '');
        $lines = [$headline];
        if ($this->taskChangedSince) {
            $lines[] = '_The owner changed the task after this run — do not keep its structure; compose the report for the current task._';
        }
        if ($this->ownerReport !== null) {
            $lines[] = $this->ownerReport->toLlmMarkdown();
        }
        return implode("\n\n", $lines);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->conversationId);
    }
}
