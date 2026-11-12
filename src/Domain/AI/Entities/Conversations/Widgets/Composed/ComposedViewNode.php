<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\Base\Entities\ValueObject;
use ReflectionClass;

/**
 * One node of a `ComposedView` tree: its type, an optional id, its props (literals and bindings,
 * as JSON values) and the nodes in its slots. Every node type is a subclass `<Type>ComposedViewNode` in
 * `Widgets/Composed/Nodes/<Group>/`, found by folder ({@see ComposedViewNodeClasses}); the subclass declares what the
 * type is — `NODE_TYPE`, `CATEGORIES`, whether it opens an item scope (`PROVIDES_ITEM_SCOPE`, `Repeat`), its props
 * ({@see ComposedViewNodeProp}) and its slots ({@see ComposedViewNodeSlot}) — and a lane adds a type with one new file.
 *
 * Wire form (JSON): `{"type":"Surface","id":"kpis","title":"Visits","children":[…],"slots":{"header":[…]}}` —
 * `type`, `id`, `children` (the default slot) and `slots` (the named slots) are reserved, every other key is a prop.
 * Read by {@see ComposedViewTreeReading}, written by {@see toWireArray()}. Reads nothing.
 */
abstract class ComposedViewNode extends ValueObject
{
    /** @var string The wire type (`Surface`, `Chart`, …). */
    public const string NODE_TYPE = '';

    /** @var string[] The {@see ComposedViewNodeCategory} values of the type. */
    public const array CATEGORIES = [];

    /** @var bool The node opens an item scope for its children (`{"$item":…}` bindings), like `Repeat`. */
    public const bool PROVIDES_ITEM_SCOPE = false;

    /** @var string One line for the model spec and the schema: what the node shows. */
    public const string SUMMARY = '';

    /**
     * @var string The node's TEXT prop — the prop that carries its main text (`markdown` of a Markdown, `code` of a
     * Code), named on the node's line of the generated kit even when optional; '' = the type's `text` prop when it
     * declares one ({@see getTextContentPropName()}).
     */
    public const string TEXT_CONTENT_PROP_NAME = '';

    /**
     * @var string[] The props whose literal holds ROWS of figures (Chart `data`, Table `rows`, Repeat `items`): a
     * written list, or a `$data` binding to a written dataset, is researched inline data that needs a source
     * ({@see NEEDS_A_SOURCE_FOR_INLINE_FIGURES}); a `$from`, `$source`, `$computed` or `$item` is not.
     */
    public const array INLINE_ROWS_PROP_NAMES = [];

    /**
     * @var string[] The props whose literal is ONE figure (KpiTile `value`, TableCell `value`): a number, or a text that
     * starts with a digit or a sign ("4,32 %", "-3"), is a researched figure; a binding or a word ("Yes") is not.
     */
    public const array INLINE_FIGURE_PROP_NAMES = [];

    /**
     * @var bool The node shows figures (Chart, Table, KpiTile): when its subtree holds an inline figure
     * ({@see INLINE_ROWS_PROP_NAMES}, {@see INLINE_FIGURE_PROP_NAMES}), it needs its `source` prop, a source on a data
     * node around it, or a node that names the sources ({@see NAMES_THE_SOURCES}) in the tree — else the reader
     * flags `INLINE_VALUE_WITHOUT_SOURCE` (kept, rendered; the render report tells the model).
     */
    public const bool NEEDS_A_SOURCE_FOR_INLINE_FIGURES = false;

    /** @var bool The node names the sources of the tree's figures (`SourceRef`). */
    public const bool NAMES_THE_SOURCES = false;

    /** @var string The prop a data node names its source in ({@see NEEDS_A_SOURCE_FOR_INLINE_FIGURES}). */
    public const string SOURCE_PROP_NAME = 'source';

    /**
     * @var string The prop that lists the node's choices (`options` of Tabs, Select, RadioGroup): the initial value of the
     * state its event sets must be one of them, or the reader flags `INVALID_INITIAL_CHOICE` (kept); '' = no choices.
     */
    public const string OPTIONS_PROP_NAME = '';

    /**
     * @var string The prop a node with {@see PROVIDES_ITEM_SCOPE} repeats its children over (`items`); its `$item`
     * bindings read the fields of these rows.
     */
    public const string ITEMS_PROP_NAME = 'items';

    /**
     * @var bool The node sends the state of the inputs inside it (`Form`): a state an input in it binds or sets counts as
     * read ({@see ComposedViewTreeReading}, `UNREAD_STATE`).
     */
    public const bool SUBMITS_THE_STATE_OF_ITS_INPUTS = false;

