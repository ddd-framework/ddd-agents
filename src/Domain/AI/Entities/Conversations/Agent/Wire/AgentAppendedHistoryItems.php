<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

/**
 * Every item an append-only conversation's model calls appended inside the current prefix epoch, by position: the items of earlier calls sit where those calls sent them — right after the newest row each call saw, i.e.
 * before the first row above it (the call's first output row records what it appended and that anchor; a nudge, a
 * bounce or an owner row written meanwhile never moves it) —, the items of THIS call at the end of the stable history,
 * before the volatile tail. The egress folds them into the wire messages ({@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation::buildOpenAiChatMessagesArray()}).
 * Pure.
 */
class AgentAppendedHistoryItems
{
    /** @var AgentAppendedHistoryInsertion[] message index => the insertion placed right before that row */
    public array $insertionsBeforeMessageIndex = [];

    /** @var AgentAppendedHistoryInsertion|null The insertion of this call, at the end of the stable history. */
    public ?AgentAppendedHistoryInsertion $insertionOfThisCall = null;

    /**
     * Place `$agentAppendedHistoryInsertion` right before the row with `$messageIndex`; a second item at the same row (two
     * recorded calls anchored after the same row) follows the first one there.
     */
    public function addInsertionBeforeMessageIndex(int $messageIndex, AgentAppendedHistoryInsertion $agentAppendedHistoryInsertion): void
    {
        $existingInsertion = $this->insertionsBeforeMessageIndex[$messageIndex] ?? null;
        if ($existingInsertion === null) {
            $this->insertionsBeforeMessageIndex[$messageIndex] = $agentAppendedHistoryInsertion;
            ksort($this->insertionsBeforeMessageIndex);
            return;
        }
        $existingInsertion->chatCompletionsToolSchemas = [...$existingInsertion->chatCompletionsToolSchemas, ...$agentAppendedHistoryInsertion->chatCompletionsToolSchemas];
        $existingInsertion->developerText = implode("\n\n", array_values(array_filter([$existingInsertion->developerText, $agentAppendedHistoryInsertion->developerText], static fn (string $developerText): bool => $developerText !== '')));
        $existingInsertion->userTexts = [...$existingInsertion->userTexts, ...$agentAppendedHistoryInsertion->userTexts];
    }

    /**
     * The message indexes the items are placed before, ascending — the egress emits each one before the first row it
     * visits whose index reaches it (a row folded into the group before it is never visited on its own).
     *
     * @return int[]
     */
    public function getInsertionMessageIndexesInOrder(): array
    {
        $insertionMessageIndexes = array_map('intval', array_keys($this->insertionsBeforeMessageIndex));
        sort($insertionMessageIndexes);
        return $insertionMessageIndexes;
    }

    /**
     * The wire entries placed right before the row with `$messageIndex` (none for most rows).
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildWireEntriesBeforeMessageIndex(?int $messageIndex): array
    {
        if ($messageIndex === null || !isset($this->insertionsBeforeMessageIndex[$messageIndex])) {
            return [];
        }
        return $this->insertionsBeforeMessageIndex[$messageIndex]->toWireEntries();
    }

    /**
     * The wire entries of this call, after the last history row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildWireEntriesOfThisCall(): array
    {
        return $this->insertionOfThisCall?->toWireEntries() ?? [];
    }
}
