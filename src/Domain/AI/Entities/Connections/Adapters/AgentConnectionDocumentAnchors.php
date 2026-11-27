<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property AgentConnectionDocumentAnchor[] $elements
 * @method AgentConnectionDocumentAnchor[] getElements()
 */
class AgentConnectionDocumentAnchors extends ObjectSet
{
    public function findByAnchor(string $anchor): ?AgentConnectionDocumentAnchor
    {
        foreach ($this->getElements() as $documentAnchor) {
            if ($documentAnchor->anchor === $anchor) {
                return $documentAnchor;
            }
        }
        return null;
    }
}
