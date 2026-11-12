<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recovery;

use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The conversations the reaper and the recovery cron (two console commands of the application)
 * would act on at the moment of the read, one row per
 * conversation with every reason that names it. Each candidate set is capped by its finder (50 per pass for the owed
 * passes), and every finder reads only the environment the server runs in.
 *
 * @property AIConversationStuckRun[] $elements
 * @method AIConversationStuckRun[] getElements()
 */
class AIConversationStuckRuns extends ObjectSet implements LlmMarkdownRenderable
{
    /** The environment the finders read (production, or a dev workspace's name). */
    public string $environment = '';

    /** When the read ran (server time). */
    public string $readAt = '';

    /** @var array<string, int> How many conversations each reason names. */
    public array $countsByReason = [];

    /** The title of the table column that shows the id of the space a conversation works for (an application may name its own term). */
    protected const string AGENT_SPACE_COLUMN_TITLE = 'space';

    public function findOrAddStuckRun(int $aiConversationId): AIConversationStuckRun
    {
        $stuckRun = $this->getByUniqueKey(AIConversationStuckRun::uniqueKeyOfConversationId($aiConversationId));
        if ($stuckRun instanceof AIConversationStuckRun) {
            return $stuckRun;
        }
        $stuckRun = AIConversationStuckRun::forConversation($aiConversationId);
        $this->add($stuckRun);
        return $stuckRun;
    }

    /**
     * Adds `$reason` to each of the conversations and counts it.
     *
     * @param int[] $aiConversationIds
     */
    public function addReasonToConversations(string $reason, array $aiConversationIds): void
    {
        $this->countsByReason[$reason] = count($aiConversationIds);
        foreach ($aiConversationIds as $aiConversationId) {
            $this->findOrAddStuckRun((int)$aiConversationId)->addReason($reason);
        }
    }

    /** @return int[] */
    public function getConversationIds(): array
    {
        return array_map(static fn (AIConversationStuckRun $stuckRun): int => $stuckRun->aiConversationId, $this->getElements());
    }

    public function toLlmMarkdown(): string
    {
        $lines = ["# Stuck and owed runs — environment {$this->environment}, read at {$this->readAt} (server time)", ''];
        $reasonLines = [];
        foreach (AIConversationStuckRun::REASON_DESCRIPTIONS as $reason => $description) {
            $reasonLines[] = '- ' . $reason . ' (' . ($this->countsByReason[$reason] ?? 0) . '): ' . $description;
        }
        $lines = [...$lines, 'Candidate sets (count): ', ...$reasonLines, ''];
        if ($this->count() === 0) {
            $lines[] = 'Nothing is stuck or owed right now.';
            return implode("\n", $lines);
        }
        $lines[] = 'The owed passes cap each set at 50, oldest first. A conversation listed here is acted on by the next recovery tick; this read changes nothing.';
        $lines[] = '';
        $lines[] = '| id | reasons | purpose | state | account | ' . static::AGENT_SPACE_COLUMN_TITLE . ' | root | heartbeat age | attempts | turn owed | report owed | last inbound |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|---|---|---|';
        // the settle sweep names every quiet IDLE purposed conversation; rows another set names come first
        $stuckRunsInOrder = $this->getElements();
        usort(
            $stuckRunsInOrder,
            static fn (AIConversationStuckRun $left, AIConversationStuckRun $right): int => (int)$left->isNamedOnlyBySettleSweep() <=> (int)$right->isNamedOnlyBySettleSweep()
        );
        foreach ($stuckRunsInOrder as $stuckRun) {
            $lines[] = $stuckRun->toLlmMarkdownRow();
        }
        return implode("\n", $lines);
    }
}
