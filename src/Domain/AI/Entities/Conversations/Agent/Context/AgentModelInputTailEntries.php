<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Context;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The model input's volatile TAIL in wire order — the ONE list both the live egress
 * ({@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation::buildWireMessagesFromModelInput()}) and the
 * admin composition view ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::buildContextComposition()})
 * read, so the two cannot list different entries by accident. Built by
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput::buildTailEntries()}; the reply-language cue is always
 * the last element.
 *
 * @property AgentModelInputTailEntry[] $elements
 * @method AgentModelInputTailEntry[] getElements()
 * @method AgentModelInputTailEntry|null first()
 */
class AgentModelInputTailEntries extends ObjectSet
{
    /**
     * Every entry as its OpenAI Chat wire message, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toWireMessages(): array
    {
        return array_map(
            static fn (AgentModelInputTailEntry $agentModelInputTailEntry): array => $agentModelInputTailEntry->toWireMessage(),
            $this->getElements()
        );
    }
}