    /** @var string[] The wire keys that are no props. */
    public const array RESERVED_WIRE_KEYS = ['type', 'id', 'children', 'slots'];

    /** @var ComposedViewNodeDeclarations[] The declarations read once per class, keyed by node class. */
    protected static array $declarationsByNodeClass = [];

    /** @var string|null The node's id, unique in the tree when set (a canvas instance, a test). */
    public ?string $id = null;

    /** @var array<string, mixed> The props as JSON values, literals and bindings. */
    public array $props = [];

    /** @var ComposedViewNodesOfSlots|null The nodes in each slot (`children` = the default slot); null = no slot was read. */
    public ?ComposedViewNodesOfSlots $nodesOfSlots = null;

    /** @return ComposedViewNode[] The nodes in a slot, in wire order; empty when the slot holds none or was not read. */
    public function getNodesOfSlot(string $slotName): array
    {
        return $this->nodesOfSlots?->getNodesOfSlot($slotName) ?? [];
    }

    /**
     * Sets the nodes of a slot.
     *
     * @param ComposedViewNode[] $slotNodes
     */
    public function setNodesOfSlot(string $slotName, array $slotNodes): void
    {
        $this->nodesOfSlots ??= new ComposedViewNodesOfSlots();
        $this->nodesOfSlots->setNodesOfSlot($slotName, $slotNodes);
    }

    /** @return ComposedViewNodesOfSlot[] The slots that were read with their nodes, in the order they were read. */
    public function getNodesOfSlots(): array
    {
        return $this->nodesOfSlots?->getElements() ?? [];
    }

    /**
     * The slots the type declares.
     *
     * @return ComposedViewNodeSlot[]
     */
    public static function getSlotDeclarations(): array
    {
        return static::readDeclarations()->slotDeclarations;
    }

    /**
     * The props the type declares.
     *
     * @return ComposedViewNodeProp[]
     */
    public static function getPropDeclarations(): array
    {
        return static::readDeclarations()->propDeclarations;
    }

    public static function findSlotDeclaration(string $slotName): ?ComposedViewNodeSlot
    {
        foreach (static::getSlotDeclarations() as $composedViewNodeSlot) {
            if ($composedViewNodeSlot->name === $slotName) {
                return $composedViewNodeSlot;
            }
        }
        return null;
    }

    public static function findPropDeclaration(string $propName): ?ComposedViewNodeProp
    {
        foreach (static::getPropDeclarations() as $composedViewNodeProp) {
            if ($composedViewNodeProp->name === $propName) {
                return $composedViewNodeProp;
            }
        }
        return null;
    }

    /**
     * The node's text prop: {@see TEXT_CONTENT_PROP_NAME} when set, else `text` when the type declares it, else null.
     * Exported into the schema (`textContentProp`), where the generated kit reads it
     * ({@see ComposedViewKitSpec::describeNodeType()}).
     */
    public static function getTextContentPropName(): ?string
    {
        $textContentPropName = static::TEXT_CONTENT_PROP_NAME !== '' ? static::TEXT_CONTENT_PROP_NAME : 'text';
        return static::findPropDeclaration($textContentPropName) !== null ? $textContentPropName : null;
    }

    /**
     * The type's source rule for the schema (`sourcing`, read by the frontend reader), or null when the type takes no
     * part in it.
     *
     * @return array{needsSource: bool, namesSources: bool, rowsProps: string[], figureProps: string[]}|null
     */
    public static function buildSourcingSchemaArray(): ?array
    {
        if (!static::NEEDS_A_SOURCE_FOR_INLINE_FIGURES && !static::NAMES_THE_SOURCES && static::INLINE_ROWS_PROP_NAMES === [] && static::INLINE_FIGURE_PROP_NAMES === []) {
            return null;
        }
        return [
            'needsSource' => static::NEEDS_A_SOURCE_FOR_INLINE_FIGURES,
            'namesSources' => static::NAMES_THE_SOURCES,
            'rowsProps' => array_values(static::INLINE_ROWS_PROP_NAMES),
            'figureProps' => array_values(static::INLINE_FIGURE_PROP_NAMES),
        ];
    }

