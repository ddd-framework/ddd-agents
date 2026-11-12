<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationResumeCondition;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The delegation tree of one conversation in ONE read (the children, sub-agent and batch view of an application's
 * inspect command): the child conversations spawned by its rows (recursively, with their message count, tokens and
 * cost), one block per sub-agent — its head line and one line per run with the run's deadline row — and one row per
 * legacy fork-join batch with the live member tally. Built from the loaded `delegate` rows plus the batch rows; the
 * command prints it, the admin tool returns it.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationDelegationTreeReport extends ValueObject implements LlmMarkdownRenderable
{
    /** @var string[] One line per child conversation spawned by a row, indented two spaces per depth (depth 1 first). */
    public array $childConversationLines = [];

    /** @var string[] Per sub-agent: its head line, then one indented line per run (current run marked ▸). */
    public array $subAgentLines = [];

    /** @var array<int, array<string, int|string>> One row per legacy batch: batch, wake, expected, completed, failed, running, queued, settled, folded, deadline. */
    public array $legacyBatchRows = [];

    /** The tree of a conversation whose message rows are loaded (`$conversation->messages`). */
    public static function buildFor(AIConversation $conversation, AIConversationDelegationBatchesService $batchesService): static
    {
        $report = new static();
        if (!isset($conversation->id)) {
            return $report;
        }
        if (isset($conversation->messages)) {
            $report->childConversationLines = static::buildChildConversationLines($conversation->messages, 1);
        }
        $subAgentRecordByDelegateMessageId = static::buildSubAgentRecordsByDelegateMessageId($conversation);
        $batches = $batchesService->findBatchesForParent((int)$conversation->id);
        $legacyBatches = [];
        $deadlinesByDelegateMessageId = [];
        foreach ($batches?->getElements() ?? [] as $batch) {
            $spawnedByMessageId = isset($batch->spawnedByMessageId) ? (int)$batch->spawnedByMessageId : null;
            if ($spawnedByMessageId !== null && isset($subAgentRecordByDelegateMessageId[$spawnedByMessageId])) {
                $deadlinesByDelegateMessageId[$spawnedByMessageId][] = $batch;
                continue;
            }
            $legacyBatches[] = $batch;
        }
        $report->subAgentLines = static::buildSubAgentLines($subAgentRecordByDelegateMessageId, $deadlinesByDelegateMessageId);
        foreach ($legacyBatches as $legacyBatch) {
            $report->legacyBatchRows[] = static::buildLegacyBatchRow($legacyBatch, $batchesService);
        }
        return $report;
    }

    /**
     * The child conversations spawned by these rows, recursively: `↳ child #<id> (spawned by msg #<row>) · <n> messages ·
     * <tokens> tok · $<cost>`. Children are the INVERSE relation (conversations whose `delegatedFromMessageId` is the
     * row's id, 1:N), read through the lazy relation; ids only — a child's detail is its own read.
     *
     * @return string[]
     */
    public static function buildChildConversationLines(AIConversationMessages $messages, int $depth): array
    {
        $indent = str_repeat('  ', $depth);
        $lines = [];
        foreach ($messages->getElements() as $message) {
            if (!isset($message->id)) {
                continue;
            }
            foreach ($message->delegatedConversations?->getElements() ?? [] as $child) {
                if (!$child instanceof AIConversation) {
                    continue;
                }
                $childMessages = AIConversationMessage::getService()->findAllMessagesForConversationInOrder($child, useEntityRegistrCache: false)
                    ?? AIConversationMessages::newInstance();
                $child->messages = $childMessages;
                $lines[] = sprintf(
                    '%s↳ child #%s (spawned by msg #%s) · %d messages · %d tok · $%.4f',
                    $indent,
                    $child->id,
                    $message->id,
                    count($childMessages->getElements()),
                    $childMessages->getTotalTokens(),
                    $childMessages->getTotalCostUsd(),
                );
                array_push($lines, ...static::buildChildConversationLines($childMessages, $depth + 1));
            }
        }
        return $lines;
    }

    /**
     * The sub-agent record of every loaded `delegate` row written with a sub-agent record, keyed by the row's message id, in
     * conversation order. Legacy rows (a results map) carry no record and are left out.
     *
     * @return DelegationSubAgentRecord[]
     */
    public static function buildSubAgentRecordsByDelegateMessageId(AIConversation $conversation): array
    {
        $subAgentRecordByDelegateMessageId = [];
        if (!isset($conversation->messages)) {
            return $subAgentRecordByDelegateMessageId;
        }
        foreach ($conversation->messages->getElements() as $message) {
            if (!isset($message->id) || !$message->isDelegationCall() || !isset($message->delegationResults) || $message->delegationResults->isLegacyMap()) {
                continue;
            }
            $subAgentRecordByDelegateMessageId[(int)$message->id] = $message->delegationResults->subAgent;
        }
        return $subAgentRecordByDelegateMessageId;
    }

    /**
     * One block per sub-agent: its head line (number, label, task, child, requester) and one line per run — status, who
     * continued it, start and end, the failure, whether its notification reached the parent, the cost — with the run's
     * deadline row matched by its creation time (a sync run has none).
     *
     * @param DelegationSubAgentRecord[] $subAgentRecordByDelegateMessageId
     * @param AIConversationDelegationBatch[][] $deadlinesByDelegateMessageId
     * @return string[]
     */
    protected static function buildSubAgentLines(array $subAgentRecordByDelegateMessageId, array $deadlinesByDelegateMessageId): array
    {
        $lines = [];
        foreach ($subAgentRecordByDelegateMessageId as $delegateMessageId => $subAgentRecord) {
            $lines[] = $subAgentRecord->buildOperatorHeadLine() . ' · delegate msg #' . $delegateMessageId;
            $deadlineByRunNumber = [];
            foreach ($deadlinesByDelegateMessageId[$delegateMessageId] ?? [] as $deadline) {
                $deadlineCreatedAt = $deadline->getCreatedTime();
                $matchedRun = $deadlineCreatedAt !== null ? $subAgentRecord->runs->getRunQueuedClosestTo($deadlineCreatedAt) : null;
                if ($matchedRun !== null) {
                    $deadlineByRunNumber[$matchedRun->runNumber] = $deadline;
                }
            }
            foreach ($subAgentRecord->runs->getRunsInOrder() as $subAgentRun) {
                $runLine = '  ' . ($subAgentRun->runNumber === $subAgentRecord->currentRunNumber ? '▸ ' : '  ') . $subAgentRun->buildOperatorLine();
                if ($subAgentRun->openingMessage !== null) {
                    $runLine .= ' · opening message: "' . $subAgentRun->openingMessage . '"';
                }
                $deadline = $deadlineByRunNumber[$subAgentRun->runNumber] ?? null;
                if ($deadline !== null) {
                    $runLine .= ' · deadline row #' . $deadline->id
                        . (isset($deadline->deadlineAt) ? ' (deadline ' . (string)$deadline->deadlineAt . ')' : '');
                }
                $lines[] = $runLine;
            }
        }
        return $lines;
    }

    /**
     * The tally row of one legacy fork-join batch.
     *
     * @return array<string, int|string>
     */
    protected static function buildLegacyBatchRow(AIConversationDelegationBatch $batch, AIConversationDelegationBatchesService $batchesService): array
    {
        $counts = [];
        foreach ($batchesService->findBatchMembers($batch)?->getElements() ?? [] as $member) {
            $memberRunState = $member->runState;
            $counts[$memberRunState] = ($counts[$memberRunState] ?? 0) + 1;
        }
        // isset() guards the un-defaulted batch columns: a column is only read when initialized AND non-null.
        $wake = $batch->delegateWakeCondition === AIConversationDelegationResumeCondition::QUORUM
            ? 'QUORUM(' . ($batch->quorumSuccessThreshold ?? '?') . ')'
            : $batch->delegateWakeCondition;
        return [
            'batch' => $batch->id,
            'wake' => $wake,
            'expected' => $batch->expectedDelegationCount,
            'completed' => $counts[AIConversationRunState::COMPLETED] ?? 0,
            'failed' => $counts[AIConversationRunState::FAILED] ?? 0,
            'running' => $counts[AIConversationRunState::RUNNING] ?? 0,
            'queued' => $counts[AIConversationRunState::QUEUED] ?? 0,
            'settled' => isset($batch->settledAt) ? (string)$batch->settledAt : '—',
            'folded' => isset($batch->foldedAt) ? (string)$batch->foldedAt : '—',
            'deadline' => isset($batch->deadlineAt) ? (string)$batch->deadlineAt : '—',
        ];
    }

    public function toLlmMarkdown(): string
    {
        if ($this->childConversationLines === [] && $this->subAgentLines === [] && $this->legacyBatchRows === []) {
            return 'No child conversations, no sub-agents and no delegation batches.';
        }
        $parts = [];
        if ($this->childConversationLines !== []) {
            $parts[] = "## Child conversations (read each with its own id)\n" . implode("\n", $this->childConversationLines);
        }
        if ($this->subAgentLines !== []) {
            $parts[] = "## Sub-agents (one per delegate call)\n" . implode("\n", $this->subAgentLines);
        }
        if ($this->legacyBatchRows !== []) {
            $tableLines = ['| ' . implode(' | ', array_keys($this->legacyBatchRows[0])) . ' |', '|' . str_repeat(' --- |', count($this->legacyBatchRows[0]))];
            foreach ($this->legacyBatchRows as $legacyBatchRow) {
                $tableLines[] = '| ' . implode(' | ', array_map('strval', $legacyBatchRow)) . ' |';
            }
            $parts[] = "## Delegation batches (legacy fork-join)\n" . implode("\n", $tableLines);
        }
        return implode("\n\n", $parts);
    }
}
