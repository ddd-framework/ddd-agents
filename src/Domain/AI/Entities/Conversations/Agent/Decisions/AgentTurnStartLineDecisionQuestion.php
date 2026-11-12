<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the turn-start line site asks at one turn start: does the assistant have to read, search
 * or change something before it can answer the owner's message — the owner message and the thread before it. Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentTurnStartLineDecisionsService::buildRequestInput()}.
 */
class AgentTurnStartLineDecisionQuestion extends ValueObject
{
    /** The site mode for this conversation (`on` applies, `shadow` records only). */
    public string $mode = AgentDecisionModelSettings::MODE_OFF;

    /** The index of the owner message that started this turn. */
    public int $initiatorMessageIndex = 0;

    /** The id of that owner message — the identity of the line on the activity stack. */
    public int $initiatorMessageId = 0;

    public string $ownerMessage = '';

    /** The last assistant text before the owner message. */
    public string $assistantTextBefore = '';

    /** @var string[] The recent turns before the owner message, one line each. */
    public array $recentTurns = [];
}