    /**
     * The type's part in the state rules for the schema (read by the frontend reader): `optionsProp`
     * ({@see OPTIONS_PROP_NAME}), `itemsProp` for a node that repeats its children, `submitsInputs`
     * ({@see SUBMITS_THE_STATE_OF_ITS_INPUTS}) — each only where it applies.
     *
     * @return array<string, string|bool>
     */
    public static function buildStateRulesSchemaArray(): array
    {
        $stateRules = [];
        if (static::OPTIONS_PROP_NAME !== '') {
            $stateRules['optionsProp'] = static::OPTIONS_PROP_NAME;
        }
        if (static::PROVIDES_ITEM_SCOPE) {
            $stateRules['itemsProp'] = static::ITEMS_PROP_NAME;
        }
        if (static::SUBMITS_THE_STATE_OF_ITS_INPUTS) {
            $stateRules['submitsInputs'] = true;
        }
        return $stateRules;
    }

    /**
     * Whether a node of this type may sit in `$composedViewNodeSlot`: one of its categories is accepted, or its type is
     * named; a `heavy` node never in a `compact` slot, unless the slot declares
     * {@see ComposedViewNodeSlot::$acceptsHeavyWhenCompact} (the cell of a 3–4 column Grid). A type whose acceptance
     * depends on its props (the embedded domain widget) overrides {@see isAcceptedBySlot()}.
     */
    public static function isTypeAcceptedBySlot(ComposedViewNodeSlot $composedViewNodeSlot): bool
    {
        if (in_array(ComposedViewNodeCategory::HEAVY, static::CATEGORIES, true) && $composedViewNodeSlot->sizeClass === ComposedViewNodeSlot::SIZE_CLASS_COMPACT
            && !$composedViewNodeSlot->acceptsHeavyWhenCompact
        ) {
            return false;
        }
        return in_array(static::NODE_TYPE, $composedViewNodeSlot->acceptsNodeTypes, true)
            || array_intersect(static::CATEGORIES, $composedViewNodeSlot->acceptsCategories) !== [];
    }

    /** Whether THIS node may sit in `$composedViewNodeSlot` — the type's rule by default. */
    public function isAcceptedBySlot(ComposedViewNodeSlot $composedViewNodeSlot): bool
    {
        return static::isTypeAcceptedBySlot($composedViewNodeSlot);
    }

    /** Whether THIS node may be the root of a view (no slot around it) — every node type by default. */
    public function isAcceptedAsRoot(): bool
    {
        return true;
    }

    /** The finding text for THIS node refused by `$composedViewNodeSlot`: the slot's size class and what it accepts. */
    public function buildSlotRefusalMessage(ComposedViewNodeSlot $composedViewNodeSlot): string
    {
        return sprintf(
            'a %s does not fit the slot "%s" (%s) — it accepts %s',
            static::NODE_TYPE,
            $composedViewNodeSlot->name,
            $composedViewNodeSlot->sizeClass,
            implode(', ', array_merge($composedViewNodeSlot->acceptsCategories, $composedViewNodeSlot->acceptsNodeTypes)),
        );
    }

    /** The finding text for THIS node refused as the root of the view. */
    public function buildRootRefusalMessage(): string
    {
        return sprintf('a %s cannot be the root of the view', static::NODE_TYPE);
    }

    /**
     * The node in its wire form: `type`, `id` when set, the props, `children` and `slots` when they hold nodes.
     *
     * @return array<string, mixed>
     */
    public function toWireArray(): array
    {
        $wireArray = ['type' => static::NODE_TYPE];
        if ($this->id !== null) {
            $wireArray['id'] = $this->id;
        }
        $wireArray += $this->props;
        $namedSlotWireArrays = [];
        foreach ($this->getNodesOfSlots() as $composedViewNodesOfSlot) {
            $slotName = $composedViewNodesOfSlot->slotName;
            $slotNodes = $composedViewNodesOfSlot->nodes;
            if ($slotNodes === []) {
                continue;
            }
            $slotWireArrays = array_map(static fn (ComposedViewNode $slotNode): array => $slotNode->toWireArray(), $slotNodes);
            if ($slotName === ComposedViewNodeSlot::DEFAULT_SLOT_NAME) {
                $wireArray['children'] = $slotWireArrays;
            } else {
                $namedSlotWireArrays[$slotName] = $slotWireArrays;
            }
        }
        if ($namedSlotWireArrays !== []) {
            $wireArray['slots'] = $namedSlotWireArrays;
        }
        return $wireArray;
    }

