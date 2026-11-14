<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Delegation;

use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The ONE task of one sub-agent: an agent task ({@see AgentDelegationAgentTask} —
 * a model loop works on a task text with preloaded skills) or a tool run ({@see AgentDelegationToolRunTask} — one call
 * of one tool, no model) or an awaited run ({@see AgentDelegationAwaitedRunTask} — a conversation that runs on its
 * own). The task describes itself: the child's seed text, the item stored in the `delegate` call's
 * arguments, the child's purpose, and the skills its seed preloads. Pure value object, no repo contact.
 */
abstract class AgentDelegationTask extends ValueObject
{
    /** The stored `kind` of an agent task in a sub-agent record. */
    public const string KIND_AGENT = 'agent';

    /** The stored `kind` of a tool run in a sub-agent record. */
    public const string KIND_TOOL_RUN = 'toolRun';

    /** The longest label a sub-agent carries. */
    public const int LABEL_MAX_LENGTH = 40;

    /**
     * One item of a `delegate` call: an item that names a tool
     * ({@see AgentDelegationToolRunTask::readToolNameFromArgumentItem()}) is a tool run — a `task` text beside it is
     * not read, the tool's arguments say what runs; an item with a non-blank `task` that names no tool is an agent
     * task; an item with neither is no task (null — the caller counts it as refused).
     *
     * @param array<string, mixed> $argumentItem
     */
    public static function fromArgumentItem(array $argumentItem): ?self
    {
        $toolName = AgentDelegationToolRunTask::readToolNameFromArgumentItem($argumentItem);
        if ($toolName !== null) {
            return AgentDelegationToolRunTask::forToolCall(
                $toolName,
                AgentDelegationToolRunTask::readToolArgumentsFromArgumentItem($argumentItem)
            );
        }
        $taskText = $argumentItem['task'] ?? null;
        if (!is_string($taskText) || trim($taskText) === '') {
            return null;
        }
        return AgentDelegationAgentTask::forTask(
            trim($taskText),
            AgentDelegationAgentTask::readSkillNamesFromArgumentItem($argumentItem)
        );
    }

    /**
     * The task as stored in a sub-agent record (`{"kind": …, …}`), read back by {@see fromStoredTaskArray()}.
     *
     * @return array<string, mixed>
     */
    abstract public function buildStoredTaskArray(): array;

    /** The label a sub-agent of this task carries when nobody named one (at most {@see LABEL_MAX_LENGTH} characters). */
    abstract public function buildDefaultLabel(): string;

    /**
     * A task stored by {@see buildStoredTaskArray()}; null for an unknown kind or a task without its text / tool.
     *
     * @param array<string, mixed> $storedTask
     */
    public static function fromStoredTaskArray(array $storedTask): ?self
    {
        $kind = $storedTask['kind'] ?? null;
        if ($kind === self::KIND_AGENT && is_string($storedTask['taskText'] ?? null) && $storedTask['taskText'] !== '') {
            $skillNames = is_array($storedTask['skillNames'] ?? null) ? array_values(array_map('strval', $storedTask['skillNames'])) : [];
            return AgentDelegationAgentTask::forTask($storedTask['taskText'], $skillNames);
        }
        if ($kind === AgentDelegationAwaitedRunTask::KIND_AWAITED_RUN && is_numeric($storedTask['awaitedConversationId'] ?? null)) {
            return AgentDelegationAwaitedRunTask::forConversation((int)$storedTask['awaitedConversationId']);
        }
        $legacyBackgroundOperationName = is_string($storedTask['legacyBackgroundOperation'] ?? null) && $storedTask['legacyBackgroundOperation'] !== ''
            ? $storedTask['legacyBackgroundOperation'] : null;
        $storedToolName = is_string($storedTask['toolName'] ?? null) ? $storedTask['toolName'] : '';
        if ($kind === self::KIND_TOOL_RUN && ($storedToolName !== '' || $legacyBackgroundOperationName !== null)) {
            $toolArguments = is_array($storedTask['toolArguments'] ?? null) ? $storedTask['toolArguments'] : [];
            $requestedSeedSkillNames = is_array($storedTask['seedSkillNames'] ?? null) ? array_values(array_map('strval', $storedTask['seedSkillNames'])) : [];
            $agentDelegationToolRunTask = $legacyBackgroundOperationName === null
                ? AgentDelegationToolRunTask::forToolCall($storedToolName, $toolArguments, $requestedSeedSkillNames)
                : AgentDelegationToolRunTask::forLegacyBackgroundOperation($legacyBackgroundOperationName, $storedToolName, $toolArguments, $requestedSeedSkillNames);
            $agentDelegationToolRunTask->warmedCacheIdentity = is_string($storedTask['warmedCacheIdentity'] ?? null) && $storedTask['warmedCacheIdentity'] !== ''
                ? $storedTask['warmedCacheIdentity'] : null;
            return $agentDelegationToolRunTask;
        }
        return null;
    }

    /**
     * A text cut to a label: its first sentence (up to the first `.`, `!` or `?` followed by a space, or the first line
     * break), at most {@see LABEL_MAX_LENGTH} characters, cut on a word boundary (mid-word only for one long word).
     */
    public static function cutToLabel(string $text): string
    {
        $firstLine = trim((string)preg_split('/\R/u', trim($text))[0]);
        $firstSentence = trim((string)preg_split('/(?<=[.!?])\s/u', $firstLine)[0]);
        if (mb_strlen($firstSentence) <= self::LABEL_MAX_LENGTH) {
            return $firstSentence;
        }
        $cut = mb_substr($firstSentence, 0, self::LABEL_MAX_LENGTH + 1);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace === false || $lastSpace === 0) {
            return mb_substr($firstSentence, 0, self::LABEL_MAX_LENGTH);
        }
        return rtrim(mb_substr($cut, 0, $lastSpace), " ,;:-–—");
    }

    /** The seed USER message of the child that runs this task. */
    abstract public function buildChildSeedText(): string;

    /**
     * The item this task occupies in the `tasks` argument of a stored `delegate` call.
     *
     * @return array<string, mixed>
     */
    abstract public function buildDelegateCallArgumentItem(): array;

    /**
     * The arguments of the ONE `delegate` call that carries this task (the synthetic row the platform
     * writes for a requested sub-agent, in the shape the model's own call has), without the label.
     *
     * @return array<string, mixed>
     */
    abstract public function buildDelegateCallArguments(): array;

    /** The purpose the child runs under; null = none. */
    abstract public function getChildPurposeName(): ?string;

    /** Whether the child runs a model loop (an agent task) or executes its call without one (a tool run). */
    abstract public function requiresModel(): bool;

    /**
     * The skills the requester named for the child's seed, as given — no catalog lookup (the background work's legacy
     * spawn seeds exactly these, an empty list seeds no capability set).
     *
     * @return string[]
     */
    abstract public function getRequestedSeedSkillNames(): array;

    /**
     * The skills the child's seed preloads, before the parent's entitlement, vision and purpose-binding gates.
     *
     * @return string[]
     */
    abstract public function buildSeedSkillNames(AISkills $permittedCatalog): array;
}
