<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;

/**
 * TEST-ONLY (the server-state arm): which part of one agent call's input goes to OpenAI when OpenAI
 * keeps the conversation state (`store: true` + `previous_response_id`).
 *
 * The call continues the chain of the conversation's newest stored response — the step-marker row whose
 * {@see RequestSnapshot::$serverStateResponseId} is set — and sends only what came after that response: the history
 * entries after the response's own output entry (tool results, owner messages, signals, appended skill texts) plus this
 * call's volatile tail. The server already holds everything before, the system content included (it went as the first
 * developer item of the chain's first call).
 *
 * A call starts a FRESH chain (no `previous_response_id`, the whole input) when there is no stored response, when the
 * model, the system content or the top-level tools differ from the stored response's call, when the history no longer
 * has the stored response's output at the recorded position (rows were dropped, a row landed in front of the output, the
 * output's calls differ), when nothing new follows the response, or when OpenAI rejected the chain
 * ({@see RESTART_PREVIOUS_RESPONSE_REJECTED}). The reason is recorded on the call's snapshot.
 *
 * Semantic differences to the stateless arms, recorded, not fixed: every call's volatile tail stays in OpenAI's history
 * ({@see $tailEntriesInServerHistory} counts them), and the tool-result stubbing and the compaction never reach the
 * history the server holds. Pure: no repository, no configuration.
 *
 * @phpstan-consistent-constructor
 */
class AgentResponsesServerStateChain
{
    public const string RESTART_NO_PREVIOUS_RESPONSE = 'noPreviousResponse';
    public const string RESTART_MODEL_CHANGED = 'modelChanged';
    public const string RESTART_SYSTEM_CONTENT_CHANGED = 'systemContentChanged';
    public const string RESTART_TOOLS_CHANGED = 'toolsChanged';
    public const string RESTART_HISTORY_SHAPE_CHANGED = 'historyShapeChanged';
    public const string RESTART_NOTHING_AFTER_PREVIOUS_RESPONSE = 'nothingAfterPreviousResponse';
    public const string RESTART_PREVIOUS_RESPONSE_REJECTED = 'previousResponseRejected';

    /** @var string|null The stored response this call continues; null = a fresh chain. */
    public ?string $previousResponseId = null;

    /** @var string|null Why this call starts a fresh chain (one of the `RESTART_*` values); null = it continues one. */
    public ?string $restartReason = null;

    /**
     * @var array<int, array<string, mixed>> The Chat-form messages this call sends: the whole list (system first) on a
     *      fresh chain, else the entries after the previous response's output entry.
     */
    public array $chatCompletionsMessagesToSend = [];

    /** @var int How many history entries (system excluded) of this call come before its volatile tail. */
    public int $stableMessageCount = 0;

    /** @var int How many history entries (system excluded) the whole input of this call has — what a stateless call sends. */
    public int $fullHistoryMessageCount = 0;

    /** @var string The hash of this call's system content. */
    public string $systemContentHash = '';

    /** @var string|null The hash of this call's top-level tools (the {@see RequestSnapshot::hashToolsArray()} form). */
    public ?string $toolsHash = null;

    /** @var int The volatile tail entries this call sends. */
    public int $sentTailEntryCount = 0;

    /** @var int The volatile tail entries the server-held history carries after this call (this call's tail included). */
    public int $tailEntriesInServerHistory = 0;

