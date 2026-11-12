<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The settings a NEW purposed conversation is created with: its run settings (the start's own
 * with the purpose's `runSettings:` defaults filled in) and its context retention settings (the purpose's
 * `contextRetention:` map), both still to be stamped by the creation. When the purpose's AGENT WIRE PROFILE cannot run
 * on the conversation's model ({@see AIConversationPurpose::declaresAgentWireProfile()}), the profile is left out as a
 * whole — the run settings are the start's own, the retention map loses its three creation choices — and
 * {@see $agentWireProfileRejectionReason} names why.
 */
class AIConversationPurposeStartSettings extends ValueObject
{
    /** @var RunSettings|null The run settings the conversation is created with; null = none. */
    public ?RunSettings $runSettings = null;

    /** @var ContextRetentionSettings|null The context retention settings the creation stamps; null = the global defaults. */
    public ?ContextRetentionSettings $contextRetentionSettings = null;

    /**
     * @var string|null Why the purpose's agent wire profile does not apply to this conversation (the rejection of
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration} for the profile on the conversation's
     * model); null = the profile applies, or the purpose declares none.
     */
    public ?string $agentWireProfileRejectionReason = null;

    /** Whether the purpose's agent wire profile was left out because the conversation's model cannot run it. */
    public function agentWireProfileFellBack(): bool
    {
        return $this->agentWireProfileRejectionReason !== null;
    }
}
