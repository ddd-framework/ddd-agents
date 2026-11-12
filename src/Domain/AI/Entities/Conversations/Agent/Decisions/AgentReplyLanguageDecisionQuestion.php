<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * What the reply-language site asks at one turn start: the owner's recent words and
 * the standing directives the decision model reads, and what PHP resolves the answer against — the configured and
 * supported codes, the newest directive's instant, the sticky anchor and the decision as it stands before the model
 * answered. Built by {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentReplyLanguageDecisionsService::buildRequestInput()}.
 */
class AgentReplyLanguageDecisionQuestion extends ValueObject
{
    /** The index of the owner message this turn answers. */
    public int $ownerMessageIndex = 0;

    /** The owner's own recent words, the evidence of the written-language question. */
    public string $ownerWords = '';

    /** The space's configured language code — the end of the recency chain. */
    public string $configuredLanguageCode = '';

    /** @var string[] The closed answer set: the active interface language codes plus the configured one. */
    public array $supportedLanguageCodes = [];

    /** The standing directives exactly as the context build renders them ('' = none). */
    public string $standingDirectivesBlock = '';

    /** The instant of the newest currently valid directive, null = none. */
    public ?DateTime $newestDirectiveValidFrom = null;

    /** The sticky anchor: the previous decision's language when it came from owner evidence. */
    public ?string $anchorLanguageCode = null;

    /** The anchor's instant, null = no anchor or an unreadable one. */
    public ?DateTime $anchorInstant = null;

    /** The decision as it stands before the model answered; the application completes and stamps it. */
    public ?AgentReplyLanguageDecision $replyLanguageDecision = null;
}