    /**
     * The snapshot of the newest step-marker row that recorded a stored response: an assistant TEXT row or a TOOL_CALL row
     * (persisted without a role) with usage. A signal row is never a link, even when it carries a discarded response's record — that output is not in
     * the history, so the next call's history would not have it at the recorded position.
     *
     * @param AIConversationMessage[] $contextMessages the rows of the call's context, oldest first
     */
    public static function findPreviousLinkSnapshot(array $contextMessages): ?RequestSnapshot
    {
        for ($position = count($contextMessages) - 1; $position >= 0; $position--) {
            $contextMessage = $contextMessages[$position];
            $messageType = $contextMessage->messageType ?? null;
            $isStepMarkerType = $messageType === AIConversationMessage::MESSAGE_TOOL_CALL
                || ($messageType === AIConversationMessage::MESSAGE_TEXT && ($contextMessage->role ?? null) === AIConversationMessage::ROLE_ASSISTANT);
            if (!$isStepMarkerType
                || ($contextMessage->inputTokens ?? 0) <= 0
                || !isset($contextMessage->requestSnapshot)
            ) {
                continue;
            }
            if (($contextMessage->requestSnapshot->serverStateResponseId ?? null) !== null) {
                return $contextMessage->requestSnapshot;
            }
        }
        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $chatCompletionsMessages the whole Chat-form input: the system entry, the history, the volatile tail
     * @param int $stableMessageCount the history entries (system excluded) before the volatile tail
     * @param string|null $toolsHash the hash of this call's top-level tools
     * @param string|null $modelName this call's model
     * @param RequestSnapshot|null $previousLinkSnapshot {@see findPreviousLinkSnapshot()}
     * @param string|null $forcedRestartReason a restart the caller orders (the fallback after a rejected chain)
     */
    public static function decideFor(
        array $chatCompletionsMessages,
        int $stableMessageCount,
        ?string $toolsHash,
        ?string $modelName,
        ?RequestSnapshot $previousLinkSnapshot,
        ?string $forcedRestartReason = null
    ): static {
        $agentResponsesServerStateChain = new static();
        $historyMessages = array_values(array_slice($chatCompletionsMessages, 1));
        $agentResponsesServerStateChain->fullHistoryMessageCount = count($historyMessages);
        $agentResponsesServerStateChain->stableMessageCount = min($stableMessageCount, count($historyMessages));
        $agentResponsesServerStateChain->systemContentHash = self::hashSystemContent($chatCompletionsMessages[0]['content'] ?? '');
        $agentResponsesServerStateChain->toolsHash = $toolsHash;
        $agentResponsesServerStateChain->sentTailEntryCount = count($historyMessages) - $agentResponsesServerStateChain->stableMessageCount;

        $restartReason = $forcedRestartReason ?? $agentResponsesServerStateChain->findRestartReason($historyMessages, $modelName, $previousLinkSnapshot);
        if ($restartReason !== null || $previousLinkSnapshot === null) {
            $agentResponsesServerStateChain->restartReason = $restartReason ?? self::RESTART_NO_PREVIOUS_RESPONSE;
            $agentResponsesServerStateChain->chatCompletionsMessagesToSend = array_values($chatCompletionsMessages);
            $agentResponsesServerStateChain->tailEntriesInServerHistory = $agentResponsesServerStateChain->sentTailEntryCount;
            return $agentResponsesServerStateChain;
        }
        $agentResponsesServerStateChain->previousResponseId = $previousLinkSnapshot->serverStateResponseId;
        $agentResponsesServerStateChain->chatCompletionsMessagesToSend = array_values(array_slice($historyMessages, (int)$previousLinkSnapshot->serverStateStableMessageCount + 1));
        $agentResponsesServerStateChain->tailEntriesInServerHistory = (int)($previousLinkSnapshot->serverStateTailEntriesInServerHistory ?? 0) + $agentResponsesServerStateChain->sentTailEntryCount;
        return $agentResponsesServerStateChain;
    }

    /** Whether this call sends the whole input (a fresh chain). */
    public function startsFreshChain(): bool
    {
        return $this->previousResponseId === null;
    }

    /** The hash of a system content (a string or text parts), the {@see RequestSnapshot::WIRE_HASH_ALGORITHM} form. */
    public static function hashSystemContent(mixed $systemContent): string
    {
        return hash(RequestSnapshot::WIRE_HASH_ALGORITHM, (string)json_encode($systemContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Why this call cannot continue the previous response's chain; null when it can.
     *
     * @param array<int, array<string, mixed>> $historyMessages the history entries (system excluded) with the tail
     */
    protected function findRestartReason(array $historyMessages, ?string $modelName, ?RequestSnapshot $previousLinkSnapshot): ?string
    {
        if ($previousLinkSnapshot === null || $previousLinkSnapshot->serverStateStableMessageCount === null) {
            return self::RESTART_NO_PREVIOUS_RESPONSE;
        }
        if ($modelName !== null && $previousLinkSnapshot->modelName !== null && $modelName !== $previousLinkSnapshot->modelName) {
            return self::RESTART_MODEL_CHANGED;
        }
        if ($previousLinkSnapshot->serverStateSystemContentHash !== $this->systemContentHash) {
            return self::RESTART_SYSTEM_CONTENT_CHANGED;
        }
        if ($previousLinkSnapshot->toolsHash !== $this->toolsHash) {
            return self::RESTART_TOOLS_CHANGED;
        }
        $outputPosition = $previousLinkSnapshot->serverStateStableMessageCount;
        $outputEntry = $outputPosition < $this->stableMessageCount ? ($historyMessages[$outputPosition] ?? null) : null;
        if ($outputEntry === null || ($outputEntry['role'] ?? null) !== 'assistant'
            || self::readToolCallIds($outputEntry) !== array_values($previousLinkSnapshot->serverStateOutputCallIds ?? [])
        ) {
            return self::RESTART_HISTORY_SHAPE_CHANGED;
        }
        if (count($historyMessages) <= $outputPosition + 1) {
            return self::RESTART_NOTHING_AFTER_PREVIOUS_RESPONSE;
        }
        return null;
    }

    /**
     * The call ids of an assistant entry's `tool_calls`, in order.
     *
     * @param array<string, mixed> $assistantEntry
     * @return string[]
     */
    public static function readToolCallIds(array $assistantEntry): array
    {
        $toolCallIds = [];
        foreach ((array)($assistantEntry['tool_calls'] ?? []) as $chatToolCall) {
            if (is_array($chatToolCall)) {
                $toolCallIds[] = (string)($chatToolCall['id'] ?? '');
            }
        }
        return $toolCallIds;
    }
}
