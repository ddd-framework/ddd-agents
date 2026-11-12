<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one model call is sent with beyond the conversation itself: the loop resolves it before the
 * call — the owner turn's decided reasoning effort, the fast service tier, and the step's timings so far (recorded on
 * the request snapshot's overhead breakdown).
 */
class AgentModelCallSettings extends ValueObject
{
    /** The effort the call is sent with — null = the catalog value. */
    public ?string $reasoningEffortOverride = null;

    /** The model's fast service tier: a purpose that asks for it, or a conversation with the customer present. */
    public bool $fastModeRequested = false;

    /** The step's milliseconds before the call, for the snapshot's overhead breakdown. */
    public ?int $preCallMs = null;

    /** The step's milliseconds spent building the tool schemas, for the snapshot's overhead breakdown. */
    public ?int $schemasMs = null;
}
