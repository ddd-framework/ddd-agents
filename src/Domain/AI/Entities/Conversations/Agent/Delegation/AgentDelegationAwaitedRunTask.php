<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Delegation;

use DDD\Domain\AI\Entities\Skills\AISkills;

/**
 * An awaited run: a conversation that already runs on its own — with its own purpose, seed, caps and
 * tools (the nightly digest of a space that a report run waits for) — becomes the child of a sub-agent of the
 * requesting conversation. No child is created and nothing is seeded: the sub-agent's record waits for that conversation's
 * end, which reaches the parent as the run's one notification like any other run end. It takes no messages
 * (`message_sub_agent` refuses it like a tool run).
 *
 * @phpstan-consistent-constructor
 */
class AgentDelegationAwaitedRunTask extends AgentDelegationTask
{
    /** The stored `kind` of an awaited run in a sub-agent record. */
    public const string KIND_AWAITED_RUN = 'awaitedRun';

    /** The conversation the sub-agent waits for. */
    public int $awaitedConversationId = 0;

    public static function forConversation(int $awaitedConversationId): static
    {
        $agentDelegationAwaitedRunTask = new static();
        $agentDelegationAwaitedRunTask->awaitedConversationId = $awaitedConversationId;
        return $agentDelegationAwaitedRunTask;
    }

    /** @return array{kind: string, awaitedConversationId: int} */
    public function buildStoredTaskArray(): array
    {
        return ['kind' => self::KIND_AWAITED_RUN, 'awaitedConversationId' => $this->awaitedConversationId];
    }

    public function buildDefaultLabel(): string
    {
        return self::cutToLabel('Conversation #' . $this->awaitedConversationId);
    }

    /** No child is seeded: the awaited conversation carries its own seed. */
    public function buildChildSeedText(): string
    {
        return '';
    }

    /** @return array{awaitedConversationId: int} the `delegate` row's arguments name the awaited conversation */
    public function buildDelegateCallArgumentItem(): array
    {
        return ['awaitedConversationId' => $this->awaitedConversationId];
    }

    /** @return array{awaitedConversationId: int} */
    public function buildDelegateCallArguments(): array
    {
        return ['awaitedConversationId' => $this->awaitedConversationId];
    }

    /** The awaited conversation keeps its own purpose. */
    public function getChildPurposeName(): ?string
    {
        return null;
    }

    public function requiresModel(): bool
    {
        return true;
    }

    /** @return string[] nothing is seeded */
    public function getRequestedSeedSkillNames(): array
    {
        return [];
    }

    /** @return string[] nothing is seeded */
    public function buildSeedSkillNames(AISkills $permittedCatalog): array
    {
        return [];
    }
}
