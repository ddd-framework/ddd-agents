<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Variants;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The variant dimensions a purpose declares, by name.
 *
 * @property AIConversationVariantDimension[] $elements
 * @method AIConversationVariantDimension[] getElements()
 * @method AIConversationVariantDimension|null first()
 */
class AIConversationVariantDimensions extends ObjectSet
{
    public function getByName(string $name): ?AIConversationVariantDimension
    {
        foreach ($this->getElements() as $dimension) {
            if ($dimension->name === $name) {
                return $dimension;
            }
        }
        return null;
    }

    /** @return string[] */
    public function getNames(): array
    {
        return array_values(array_map(static fn (AIConversationVariantDimension $dimension): string => $dimension->name, $this->getElements()));
    }
}
