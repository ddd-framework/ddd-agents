<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use Attribute;

/**
 * One slot of a `ComposedView` node type: a place where the node holds other nodes, declared on the node
 * class. The default slot is `children` (`"children": […]`); every other slot is named (`"slots": {"header": […]}`).
 * A child is accepted when one of its categories
 * is in `acceptsCategories` or its type is in `acceptsNodeTypes`; a `heavy` node never in a `compact` slot (unless the
 * slot declares {@see $acceptsHeavyWhenCompact}). The size class tells the child how much room it has: a `Chart` in a
 * compact cell renders as a sparkline, an `Image` as a thumbnail. A slot whose room depends on a prop of its node (a
 * Grid's cell narrows with its `columns`) names that prop in {@see $sizeClassFromProp} and the
 * size class per prop value in {@see $sizeClassesByPropValue}; {@see buildForNodeProps()} gives the slot of one node.
 * Exported into `composed-node-schema.json` (`ComposedViewNodeSchema`).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ComposedViewNodeSlot
{
    public const string SIZE_CLASS_COMPACT = 'compact';
    public const string SIZE_CLASS_REGULAR = 'regular';
    public const string SIZE_CLASS_FULL = 'full';

    /** @var string[] the size classes, smallest first */
    public const array SIZE_CLASSES = [self::SIZE_CLASS_COMPACT, self::SIZE_CLASS_REGULAR, self::SIZE_CLASS_FULL];

    public const string DEFAULT_SLOT_NAME = 'children';

    /**
     * @param string[] $acceptsCategories {@see ComposedViewNodeCategory} values
     * @param string[] $acceptsNodeTypes node types accepted besides the categories (e.g. `Row` in `Table.body`)
     * @param int|null $maxChildren null = no limit beyond the tree's node budget
     * @param string $sizeClass the slot's size class; with {@see $sizeClassFromProp} the one of a node that does not set that prop
     * @param string $sizeClassFromProp the prop of the node the slot's size class follows ('' = the size class is fixed)
     * @param array<int|string, string> $sizeClassesByPropValue prop value → size class (`[1 => 'full', 3 => 'compact']`); a value not listed keeps {@see $sizeClass}
     * @param bool $acceptsHeavyWhenCompact a heavy node (a map, an embedded widget) may sit in the slot while it is compact — the cell of a 3-column Grid
     */
    public function __construct(
        public string $name = self::DEFAULT_SLOT_NAME,
        public array $acceptsCategories = ComposedViewNodeCategory::ALL_BUT_HEAVY,
        public array $acceptsNodeTypes = [],
        public int $minChildren = 0,
        public ?int $maxChildren = null,
        public string $sizeClass = self::SIZE_CLASS_REGULAR,
        public string $description = '',
        public string $sizeClassFromProp = '',
        public array $sizeClassesByPropValue = [],
        public bool $acceptsHeavyWhenCompact = false,
    ) {
    }

    /** Whether a size class `$sizeClass` fits into this slot (a compact widget fits everywhere, a full one only into full slots). */
    public function fitsSizeClass(string $sizeClass): bool
    {
        $sizeClassRank = array_search($sizeClass, self::SIZE_CLASSES, true);
        $slotSizeClassRank = array_search($this->sizeClass, self::SIZE_CLASSES, true);
        return $sizeClassRank !== false && $slotSizeClassRank !== false && $sizeClassRank <= $slotSizeClassRank;
    }

    /**
     * The slot of one node: its size class read from the node's {@see $sizeClassFromProp} (a Grid with `columns: 3` gives
     * compact cells); the declaration itself when the size class is fixed or the prop's value is not listed.
     *
     * @param array<string, mixed> $nodeProps
     */
    public function buildForNodeProps(array $nodeProps): static
    {
        $propValue = $this->sizeClassFromProp !== '' ? ($nodeProps[$this->sizeClassFromProp] ?? null) : null;
        $sizeClassOfPropValue = is_int($propValue) || is_string($propValue) ? ($this->sizeClassesByPropValue[$propValue] ?? null) : null;
        if (!is_string($sizeClassOfPropValue) || $sizeClassOfPropValue === $this->sizeClass) {
            return $this;
        }
        $slotOfNode = clone $this;
        $slotOfNode->sizeClass = $sizeClassOfPropValue;
        return $slotOfNode;
    }

    /** @return array<string, mixed> the slot as `composed-node-schema.json` lists it (the prop-driven size and the heavy flag only where set) */
    public function toSchemaArray(): array
    {
        $schemaArray = [
            'name' => $this->name,
            'acceptsCategories' => array_values($this->acceptsCategories),
            'acceptsNodeTypes' => array_values($this->acceptsNodeTypes),
            'minChildren' => $this->minChildren,
            'maxChildren' => $this->maxChildren,
            'sizeClass' => $this->sizeClass,
            'description' => $this->description,
        ];
        if ($this->sizeClassFromProp !== '') {
            $schemaArray['sizeClassFromProp'] = $this->sizeClassFromProp;
            $sizeClassesByPropValue = [];
            foreach ($this->sizeClassesByPropValue as $propValue => $sizeClassOfPropValue) {
                $sizeClassesByPropValue[(string)$propValue] = $sizeClassOfPropValue;
            }
            $schemaArray['sizeClassesByPropValue'] = (object)$sizeClassesByPropValue;
        }
        if ($this->acceptsHeavyWhenCompact) {
            $schemaArray['acceptsHeavyWhenCompact'] = true;
        }
        return $schemaArray;
    }
}
