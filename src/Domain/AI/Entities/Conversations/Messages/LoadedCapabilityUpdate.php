<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/** Any field change of a capability that stays in the set other than a pure use stamp ({@see LoadedCapabilityUse}). */
class LoadedCapabilityUpdate extends LoadedCapabilityFieldsChange
{
    public const string OPERATION = 'set';
}
