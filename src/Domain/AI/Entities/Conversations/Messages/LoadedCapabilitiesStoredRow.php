<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One stored message row as the migration of legacy rows reads it ({@see LoadedCapabilitiesChain::compactStoredRows()}):
 * its id, whether it opens a turn, and its stored `loadedCapabilities` column.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilitiesStoredRow extends ValueObject
{
    /** The row's database id. */
    public int $id = 0;

    /** Whether the row is the owner's (or a legacy USER-role) row — a turn initiator. */
    public bool $isOwnerOrLegacyUserRoleRow = false;

    /** The stored `loadedCapabilities` column; null when the row carries no set. */
    public ?string $loadedCapabilities = null;

    public static function create(int $id, bool $isOwnerOrLegacyUserRoleRow, ?string $loadedCapabilities): static
    {
        $loadedCapabilitiesStoredRow = new static();
        $loadedCapabilitiesStoredRow->id = $id;
        $loadedCapabilitiesStoredRow->isOwnerOrLegacyUserRoleRow = $isOwnerOrLegacyUserRoleRow;
        $loadedCapabilitiesStoredRow->loadedCapabilities = $loadedCapabilities;
        return $loadedCapabilitiesStoredRow;
    }
}
