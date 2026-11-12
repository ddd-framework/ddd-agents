<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One row the migration of legacy rows rewrites ({@see LoadedCapabilitiesChain::compactStoredRows()}): its id and the
 * change record its `loadedCapabilities` column gets instead of the complete set.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilitiesRowRewrite extends ValueObject
{
    /** The row's database id. */
    public int $id = 0;

    /** The stored change record (`{"v":2,"e":[…]}`) the column is rewritten to. */
    public string $loadedCapabilities = '';

    public static function create(int $id, string $loadedCapabilities): static
    {
        $loadedCapabilitiesRowRewrite = new static();
        $loadedCapabilitiesRowRewrite->id = $id;
        $loadedCapabilitiesRowRewrite->loadedCapabilities = $loadedCapabilities;
        return $loadedCapabilitiesRowRewrite;
    }
}
