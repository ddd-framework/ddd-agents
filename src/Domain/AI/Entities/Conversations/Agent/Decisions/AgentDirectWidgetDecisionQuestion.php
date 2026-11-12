<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the direct-widget site asks at one turn start: is the owner's message answered by one
 * widget the model writes directly — the owner message, the thread before it and the answer set, each widget type with
 * the line of its class ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget::DIRECT_WIDGET_WHEN}).
 * Built by {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentDirectWidgetDecisionsService::buildRequestInput()}.
 */
class AgentDirectWidgetDecisionQuestion extends ValueObject
{
    /** The site mode for this conversation (`on` applies, `shadow` records only). */
    public string $mode = AgentDecisionModelSettings::MODE_OFF;

    /** The index of the owner message that started this turn. */
    public int $initiatorMessageIndex = 0;

    public string $ownerMessage = '';

    /** The last assistant text before the owner message. */
    public string $assistantTextBefore = '';

    /** @var string[] The recent turns before the owner message, one line each. */
    public array $recentTurns = [];

    /** @var array<string, string> The answer set: widget type → the situation it is decided for, sorted by type. */
    public array $widgetWhenLines = [];
}
