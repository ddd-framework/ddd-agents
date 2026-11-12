<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The conversation as the turn-start decision questions read it before ONE message: the assistant's last text before
 * that message and the last owner / assistant turns, each as one `owner: …` / `assistant: …` line. Built by
 * {@see AIConversationOwnerTurns::buildRecentTurnTextsBefore()}.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationRecentTurnTexts extends ValueObject
{
    /** @var string The assistant's last text before the message, uncut; empty when the assistant wrote none */
    public string $assistantTextBefore = '';

    /** @var string[] The last owner / assistant turns before the message, oldest first, each cut to the turn length */
    public array $recentTurns = [];

    /**
     * @param string $assistantTextBefore
     * @param string[] $recentTurns
     * @return static
     */
    public static function forAssistantTextAndRecentTurns(string $assistantTextBefore, array $recentTurns): static
    {
        $recentTurnTexts = new static();
        $recentTurnTexts->assistantTextBefore = $assistantTextBefore;
        $recentTurnTexts->recentTurns = $recentTurns;
        return $recentTurnTexts;
    }
}