    /** Nodes in this subtree, this node included. */
    public function countNodes(): int
    {
        $nodeCount = 1;
        foreach ($this->getNodesOfSlots() as $composedViewNodesOfSlot) {
            foreach ($composedViewNodesOfSlot->nodes as $slotNode) {
                $nodeCount += $slotNode->countNodes();
            }
        }
        return $nodeCount;
    }

    /**
     * Every node of this subtree, depth first, this node first.
     *
     * @return ComposedViewNode[]
     */
    public function getNodesOfSubtree(): array
    {
        $subtreeNodes = [$this];
        foreach ($this->getNodesOfSlots() as $composedViewNodesOfSlot) {
            foreach ($composedViewNodesOfSlot->nodes as $slotNode) {
                array_push($subtreeNodes, ...$slotNode->getNodesOfSubtree());
            }
        }
        return $subtreeNodes;
    }

    /**
     * Every node of this subtree by its path in the kept tree (`view.children[1].slots.header[0]`), depth first, this
     * node first under `$path`.
     *
     * @return ComposedViewNode[] Keyed by path
     */
    public function getNodesOfSubtreeByPath(string $path): array
    {
        $subtreeNodesByPath = [$path => $this];
        foreach ($this->getNodesOfSlots() as $composedViewNodesOfSlot) {
            $slotName = $composedViewNodesOfSlot->slotName;
            $slotNodes = $composedViewNodesOfSlot->nodes;
            $slotPath = $slotName === ComposedViewNodeSlot::DEFAULT_SLOT_NAME ? $path . '.children' : $path . '.slots.' . $slotName;
            foreach (array_values($slotNodes) as $childPosition => $slotNode) {
                $subtreeNodesByPath += $slotNode->getNodesOfSubtreeByPath($slotPath . '[' . $childPosition . ']');
            }
        }
        return $subtreeNodesByPath;
    }

    /**
     * The state names this node's event props set (`{"set": <name>}`), in prop order.
     *
     * @return string[]
     */
    public function getStateNamesSetByEvents(): array
    {
        $stateNames = [];
        foreach (static::getPropDeclarations() as $composedViewNodeProp) {
            $eventValue = $this->props[$composedViewNodeProp->name] ?? null;
            if (in_array(ComposedViewNodeProp::TYPE_EVENT, $composedViewNodeProp->types, true) && is_array($eventValue) && is_string($eventValue['set'] ?? null)) {
                $stateNames[] = $eventValue['set'];
            }
        }
        return $stateNames;
    }

    /**
     * The bindings of this node's props, prop name → binding (a binding nested inside a prop value included, keyed
     * `prop.key…`).
     *
     * @return ComposedViewBinding[] Keyed by prop path
     */
    public function findBindings(): array
    {
        $bindingsByPropPath = [];
        foreach ($this->props as $propName => $propValue) {
            static::collectBindings((string)$propName, $propValue, $bindingsByPropPath);
        }
        return $bindingsByPropPath;
    }

    /**
     * @param ComposedViewBinding[] $bindingsByPropPath
     */
    protected static function collectBindings(string $propPath, mixed $propValue, array &$bindingsByPropPath): void
    {
        $composedViewBinding = ComposedViewBinding::fromWireValue($propValue);
        if ($composedViewBinding !== null) {
            $bindingsByPropPath[$propPath] = $composedViewBinding;
            return;
        }
        if (!is_array($propValue)) {
            return;
        }
        foreach ($propValue as $nestedKey => $nestedValue) {
            static::collectBindings($propPath . '.' . $nestedKey, $nestedValue, $bindingsByPropPath);
        }
    }

    /**
     * The slot and prop declarations of the class, read from its attributes once per process.
     *
     * @return ComposedViewNodeDeclarations
     */
    protected static function readDeclarations(): ComposedViewNodeDeclarations
    {
        if (isset(static::$declarationsByNodeClass[static::class])) {
            return static::$declarationsByNodeClass[static::class];
        }
        $reflectionClass = new ReflectionClass(static::class);
        $slotDeclarations = array_map(static fn ($attribute): ComposedViewNodeSlot => $attribute->newInstance(), $reflectionClass->getAttributes(ComposedViewNodeSlot::class));
        $propDeclarations = array_map(static fn ($attribute): ComposedViewNodeProp => $attribute->newInstance(), $reflectionClass->getAttributes(ComposedViewNodeProp::class));
        return static::$declarationsByNodeClass[static::class] = ComposedViewNodeDeclarations::forSlotsAndProps($slotDeclarations, $propDeclarations);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic(static::NODE_TYPE . ':' . ($this->id ?? (string)spl_object_id($this)));
    }
}
