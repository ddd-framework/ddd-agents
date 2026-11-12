<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Delegation;

use DDD\Domain\AI\Entities\Skills\AISkills;

/**
 * An agent task: a sub-agent's model loop works on {@see $taskText} with {@see $skillNames} preloaded (today's
 * `{task, skill_names}` item of a `delegate` call). The seed text is the task text itself.
 *
 * @phpstan-consistent-constructor
 */
class AgentDelegationAgentTask extends AgentDelegationTask
{
    /** The self-contained task the sub-agent works on (trimmed, never blank). */
    public string $taskText = '';

    /** @var string[] the skills the sub-agent's seed preloads, as requested */
    public array $skillNames = [];

    /** @param string[] $skillNames */
    public static function forTask(string $taskText, array $skillNames): static
    {
        $agentDelegationAgentTask = new static();
        $agentDelegationAgentTask->taskText = $taskText;
        $agentDelegationAgentTask->skillNames = $skillNames;
        return $agentDelegationAgentTask;
    }

    /**
     * One item of a STORED `delegate` call's `tasks` argument, read as before sub-agent records existed: every item with a non-blank
     * `task` is an agent task (a `toolName` beside it is ignored), anything else is skipped. The reader of old rows and
     * of today's model calls ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateToolArguments::fromLegacyArguments()}).
     */
    public static function fromLegacyArgumentItem(mixed $argumentItem): ?self
    {
        if (!is_array($argumentItem)) {
            return null;
        }
        $taskText = trim((string)($argumentItem['task'] ?? ''));
        if ($taskText === '') {
            return null;
        }
        return self::forTask($taskText, self::readSkillNamesFromArgumentItem($argumentItem));
    }

    /**
     * The `skill_names` of an argument item: always a (possibly empty) string list.
     *
     * @param array<string, mixed> $argumentItem
     * @return string[]
     */
    public static function readSkillNamesFromArgumentItem(array $argumentItem): array
    {
        $requestedSkillNames = $argumentItem['skill_names'] ?? [];
        return is_array($requestedSkillNames) ? array_values(array_map('strval', $requestedSkillNames)) : [];
    }

    /** Whether the task names at least one skill — a sub-agent without any has no work tools. */
    public function hasSkillNames(): bool
    {
        return $this->skillNames !== [];
    }

    /** @return array{kind: string, taskText: string, skillNames: string[]} */
    public function buildStoredTaskArray(): array
    {
        return ['kind' => self::KIND_AGENT, 'taskText' => $this->taskText, 'skillNames' => $this->skillNames];
    }

    /** The task text's first sentence, cut to a label. */
    public function buildDefaultLabel(): string
    {
        return self::cutToLabel($this->taskText);
    }

    public function buildChildSeedText(): string
    {
        return $this->taskText;
    }

    /** @return array{task: string, skill_names: string[]} */
    public function buildDelegateCallArgumentItem(): array
    {
        return ['task' => $this->taskText, 'skill_names' => $this->skillNames];
    }

    /** @return array{task: string, skill_names: string[]} */
    public function buildDelegateCallArguments(): array
    {
        return ['task' => $this->taskText, 'skill_names' => $this->skillNames];
    }

    public function getChildPurposeName(): ?string
    {
        return null;
    }

    public function requiresModel(): bool
    {
        return true;
    }

    /** @return string[] the task's skills */
    public function getRequestedSeedSkillNames(): array
    {
        return $this->skillNames;
    }

    /** @return string[] the requested skills as given; the parent's gates filter them when the child is seeded */
    public function buildSeedSkillNames(AISkills $permittedCatalog): array
    {
        return $this->skillNames;
    }
}
