<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The slot and prop declarations of ONE `ComposedView` node class, as its class attributes declare them. Read once per
 * class by {@see ComposedViewNode::readDeclarations()} and kept in {@see ComposedViewNode::$declarationsByNodeClass}.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewNodeDeclarations extends ValueObject
{
    /** @var ComposedViewNodeSlot[] The slots the node class declares, attribute order */
    public array $slotDeclarations = [];

    /** @var ComposedViewNodeProp[] The props the node class declares, attribute order */
    public array $propDeclarations = [];

    /**
     * @param ComposedViewNodeSlot[] $slotDeclarations
     * @param ComposedViewNodeProp[] $propDeclarations
     * @return static
     */
    public static function forSlotsAndProps(array $slotDeclarations, array $propDeclarations): static
    {
        $composedViewNodeDeclarations = new static();
        $composedViewNodeDeclarations->slotDeclarations = $slotDeclarations;
        $composedViewNodeDeclarations->propDeclarations = $propDeclarations;
        return $composedViewNodeDeclarations;
    }
}
