<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Context;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the model is told about the customer's product: the plan block with the product line on top,
 * the capabilities this user may not use and the AI-budget policy — the three
 * per-account-stable system segments. Built by
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::findProductAwareness()}; the context build copies the
 * three texts. Never persisted. Null = the segment is omitted.
 */
class AgentProductAwareness extends ValueObject
{
    /** The product line and the plan's capabilities; null without an agent space or when both are empty. */
    public ?string $productCapabilities = null;

    /** The capabilities the space has but this user may not use; null without rights or when nothing is restricted. */
    public ?string $restrictedCapabilities = null;

    /** The number-free AI-budget policy; null without an agent space or without an AI budget flag. */
    public ?string $aiBudgetAwareness = null;
}
