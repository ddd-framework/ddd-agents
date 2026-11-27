<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One anchor of a structured read (`p:12`, `tbl:2 r3c1`, `nr:Summary`, `s:3 obj:g12ab`) and where it lies in the read
 * revision: the start and end index of a Docs element, or the object id of a Slides shape.
 */
class AgentConnectionDocumentAnchor extends ValueObject
{
    public string $anchor = '';
    public int $startIndex = 0;
    public int $endIndex = 0;
    public ?string $objectId = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->anchor);
    }
}
