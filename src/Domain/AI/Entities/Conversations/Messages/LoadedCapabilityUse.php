<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * A {@see LoadedCapability::markUsedAt()} stamp — only the use fields ({@see LoadedCapability::USE_FIELDS}) changed.
 * Applied like {@see LoadedCapabilityUpdate}; the operation is a label for the reader of a stored row.
 */
class LoadedCapabilityUse extends LoadedCapabilityFieldsChange
{
    public const string OPERATION = 'use';
}
