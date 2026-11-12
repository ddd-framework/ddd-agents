<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindablePathListing;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindingResolution;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\DataSources\ComposedViewDataSourceClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Embedding\WidgetComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockSerializer;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * A `ComposedView` block's fields read into typed objects and checked: the `state` names
 * with their initial values, the named `data` sets (inline rows, or one `$from` / `$source` binding each), the
 * `computed` expressions over state, data and each other, the `actions`, and the `view` as a tree of
 * {@see ComposedViewNode}s, plus every finding. The reader is the validator: what is invalid is dropped where it
 * stands and reported ({@see ComposedViewValidationFindings}); the rest is kept — data validity, never a refusal. The rules:
 *
 * - a node needs a known type ({@see ComposedViewNodeClasses}), its required props, a slot that accepts it (the root:
 *   {@see ComposedViewNode::isAcceptedAsRoot()}), a depth and a node count within {@see ComposedViewBudgets};
 * - no string anywhere — title, state, data (bindings included), computed, actions, props — holds three backticks (it
 *   would end the fence in `ModelResponse::getVisibleProse()` and `WidgetBlocks`);
 * - the default slot is written once: a node with `children` AND `slots.children` keeps `children`;
 * - a prop must be declared by the node type and be a literal of its types or (when bindable) a well-formed binding to
 *   a declared `state` / `computed` name, an `$item` inside an item scope, a `$from` or a `$source`; an event prop
 *   (`onChange`) takes `{"set": <state name>}` or `{"action": <action name>}`;
 * - inline data is capped by items and cells; an inline `{"value": …}` without `source` / `derivedFrom` is flagged;
 * - a data node (Chart, Table, KpiTile) that shows figures written inline — not bound to a tool result or a data
 *   source — without its `source`, a source on a data node around it or a `SourceRef` in the tree is flagged
 *   `INLINE_VALUE_WITHOUT_SOURCE` (kept and rendered; the render report tells the model, {@see flagInlineFiguresWithoutSource()});
 * - `state` names are identifiers with scalar initial values, `computed` expressions are strings within the source
 *   length, `actions` are `copy`, `resetState`, `openEntity` (with a `ref`) or `openUrl` (with an http(s) `url`);
 * - an event `{"set": <name>}` on a name the block does not declare declares it with a null initial value
 *   ({@see declareStateNamesSetByEvents()}) — an input without `value` shows its own set target (frontend runtime);
 * - a `Widget` node its slot refuses — the widget does not embed, or needs a larger slot — is LIFTED, not dropped
 *   — it leaves the tree with a `WIDGET_LIFTED` note and is shown as its own block after the view
 *   ({@see $liftedWidgetNodes}, {@see buildLiftedWidgetBlocks()}); a layout node the lift left with no node is
 *   pruned with it, a root left with nothing is no view — never an empty Grid. Inside a node that repeats its children
 *   a refused widget is dropped (it would be lifted once per row);
 * - kept, with a note ({@see ComposedViewValidationFinding::NOTE_CODES}): a Tabs / Select / RadioGroup whose state
 *   starts as none of its choices, a state name a control sets and nothing else reads, and an `$item` field
 *   no row of the resolved `$from` rows has (the stored block carries the rows, so the finding lists their fields).
 *
 * Expressions are not parsed here: the frontend evaluator compiles them under the same budgets and reports
 * `INVALID_EXPRESSION` itself. Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewTreeReading extends ValueObject
{
    public const string ACTION_COPY = 'copy';
    public const string ACTION_RESET_STATE = 'resetState';
    public const string ACTION_OPEN_ENTITY = 'openEntity';
    public const string ACTION_OPEN_URL = 'openUrl';

    /** @var string[] the closed list of action types (no "issue a new chat turn") */
    public const array ACTION_TYPES = [self::ACTION_COPY, self::ACTION_RESET_STATE, self::ACTION_OPEN_ENTITY, self::ACTION_OPEN_URL];

    /** @var string a text that reads as a figure: it starts with a digit, or a sign and a digit ("4,32 %", "-3", "+2") */
    public const string FIGURE_TEXT_PATTERN = '/^[-+\x{2212}]?[0-9]/u';

    /** @var string the identifier rule for state, computed and action names */
    public const string NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    /** @var string[] names the pattern admits that reach an object's prototype in the browser — refused everywhere */
    public const array RESERVED_NAMES = ['__proto__', 'constructor', 'prototype'];

    /** @var string|null The tree's title. */
    public ?string $title = null;

    /** @var array<string, string|int|float|bool|null> State name → initial value. */
    public array $state = [];

    /** @var array<string, mixed> Dataset name → inline rows (a list) or one `$from` / `$source` binding. */
    public array $data = [];

    /** @var array<string, string> Computed name → expression source. */
    public array $computed = [];

    /** @var array<string, array<string, mixed>> Action name → action (`type` + its fields). */
    public array $actions = [];

    /** @var ComposedViewNode|null The root of the view; null when there is none or it was dropped. */
    public ?ComposedViewNode $view = null;

    /** @var ComposedViewValidationFindings|null What was dropped or flagged. */
    public ?ComposedViewValidationFindings $findings = null;

    /**
     * @var ComposedViewLiftedWidgetNodes|null The `Widget` nodes lifted out of the tree with their wire paths, in
     * reading order — each is shown as its own widget block after the view.
     */
    public ?ComposedViewLiftedWidgetNodes $liftedWidgetNodes = null;

    /** @var string[] The wire paths the kept tree no longer holds by the lift: the lifted nodes and the layout nodes pruned with them. */
    protected array $liftRemovedWirePaths = [];

    /** @var int Nodes read so far (the node budget). */
    protected int $readNodeCount = 0;

    /** @var int Inline scalar cells read so far (the cell budget). */
    protected int $inlineCellCount = 0;

    /**
     * The fields of a `ComposedView` block (the decoded JSON without `type`) read and checked.
     *
     * @param array<mixed> $widgetFields
     */
    public static function fromWidgetFields(array $widgetFields): static
    {
        $composedViewTreeReading = new static();
        $composedViewTreeReading->findings = new ComposedViewValidationFindings();
        $composedViewTreeReading->liftedWidgetNodes = new ComposedViewLiftedWidgetNodes();
        $composedViewTreeReading->readTitle($widgetFields['title'] ?? null);
        $composedViewTreeReading->readState($widgetFields['state'] ?? []);
        $composedViewTreeReading->readData($widgetFields['data'] ?? []);
        $composedViewTreeReading->readComputed($widgetFields['computed'] ?? []);
        $composedViewTreeReading->readActions($widgetFields['actions'] ?? []);
        $composedViewTreeReading->declareStateNamesSetByEvents($widgetFields['view'] ?? null);
        if (isset($widgetFields['view'])) {
            $composedViewTreeReading->view = $composedViewTreeReading->readRootNode($widgetFields['view']);
        }
        $composedViewTreeReading->flagInlineFiguresWithoutSource();
        $composedViewTreeReading->flagInitialStatesOutsideTheirChoices();
        $composedViewTreeReading->flagStateNamesNothingReads();
        $composedViewTreeReading->flagItemFieldsMissingFromTheirRows();
        return $composedViewTreeReading;
    }

    /**
     * The bytes the source budget counts: the block's fields without `type` as compact JSON (unescaped unicode,
     * slashes and line terminators) — the same canonical bytes the frontend counts (`JSON.stringify` of the parsed
     * payload), so whitespace and the key order of the written fence never decide. The
     * {@see WidgetBlock::LEGACY_WRITTEN_SYNTAX_FIELD} of an old stored fence does not count.
     *
     * @param array<mixed> $widgetFields
     */
    public static function measureSourceBytes(array $widgetFields): int
    {
        unset($widgetFields['type'], $widgetFields[WidgetBlock::LEGACY_WRITTEN_SYNTAX_FIELD]);
        $canonicalJson = json_encode($widgetFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        return $canonicalJson === false ? PHP_INT_MAX : strlen($canonicalJson);
    }

    /** A reading with no view and one finding — the block was over the source budget. */
    public static function forBlockOverTheSourceBudget(int $sourceBytes): static
    {
        $composedViewTreeReading = new static();
        $composedViewTreeReading->findings = new ComposedViewValidationFindings();
        $composedViewTreeReading->liftedWidgetNodes = new ComposedViewLiftedWidgetNodes();
        $composedViewTreeReading->findings->addFinding(ComposedViewValidationFinding::CODE_BUDGET_EXCEEDED, 'block',
            sprintf('the block has %d bytes, the budget is %d — no view is rendered', $sourceBytes, ComposedViewBudgets::MAX_SOURCE_BYTES));
        return $composedViewTreeReading;
    }

    /** An identifier ({@see NAME_PATTERN}) that is not one of {@see RESERVED_NAMES}. */
    public static function isAllowedName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1 && !in_array($name, self::RESERVED_NAMES, true);
    }

    protected function readTitle(mixed $titleField): void
    {
        if (!is_string($titleField)) {
            return;
        }
        if (str_contains($titleField, '```')) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_PROP, 'title', 'a string with three backticks would end the widget fence');
            return;
        }
        $this->title = $titleField;
    }

    /**
     * The root node: read like any node, then held to the root rule ({@see ComposedViewNode::isAcceptedAsRoot()}). A
     * refused `Widget` root is lifted (the block is then that widget alone); a layout root the lift emptied is no view.
     */
    protected function readRootNode(mixed $wireRootNode): ?ComposedViewNode
    {
        $liftedCountBefore = $this->liftedWidgetNodes->count();
        $rootNode = $this->readNode($wireRootNode, 'view', 1, false);
        if ($rootNode !== null && $this->isPrunedByTheLift($rootNode, $liftedCountBefore)) {
            $this->pruneNodeEmptiedByTheLift($rootNode, 'view');
            return null;
        }
        if ($rootNode === null || $rootNode->isAcceptedAsRoot()) {
            return $rootNode;
        }
        $this->readNodeCount -= $rootNode->countNodes();
        if ($rootNode instanceof WidgetComposedViewNode && $rootNode->isLiftedWhenRefused()) {
            $this->liftWidgetNode($rootNode, 'view', null);
            return null;
        }
        $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_NODE, 'view', $rootNode->buildRootRefusalMessage());
        return null;
    }

    /** Records a refused `Widget` node as lifted: out of the tree, with its note, shown as its own block after the view. */
    protected function liftWidgetNode(WidgetComposedViewNode $widgetComposedViewNode, string $path, ?ComposedViewNodeSlot $refusingSlot): void
    {
        $this->liftedWidgetNodes->addWidgetNodeAtPath($path, $widgetComposedViewNode);
        $this->liftRemovedWirePaths[] = $path;
        $this->findings->addFinding(ComposedViewValidationFinding::CODE_WIDGET_LIFTED, $path, $widgetComposedViewNode->buildLiftedNoteMessage($refusingSlot));
    }

    /** Whether `$composedViewNode` is a layout node the lift left with no node: a widget was lifted while it was read and every slot is empty. */
    protected function isPrunedByTheLift(ComposedViewNode $composedViewNode, int $liftedCountBefore): bool
    {
        if ($this->liftedWidgetNodes->count() === $liftedCountBefore || !in_array(ComposedViewNodeCategory::LAYOUT, $composedViewNode::CATEGORIES, true)) {
            return false;
        }
        foreach ($composedViewNode->getNodesOfSlots() as $composedViewNodesOfSlot) {
            if ($composedViewNodesOfSlot->nodes !== []) {
                return false;
            }
        }
        return true;
    }

    /** Takes a layout node the lift emptied out of the tree: its node count back, its slot-minimum notes gone, its path removed. */
    protected function pruneNodeEmptiedByTheLift(ComposedViewNode $composedViewNode, string $path): void
    {
        $this->readNodeCount -= $composedViewNode->countNodes();
        $this->findings->removeFindingsOfCodeInSubtree(ComposedViewValidationFinding::CODE_INVALID_SLOT_NODE_COUNT, $path);
        $this->liftRemovedWirePaths[] = $path;
    }

    protected function readState(mixed $stateField): void
    {
        if (!is_array($stateField) || ($stateField !== [] && array_is_list($stateField))) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_STATE, 'state', '`state` must be an object of name → initial value');
            return;
        }
        foreach ($stateField as $stateName => $initialValue) {
            $stateName = (string)$stateName;
            if (count($this->state) >= ComposedViewBudgets::MAX_STATE_KEYS) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_BUDGET_EXCEEDED, 'state.' . $stateName, sprintf('more than %d state names', ComposedViewBudgets::MAX_STATE_KEYS));
                break;
            }
            if (!self::isAllowedName($stateName)) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_STATE, 'state.' . $stateName, 'a state name is an identifier (letters, digits, underscore)');
                continue;
            }
            if (!is_scalar($initialValue) && $initialValue !== null) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_STATE, 'state.' . $stateName, 'an initial value is a string, number, boolean or null');
                continue;
            }
            if (is_string($initialValue) && str_contains($initialValue, '```')) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_STATE, 'state.' . $stateName, 'a string with three backticks would end the widget fence');
                continue;
            }
            if (is_string($initialValue) && mb_strlen($initialValue) > ComposedViewBudgets::MAX_STATE_STRING_LENGTH) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_BUDGET_EXCEEDED, 'state.' . $stateName, sprintf('an initial string longer than %d characters', ComposedViewBudgets::MAX_STATE_STRING_LENGTH));
                continue;
            }
            $this->state[$stateName] = $initialValue;
        }
    }

    protected function readData(mixed $dataField): void
    {
        if (!is_array($dataField) || ($dataField !== [] && array_is_list($dataField))) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_PROP, 'data', '`data` must be an object of name → rows');
            return;
        }
        foreach ($dataField as $dataName => $dataset) {
            $dataName = (string)$dataName;
            $path = 'data.' . $dataName;
            if (!self::isAllowedName($dataName) || array_key_exists($dataName, $this->state)) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_PROP, $path, 'a data name is an identifier that is not also a state name');
                continue;
            }
            $composedViewBinding = ComposedViewBinding::fromWireValue($dataset);
            if ($composedViewBinding !== null) {
                if (!in_array($composedViewBinding->kind, [ComposedViewBinding::KIND_FROM, ComposedViewBinding::KIND_SOURCE], true)) {
                    $this->findings->addFinding(ComposedViewValidationFinding::CODE_UNKNOWN_BINDING, $path, 'a dataset binds a tool result ($from) or a data source ($source)');
                    continue;
                }
                if ($this->containsTripleBackticks($dataset)) {
                    $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_PROP, $path, 'a string with three backticks would end the widget fence');
                    continue;
                }
                if ($this->isValidBinding($composedViewBinding, $path, false)) {
                    $this->data[$dataName] = $dataset;
                }
                continue;
            }
            if (!is_array($dataset) || !array_is_list($dataset)) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_PROP, $path, 'a dataset is a list of rows, a $from or a $source');
                continue;
            }
            if ($this->containsTripleBackticks($dataset)) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_PROP, $path, 'a string with three backticks would end the widget fence');
                continue;
            }
            if ($this->isValidInlineData($dataset, $path, false)) {
                $this->data[$dataName] = $dataset;
            }
        }
    }

    protected function readComputed(mixed $computedField): void
    {
        if (!is_array($computedField) || ($computedField !== [] && array_is_list($computedField))) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_EXPRESSION, 'computed', '`computed` must be an object of name → expression');
            return;
        }
        foreach ($computedField as $computedName => $expressionSource) {
            $computedName = (string)$computedName;
            $path = 'computed.' . $computedName;
            if (count($this->computed) >= ComposedViewBudgets::MAX_COMPUTED_EXPRESSIONS) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_BUDGET_EXCEEDED, $path, sprintf('more than %d computed values', ComposedViewBudgets::MAX_COMPUTED_EXPRESSIONS));
                break;
            }
            if (!self::isAllowedName($computedName) || array_key_exists($computedName, $this->state) || array_key_exists($computedName, $this->data)) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_EXPRESSION, $path, 'a computed name is an identifier that is not also a state or data name');
                continue;
            }
            if (!is_string($expressionSource) || trim($expressionSource) === '') {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_EXPRESSION, $path, 'an expression is a non-empty string');
                continue;
            }
            if (mb_strlen($expressionSource) > ComposedViewBudgets::MAX_EXPRESSION_SOURCE_LENGTH) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_BUDGET_EXCEEDED, $path, sprintf('an expression longer than %d characters', ComposedViewBudgets::MAX_EXPRESSION_SOURCE_LENGTH));
                continue;
            }
            if (str_contains($expressionSource, '```')) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_EXPRESSION, $path, 'a string with three backticks would end the widget fence');
                continue;
            }
            $this->computed[$computedName] = $expressionSource;
        }
    }

    protected function readActions(mixed $actionsField): void
    {
        if (!is_array($actionsField) || ($actionsField !== [] && array_is_list($actionsField))) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_ACTION, 'actions', '`actions` must be an object of name → action');
            return;
        }
        foreach ($actionsField as $actionName => $action) {
            $actionName = (string)$actionName;
            $path = 'actions.' . $actionName;
            if (count($this->actions) >= ComposedViewBudgets::MAX_ACTIONS) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_BUDGET_EXCEEDED, $path, sprintf('more than %d actions', ComposedViewBudgets::MAX_ACTIONS));
                break;
            }
            $actionType = is_array($action) ? ($action['type'] ?? null) : null;
            if (!self::isAllowedName($actionName) || !in_array($actionType, self::ACTION_TYPES, true)) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_ACTION, $path, 'an action has an identifier name and a type of ' . implode(', ', self::ACTION_TYPES));
                continue;
            }
            if ($actionType === self::ACTION_OPEN_URL && !(is_string($action['url'] ?? null) && preg_match('#^https?://#i', $action['url']) === 1)) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_ACTION, $path, '`openUrl` needs an http(s) `url`');
                continue;
            }
            // `{"$item":"entityRef"}` — inside a Repeat over a resolved `$from` list, each row opens its own entity
            $refBinding = $actionType === self::ACTION_OPEN_ENTITY ? ComposedViewBinding::fromWireValue($action['ref'] ?? null) : null;
            $refIsRowBound = $refBinding !== null && $refBinding->kind === ComposedViewBinding::KIND_ITEM && $refBinding->isWellFormed;
            if ($actionType === self::ACTION_OPEN_ENTITY && !$refIsRowBound && !(is_string($action['ref'] ?? null) && $action['ref'] !== '')) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_ACTION, $path, '`openEntity` needs the platform-minted `ref` of a tool result row, or {"$item":"entityRef"} inside a Repeat');
                continue;
            }
            if ($this->containsTripleBackticks($action)) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_ACTION, $path, 'a string with three backticks would end the widget fence');
                continue;
            }
            $this->actions[$actionName] = $action;
        }
    }

    /** One node and its slots, or null when it is dropped (with its finding). */
    protected function readNode(mixed $wireNode, string $path, int $depth, bool $isInItemScope): ?ComposedViewNode
    {
        if (!is_array($wireNode) || !is_string($wireNode['type'] ?? null)) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_NODE, $path, 'a node is an object with a string `type`');
            return null;
        }
        $nodeType = $wireNode['type'];
        $nodeClass = ComposedViewNodeClasses::getNodeClassForNodeType($nodeType);
        if ($nodeClass === null) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_NODE, $path, sprintf('unknown node type "%s"', $nodeType));
            return null;
        }
        if ($depth > ComposedViewBudgets::MAX_DEPTH) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_BUDGET_EXCEEDED, $path, sprintf('deeper than %d levels', ComposedViewBudgets::MAX_DEPTH));
            return null;
        }
        if ($this->readNodeCount >= ComposedViewBudgets::MAX_NODES) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_BUDGET_EXCEEDED, $path, sprintf('more than %d nodes', ComposedViewBudgets::MAX_NODES));
            return null;
        }
        if ($this->containsTripleBackticks(array_diff_key($wireNode, ['children' => true, 'slots' => true]))) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_NODE, $path, 'a string with three backticks would end the widget fence');
            return null;
        }
        $this->readNodeCount++;
        /** @var ComposedViewNode $composedViewNode */
        $composedViewNode = new $nodeClass();
        $composedViewNode->id = is_string($wireNode['id'] ?? null) ? $wireNode['id'] : null;
        if (!$this->readProps($composedViewNode, $wireNode, $path, $isInItemScope)) {
            $this->readNodeCount--;
            return null;
        }
        $childScope = $isInItemScope || $nodeClass::PROVIDES_ITEM_SCOPE;
        $wireNodesBySlotName = [];
        if (array_key_exists('children', $wireNode)) {
            $wireNodesBySlotName[ComposedViewNodeSlot::DEFAULT_SLOT_NAME] = $wireNode['children'];
        }
        if (is_array($wireNode['slots'] ?? null)) {
            foreach ($wireNode['slots'] as $slotName => $slotWireNodes) {
                $slotName = (string)$slotName;
                if ($slotName === ComposedViewNodeSlot::DEFAULT_SLOT_NAME && array_key_exists('children', $wireNode)) {
                    $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_NODE, $path . '.slots.' . $slotName, 'the default slot is written twice (`children` and `slots.children`) — `slots.children` is dropped');
                    continue;
                }
                $wireNodesBySlotName[$slotName] = $slotWireNodes;
            }
        }
        foreach ($wireNodesBySlotName as $slotName => $slotWireNodes) {
            $slotPath = $slotName === ComposedViewNodeSlot::DEFAULT_SLOT_NAME ? $path . '.children' : $path . '.slots.' . $slotName;
            // the slot of THIS node: a Grid's cells narrow with its `columns` — the props are read above
            $composedViewNodeSlot = $nodeClass::findSlotDeclaration($slotName)?->buildForNodeProps($composedViewNode->props);
            if ($composedViewNodeSlot === null) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_NODE, $slotPath, sprintf('%s has no slot "%s" — its nodes are dropped', $nodeType, $slotName));
                continue;
            }
            $composedViewNode->setNodesOfSlot((string)$slotName, $this->readSlotNodes($composedViewNodeSlot, $slotWireNodes, $slotPath, $depth, $childScope));
        }
        foreach ($nodeClass::getSlotDeclarations() as $composedViewNodeSlot) {
            $slotNodeCount = count($composedViewNode->getNodesOfSlot($composedViewNodeSlot->name));
            if ($slotNodeCount < $composedViewNodeSlot->minChildren) {
                // the node is kept and renders what it has: a note, never a dropped node
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_SLOT_NODE_COUNT, $path . '.' . $composedViewNodeSlot->name,
                    sprintf('%s needs at least %d node(s) in "%s", it has %d', $nodeType, $composedViewNodeSlot->minChildren, $composedViewNodeSlot->name, $slotNodeCount));
            }
        }
        return $composedViewNode;
    }

    /**
     * The nodes of one slot: each read, the ones the slot does not accept or over its maximum dropped.
     *
     * @return ComposedViewNode[]
     */
    protected function readSlotNodes(ComposedViewNodeSlot $composedViewNodeSlot, mixed $slotWireNodes, string $slotPath, int $depth, bool $isInItemScope): array
    {
        if (!is_array($slotWireNodes) || !array_is_list($slotWireNodes)) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_NODE, $slotPath, 'a slot holds a list of nodes');
            return [];
        }
        $slotNodes = [];
        foreach ($slotWireNodes as $childPosition => $slotWireNode) {
            $childPath = $slotPath . '[' . $childPosition . ']';
            if ($composedViewNodeSlot->maxChildren !== null && count($slotNodes) >= $composedViewNodeSlot->maxChildren) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_NODE, $childPath, sprintf('the slot "%s" holds at most %d node(s)', $composedViewNodeSlot->name, $composedViewNodeSlot->maxChildren));
                break;
            }
            $liftedCountBefore = $this->liftedWidgetNodes->count();
            $slotNode = $this->readNode($slotWireNode, $childPath, $depth + 1, $isInItemScope);
            if ($slotNode === null) {
                continue;
            }
            if ($this->isPrunedByTheLift($slotNode, $liftedCountBefore)) {
                $this->pruneNodeEmptiedByTheLift($slotNode, $childPath);
                continue;
            }
            if (!$slotNode->isAcceptedBySlot($composedViewNodeSlot)) {
                $this->readNodeCount -= $slotNode->countNodes();
                if ($slotNode instanceof WidgetComposedViewNode && $slotNode->isLiftedWhenRefused() && !$isInItemScope) {
                    $this->liftWidgetNode($slotNode, $childPath, $composedViewNodeSlot);
                    continue;
                }
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_NODE, $childPath, $slotNode->buildSlotRefusalMessage($composedViewNodeSlot));
                continue;
            }
            $slotNodes[] = $slotNode;
        }
        return $slotNodes;
    }

    /**
     * The node's props: declared ones kept when their literal or binding is valid, undeclared or invalid ones dropped
     * with a finding. False when a required prop is missing (the node is then dropped).
     *
     * @param array<mixed> $wireNode
     */
    protected function readProps(ComposedViewNode $composedViewNode, array $wireNode, string $path, bool $isInItemScope): bool
    {
        $nodeClass = $composedViewNode::class;
        foreach ($wireNode as $propName => $propValue) {
            $propName = (string)$propName;
            if (in_array($propName, ComposedViewNode::RESERVED_WIRE_KEYS, true)) {
                continue;
            }
            $propPath = $path . '.' . $propName;
            $composedViewNodeProp = $nodeClass::findPropDeclaration($propName);
            if ($composedViewNodeProp === null) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_PROP, $propPath, sprintf('%s has no prop "%s" — dropped', $composedViewNode::NODE_TYPE, $propName));
                continue;
            }
            if ($this->isValidPropValue($composedViewNodeProp, $propValue, $propPath, $isInItemScope)) {
                $composedViewNode->props[$propName] = $propValue;
            }
        }
        foreach ($nodeClass::getPropDeclarations() as $composedViewNodeProp) {
            if ($composedViewNodeProp->isRequired && !array_key_exists($composedViewNodeProp->name, $composedViewNode->props)) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_NODE, $path, sprintf('%s needs the prop "%s" — the node is dropped', $composedViewNode::NODE_TYPE, $composedViewNodeProp->name));
                return false;
            }
        }
        return true;
    }

    /** Whether one prop value is kept; reports why when it is not. */
    protected function isValidPropValue(ComposedViewNodeProp $composedViewNodeProp, mixed $propValue, string $propPath, bool $isInItemScope): bool
    {
        if (in_array(ComposedViewNodeProp::TYPE_EVENT, $composedViewNodeProp->types, true)) {
            return $this->isValidEvent($propValue, $propPath);
        }
        $composedViewBinding = ComposedViewBinding::fromWireValue($propValue);
        if ($composedViewBinding !== null) {
            if (!$composedViewNodeProp->isBindable) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_PROP, $propPath, 'this prop takes a literal, not a binding');
                return false;
            }
            return $this->isValidBinding($composedViewBinding, $propPath, $isInItemScope);
        }
        if (!$composedViewNodeProp->acceptsLiteral($propValue)) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_PROP, $propPath, sprintf('a %s is expected%s', implode(' or ', $composedViewNodeProp->types),
                $composedViewNodeProp->allowedValues !== null ? ' (one of ' . implode(', ', array_map('strval', $composedViewNodeProp->allowedValues)) . ')' : ''));
            return false;
        }
        if (is_string($propValue) && mb_strlen($propValue) > ComposedViewBudgets::MAX_TEXT_LENGTH) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_BUDGET_EXCEEDED, $propPath, sprintf('a text longer than %d characters', ComposedViewBudgets::MAX_TEXT_LENGTH));
            return false;
        }
        return !is_array($propValue) || $this->isValidInlineData($propValue, $propPath, $isInItemScope);
    }

    protected function isValidEvent(mixed $propValue, string $propPath): bool
    {
        $isSetEvent = is_array($propValue) && count($propValue) === 1 && is_string($propValue['set'] ?? null);
        $isActionEvent = is_array($propValue) && count($propValue) === 1 && is_string($propValue['action'] ?? null);
        if ($isSetEvent && array_key_exists($propValue['set'], $this->state)) {
            return true;
        }
        if ($isActionEvent && array_key_exists($propValue['action'], $this->actions)) {
            return true;
        }
        $this->findings->addFinding(ComposedViewValidationFinding::CODE_UNKNOWN_BINDING, $propPath, 'an event is {"set": <a state name>} or {"action": <an action name>} of this tree');
        return false;
    }

    protected function isValidBinding(ComposedViewBinding $composedViewBinding, string $propPath, bool $isInItemScope): bool
    {
        $bindingProblem = match (true) {
            !$composedViewBinding->isWellFormed => 'a malformed binding (one of $state, $computed, $data, $item, $from {toolCall, path}, $source {name, params})',
            $composedViewBinding->kind === ComposedViewBinding::KIND_STATE && !array_key_exists($composedViewBinding->target, $this->state) => sprintf('no state "%s"', $composedViewBinding->target),
            $composedViewBinding->kind === ComposedViewBinding::KIND_COMPUTED && !array_key_exists($composedViewBinding->target, $this->computed) => sprintf('no computed value "%s"', $composedViewBinding->target),
            $composedViewBinding->kind === ComposedViewBinding::KIND_DATA && !array_key_exists($composedViewBinding->target, $this->data) => sprintf('no dataset "%s"', $composedViewBinding->target),
            $composedViewBinding->kind === ComposedViewBinding::KIND_ITEM && !$isInItemScope => '$item outside a node that repeats its children',
            $composedViewBinding->kind === ComposedViewBinding::KIND_SOURCE => $this->findSourceBindingProblem($composedViewBinding),
            default => null,
        };
        if ($bindingProblem === null) {
            return true;
        }
        $this->findings->addFinding(ComposedViewValidationFinding::CODE_UNKNOWN_BINDING, $propPath, $bindingProblem . ' — the prop is dropped');
        return false;
    }

    /**
     * Why a `$source` binding is not valid, or null: the source must be registered ({@see ComposedViewDataSourceClasses}),
     * every parameter declared, every required parameter given, and a parameter bound only to a declared state name.
     */
    protected function findSourceBindingProblem(ComposedViewBinding $composedViewBinding): ?string
    {
        $dataSourceClass = ComposedViewDataSourceClasses::getDataSourceClassForName($composedViewBinding->target);
        if ($dataSourceClass === null) {
            return sprintf('no data source "%s"', $composedViewBinding->target);
        }
        $declaredParams = [];
        foreach (ComposedViewDataSourceClasses::getParams($dataSourceClass) as $composedViewDataSourceParam) {
            $declaredParams[$composedViewDataSourceParam->name] = $composedViewDataSourceParam;
        }
        foreach ($composedViewBinding->sourceParams as $paramName => $paramValue) {
            if (!isset($declaredParams[(string)$paramName])) {
                return sprintf('the data source "%s" has no param "%s"', $composedViewBinding->target, $paramName);
            }
            $paramBinding = ComposedViewBinding::fromWireValue($paramValue);
            if ($paramBinding !== null && ($paramBinding->kind !== ComposedViewBinding::KIND_STATE || !array_key_exists($paramBinding->target, $this->state))) {
                return sprintf('the param "%s" binds a declared state name only', $paramName);
            }
        }
        foreach ($declaredParams as $paramName => $composedViewDataSourceParam) {
            if ($composedViewDataSourceParam->isRequired && !array_key_exists($paramName, $composedViewBinding->sourceParams)) {
                return sprintf('the data source "%s" needs the param "%s"', $composedViewBinding->target, $paramName);
            }
        }
        return null;
    }

    /**
     * Inline data (an array literal): within the item and cell budgets, its nested bindings valid, and every
     * `{"value": …}` with a `source` or `derivedFrom` (flagged, kept).
     *
     * @param array<mixed> $inlineData
     */
    protected function isValidInlineData(array $inlineData, string $propPath, bool $isInItemScope): bool
    {
        if (array_is_list($inlineData) && count($inlineData) > ComposedViewBudgets::MAX_INLINE_ARRAY_ITEMS) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_BUDGET_EXCEEDED, $propPath, sprintf('more than %d inline items', ComposedViewBudgets::MAX_INLINE_ARRAY_ITEMS));
            return false;
        }
        if (array_key_exists('value', $inlineData) && !array_key_exists('source', $inlineData) && !array_key_exists('derivedFrom', $inlineData)) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INLINE_VALUE_WITHOUT_SOURCE, $propPath, 'a value without `source` or `derivedFrom` — rendered without a source line');
        }
        foreach ($inlineData as $inlineKey => $inlineValue) {
            $nestedPath = $propPath . (array_is_list($inlineData) ? '[' . $inlineKey . ']' : '.' . $inlineKey);
            $composedViewBinding = ComposedViewBinding::fromWireValue($inlineValue);
            if ($composedViewBinding !== null) {
                if (!$this->isValidBinding($composedViewBinding, $nestedPath, $isInItemScope)) {
                    return false;
                }
                continue;
            }
            if (is_array($inlineValue)) {
                if (!$this->isValidInlineData($inlineValue, $nestedPath, $isInItemScope)) {
                    return false;
                }
                continue;
            }
            if (++$this->inlineCellCount > ComposedViewBudgets::MAX_INLINE_CELLS) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_BUDGET_EXCEEDED, $nestedPath, sprintf('more than %d inline cells in the block', ComposedViewBudgets::MAX_INLINE_CELLS));
                return false;
            }
        }
        return true;
    }

    /**
     * Flags each data node of the kept view that shows inline figures without a source ("every value has a
     * source": a chart shows its sources, above all on political figures). Read on the KEPT tree,
     * after every drop, by the declarations of the node classes ({@see ComposedViewNode::NEEDS_A_SOURCE_FOR_INLINE_FIGURES});
     * a `SourceRef` anywhere in the tree sources every node. One finding per unsourced data node — the nodes inside it
     * are covered by it. The node stays: data validity, not a gate.
     */
    protected function flagInlineFiguresWithoutSource(): void
    {
        if ($this->view === null) {
            return;
        }
        foreach ($this->view->getNodesOfSubtree() as $subtreeNode) {
            if ($subtreeNode::NAMES_THE_SOURCES) {
                return;
            }
        }
        $this->flagUnsourcedDataNodesOfSubtree($this->view, 'view', false, false);
    }

    /** The flagging walk: `$isSourcedAround` = a data node around this one names its source or was flagged already. */
    protected function flagUnsourcedDataNodesOfSubtree(ComposedViewNode $composedViewNode, string $path, bool $isSourcedAround, bool $itemsAreInline): void
    {
        $isSourced = $isSourcedAround || ($composedViewNode::NEEDS_A_SOURCE_FOR_INLINE_FIGURES && array_key_exists(ComposedViewNode::SOURCE_PROP_NAME, $composedViewNode->props));
        if ($composedViewNode::NEEDS_A_SOURCE_FOR_INLINE_FIGURES && !$isSourced && $this->subtreeHoldsInlineFigures($composedViewNode, $itemsAreInline)) {
            $this->findings->addFinding(ComposedViewValidationFinding::CODE_INLINE_VALUE_WITHOUT_SOURCE, $path, sprintf(
                'a %s shows figures written inline without `source` — give it the publisher and page as `source` {"name","url"} (or a SourceRef beside it)',
                $composedViewNode::NODE_TYPE,
            ));
            $isSourced = true;
        }
        $childItemsAreInline = $composedViewNode::PROVIDES_ITEM_SCOPE ? $this->nodeHoldsInlineRows($composedViewNode, $itemsAreInline) : $itemsAreInline;
        foreach ($composedViewNode->getNodesOfSlots() as $composedViewNodesOfSlot) {
            $slotName = $composedViewNodesOfSlot->slotName;
            $slotNodes = $composedViewNodesOfSlot->nodes;
            $slotPath = $slotName === ComposedViewNodeSlot::DEFAULT_SLOT_NAME ? $path . '.children' : $path . '.slots.' . $slotName;
            foreach (array_values($slotNodes) as $childPosition => $slotNode) {
                $this->flagUnsourcedDataNodesOfSubtree($slotNode, $slotPath . '[' . $childPosition . ']', $isSourced, $childItemsAreInline);
            }
        }
    }

    /** Whether the node or a node below it holds an inline figure (rows or one figure). */
    protected function subtreeHoldsInlineFigures(ComposedViewNode $composedViewNode, bool $itemsAreInline): bool
    {
        if ($this->nodeHoldsInlineRows($composedViewNode, $itemsAreInline) || $this->nodeHoldsInlineFigure($composedViewNode, $itemsAreInline)) {
            return true;
        }
        $childItemsAreInline = $composedViewNode::PROVIDES_ITEM_SCOPE ? $this->nodeHoldsInlineRows($composedViewNode, $itemsAreInline) : $itemsAreInline;
        foreach ($composedViewNode->getNodesOfSlots() as $composedViewNodesOfSlot) {
            foreach ($composedViewNodesOfSlot->nodes as $slotNode) {
                if ($this->subtreeHoldsInlineFigures($slotNode, $childItemsAreInline)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Whether one of the node's rows props ({@see ComposedViewNode::INLINE_ROWS_PROP_NAMES}) holds written rows. */
    protected function nodeHoldsInlineRows(ComposedViewNode $composedViewNode, bool $itemsAreInline): bool
    {
        foreach ($composedViewNode::INLINE_ROWS_PROP_NAMES as $rowsPropName) {
            if (!array_key_exists($rowsPropName, $composedViewNode->props)) {
                continue;
            }
            $rowsValue = $composedViewNode->props[$rowsPropName];
            $composedViewBinding = ComposedViewBinding::fromWireValue($rowsValue);
            $holdsInlineRows = match (true) {
                $composedViewBinding === null => is_array($rowsValue) && $rowsValue !== [],
                $composedViewBinding->kind === ComposedViewBinding::KIND_DATA => $this->isInlineDataset($composedViewBinding->target),
                $composedViewBinding->kind === ComposedViewBinding::KIND_ITEM => $itemsAreInline,
                default => false,
            };
            if ($holdsInlineRows) {
                return true;
            }
        }
        return false;
    }

    /** Whether one of the node's figure props ({@see ComposedViewNode::INLINE_FIGURE_PROP_NAMES}) holds a written figure. */
    protected function nodeHoldsInlineFigure(ComposedViewNode $composedViewNode, bool $itemsAreInline): bool
    {
        foreach ($composedViewNode::INLINE_FIGURE_PROP_NAMES as $figurePropName) {
            if (!array_key_exists($figurePropName, $composedViewNode->props)) {
                continue;
            }
            $figureValue = $composedViewNode->props[$figurePropName];
            $composedViewBinding = ComposedViewBinding::fromWireValue($figureValue);
            $holdsInlineFigure = match (true) {
                $composedViewBinding !== null => $composedViewBinding->kind === ComposedViewBinding::KIND_ITEM && $itemsAreInline,
                is_int($figureValue), is_float($figureValue) => true,
                is_string($figureValue) => preg_match(self::FIGURE_TEXT_PATTERN, $figureValue) === 1,
                default => false,
            };
            if ($holdsInlineFigure) {
                return true;
            }
        }
        return false;
    }

    /** Whether the dataset `$dataName` is written rows (not a `$from` / `$source` binding) with at least one row. */
    protected function isInlineDataset(string $dataName): bool
    {
        $dataset = $this->data[$dataName] ?? null;
        return is_array($dataset) && $dataset !== [] && ComposedViewBinding::fromWireValue($dataset) === null;
    }

    /**
     * The lenient state rule: every `{"set": <name>}` event of a node of a known type declares
     * `<name>` with a null initial value when the block does not declare it — unless the name is no identifier, names a
     * dataset or a computed value, or the state is at its budget (the event then stays an unknown binding). Walks the
     * written view before it is read, so the event and a `{"$state": <name>}` beside it are valid; a `slots.children`
     * beside `children` is skipped, as the reader drops it.
     */
    protected function declareStateNamesSetByEvents(mixed $wireNode): void
    {
        if (!is_array($wireNode) || !is_string($wireNode['type'] ?? null)) {
            return;
        }
        $nodeClass = ComposedViewNodeClasses::getNodeClassForNodeType($wireNode['type']);
        if ($nodeClass === null) {
            return;
        }
        foreach ($nodeClass::getPropDeclarations() as $composedViewNodeProp) {
            $eventValue = $wireNode[$composedViewNodeProp->name] ?? null;
            if (in_array(ComposedViewNodeProp::TYPE_EVENT, $composedViewNodeProp->types, true) && is_array($eventValue) && count($eventValue) === 1 && is_string($eventValue['set'] ?? null)) {
                $this->declareStateNameSetByEvent($eventValue['set']);
            }
        }
        $slotWireNodeLists = [$wireNode['children'] ?? null];
        foreach (is_array($wireNode['slots'] ?? null) ? $wireNode['slots'] : [] as $slotName => $slotWireNodes) {
            if ((string)$slotName !== ComposedViewNodeSlot::DEFAULT_SLOT_NAME || !array_key_exists('children', $wireNode)) {
                $slotWireNodeLists[] = $slotWireNodes;
            }
        }
        foreach ($slotWireNodeLists as $slotWireNodes) {
            foreach (is_array($slotWireNodes) && array_is_list($slotWireNodes) ? $slotWireNodes : [] as $slotWireNode) {
                $this->declareStateNamesSetByEvents($slotWireNode);
            }
        }
    }

    protected function declareStateNameSetByEvent(string $stateName): void
    {
        if (!self::isAllowedName($stateName) || array_key_exists($stateName, $this->state) || array_key_exists($stateName, $this->data)
            || array_key_exists($stateName, $this->computed) || count($this->state) >= ComposedViewBudgets::MAX_STATE_KEYS
        ) {
            return;
        }
        $this->state[$stateName] = null;
    }

    /**
     * Flags each kept node with choices ({@see ComposedViewNode::OPTIONS_PROP_NAME}) whose event sets a state that starts
     * as none of them — the control then shows nothing picked, and a `computed` that compares the state with a choice
     * id never matches. A null or empty initial value (nothing picked yet) is no finding; the node stays.
     */
    protected function flagInitialStatesOutsideTheirChoices(): void
    {
        foreach ($this->view?->getNodesOfSubtreeByPath('view') ?? [] as $nodePath => $composedViewNode) {
            $choices = $composedViewNode::OPTIONS_PROP_NAME !== '' ? ($composedViewNode->props[$composedViewNode::OPTIONS_PROP_NAME] ?? null) : null;
            if (!is_array($choices) || !array_is_list($choices)) {
                continue;
            }
            $choiceValues = self::readChoiceValues($choices);
            foreach (array_unique($composedViewNode->getStateNamesSetByEvents()) as $stateName) {
                $initialValue = $this->state[$stateName] ?? null;
                if ($initialValue === null || $initialValue === '' || ((is_string($initialValue) || is_int($initialValue) || is_float($initialValue)) && in_array((string)$initialValue, $choiceValues, true))) {
                    continue;
                }
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_INVALID_INITIAL_CHOICE, $nodePath, sprintf(
                    'the state "%s" starts as %s, none of the choices (%s) — start it with a choice\'s id',
                    $stateName,
                    (string)json_encode($initialValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    implode(', ', $choiceValues),
                ));
            }
        }
    }

    /**
     * The values a choice may be picked by, as text: a label written as the choice itself, or an object's `id`,
     * `value`, `key`, `label`, `title`, `name` and `text` (what the frontend inputs read a choice's id and label from).
     *
     * @param array<int, mixed> $choices
     * @return string[]
     */
    public static function readChoiceValues(array $choices): array
    {
        $choiceValues = [];
        foreach ($choices as $choice) {
            $choiceFields = is_array($choice) ? array_intersect_key($choice, array_flip(['id', 'value', 'key', 'label', 'title', 'name', 'text'])) : [$choice];
            foreach ($choiceFields as $choiceField) {
                if (is_string($choiceField) || is_int($choiceField) || is_float($choiceField)) {
                    $choiceValues[(string)$choiceField] = (string)$choiceField;
                }
            }
        }
        return array_values($choiceValues);
    }

    /**
     * Flags each state name a kept node's event sets that nothing reads: no `computed` expression names it, no `$state`
     * binding of the data, the actions or a node reads it — a node's binding of a state its own event sets is the
     * control showing its value, no read — and no form ({@see ComposedViewNode::SUBMITS_THE_STATE_OF_ITS_INPUTS}) sends
     * it. A Tab or input over such a state changes nothing on screen; the state stays. A state nothing sets is no
     * finding.
     */
    protected function flagStateNamesNothingReads(): void
    {
        if ($this->state === []) {
            return;
        }
        $readStateNames = [];
        $setStateNames = [];
        foreach ($this->computed as $expressionSource) {
            foreach (self::findIdentifiersOfExpression($expressionSource) as $identifier) {
                $readStateNames[$identifier] = true;
            }
        }
        self::collectStateBindingTargets($this->data, $readStateNames);
        self::collectStateBindingTargets($this->actions, $readStateNames);
        foreach ($this->view?->getNodesOfSubtree() ?? [] as $composedViewNode) {
            foreach ($composedViewNode->getStateNamesSetByEvents() as $setStateName) {
                $setStateNames[$setStateName] = true;
            }
            $nodeReadStateNames = [];
            self::collectStateBindingTargets($composedViewNode->props, $nodeReadStateNames);
            foreach (array_diff(array_keys($nodeReadStateNames), $composedViewNode->getStateNamesSetByEvents()) as $readStateName) {
                $readStateNames[$readStateName] = true;
            }
            if (!$composedViewNode::SUBMITS_THE_STATE_OF_ITS_INPUTS) {
                continue;
            }
            foreach ($composedViewNode->getNodesOfSubtree() as $formSubtreeNode) {
                self::collectStateBindingTargets($formSubtreeNode->props, $readStateNames);
                foreach ($formSubtreeNode->getStateNamesSetByEvents() as $submittedStateName) {
                    $readStateNames[$submittedStateName] = true;
                }
            }
        }
        foreach (array_keys($this->state) as $stateName) {
            if (isset($setStateNames[$stateName]) && !isset($readStateNames[$stateName])) {
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_UNREAD_STATE, 'state.' . $stateName, sprintf(
                    'nothing reads the state "%s" — a Tab or input changes only what reads its state: bind a node to {"$state":"%s"} or name it in a computed value',
                    $stateName,
                    $stateName,
                ));
            }
        }
    }

    /**
     * The names a `computed` expression reads: its identifiers outside string literals, not after a `.` (a field).
     *
     * @return string[]
     */
    public static function findIdentifiersOfExpression(string $expressionSource): array
    {
        $sourceWithoutStrings = (string)preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', ' ', $expressionSource);
        preg_match_all('/(?<![A-Za-z0-9_$.])[A-Za-z_][A-Za-z0-9_]*/', $sourceWithoutStrings, $identifierMatches);
        return array_values(array_unique($identifierMatches[0]));
    }

    /**
     * Adds the target of every `$state` binding inside `$value` to `$stateNames` — a `$source` binding's parameters
     * included.
     *
     * @param array<string, true> $stateNames
     */
    protected static function collectStateBindingTargets(mixed $value, array &$stateNames): void
    {
        if (!is_array($value)) {
            return;
        }
        $composedViewBinding = ComposedViewBinding::fromWireValue($value);
        if ($composedViewBinding !== null) {
            if ($composedViewBinding->kind === ComposedViewBinding::KIND_STATE && $composedViewBinding->isWellFormed) {
                $stateNames[$composedViewBinding->target] = true;
            }
            if ($composedViewBinding->kind === ComposedViewBinding::KIND_SOURCE) {
                self::collectStateBindingTargets($composedViewBinding->sourceParams, $stateNames);
            }
            return;
        }
        foreach ($value as $nestedValue) {
            self::collectStateBindingTargets($nestedValue, $stateNames);
        }
    }

    /**
     * Flags each `$item` binding whose field no row of its item scope has, where the scope repeats a RESOLVED `$from`
     * list (directly, or through a `$data` set that binds one): the stored block carries the rows, so the finding names
     * their fields. A scope over written rows, a `$computed` or a pending binding is not checked; the prop stays.
     */
    protected function flagItemFieldsMissingFromTheirRows(): void
    {
        if ($this->view !== null) {
            $this->flagItemFieldsOfSubtree($this->view, 'view', null);
        }
    }

    /** @param array<int, array<mixed>>|null $itemRows the object rows of the item scope the node sits in; null = none or not known */
    protected function flagItemFieldsOfSubtree(ComposedViewNode $composedViewNode, string $path, ?array $itemRows): void
    {
        if ($itemRows !== null) {
            foreach ($composedViewNode->findBindings() as $propPath => $composedViewBinding) {
                if ($composedViewBinding->kind !== ComposedViewBinding::KIND_ITEM || !$composedViewBinding->isWellFormed || self::rowsHaveField($itemRows, $composedViewBinding->target)) {
                    continue;
                }
                $this->findings->addFinding(ComposedViewValidationFinding::CODE_UNKNOWN_ITEM_KEY, $path . '.' . $propPath, sprintf(
                    'no row of the bound rows has the field "%s" — it renders empty; the rows have: %s',
                    $composedViewBinding->target,
                    implode(', ', ComposedViewBindablePathListing::buildListedRowKeys($itemRows[0])),
                ));
            }
        }
        $childItemRows = $composedViewNode::PROVIDES_ITEM_SCOPE ? $this->findResolvedRowsOfItems($composedViewNode->props[$composedViewNode::ITEMS_PROP_NAME] ?? null) : $itemRows;
        foreach ($composedViewNode->getNodesOfSlots() as $composedViewNodesOfSlot) {
            $slotName = $composedViewNodesOfSlot->slotName;
            $slotNodes = $composedViewNodesOfSlot->nodes;
            $slotPath = $slotName === ComposedViewNodeSlot::DEFAULT_SLOT_NAME ? $path . '.children' : $path . '.slots.' . $slotName;
            foreach (array_values($slotNodes) as $childPosition => $slotNode) {
                $this->flagItemFieldsOfSubtree($slotNode, $slotPath . '[' . $childPosition . ']', $childItemRows);
            }
        }
    }

    /**
     * The object rows an item scope repeats over when its items are a resolved `$from` list, directly or through a
     * `$data` set; null otherwise.
     *
     * @return array<int, array<mixed>>|null
     */
    protected function findResolvedRowsOfItems(mixed $itemsValue): ?array
    {
        $composedViewBinding = ComposedViewBinding::fromWireValue($itemsValue);
        $fromBinding = match ($composedViewBinding?->kind) {
            ComposedViewBinding::KIND_FROM => $itemsValue['$from'] ?? null,
            ComposedViewBinding::KIND_DATA => $this->data[$composedViewBinding->target]['$from'] ?? null,
            default => null,
        };
        if (!is_array($fromBinding) || ($fromBinding['state'] ?? null) !== ComposedViewBindingResolution::STATE_RESOLVED || !is_array($fromBinding['value'] ?? null) || !array_is_list($fromBinding['value'])) {
            return null;
        }
        $objectRows = array_values(array_filter($fromBinding['value'], static fn (mixed $row): bool => is_array($row) && $row !== [] && !array_is_list($row)));
        return $objectRows === [] ? null : $objectRows;
    }

    /** @param array<int, array<mixed>> $rows */
    protected static function rowsHaveField(array $rows, string $fieldName): bool
    {
        foreach ($rows as $row) {
            $found = false;
            ComposedViewBinding::readRowField($row, $fieldName, $found);
            if ($found) {
                return true;
            }
        }
        return false;
    }

    /**
     * The block's fields as WRITTEN, without the nodes the lift took out of the tree (the lifted `Widget` nodes and the
     * layout nodes pruned with them); every other byte of the tree stays — a node the reader dropped for another reason
     * stays too, so its render report still tells the model. No `view` when the lift took the root. The fields as given
     * when nothing was lifted.
     *
     * @param array<mixed> $widgetFields the block's decoded fields (`type` included or not)
     * @return array<mixed>
     */
    public function buildWireFieldsWithoutLiftedNodes(array $widgetFields): array
    {
        if ($this->liftRemovedWirePaths === [] || !array_key_exists('view', $widgetFields)) {
            return $widgetFields;
        }
        $keptWireView = $this->buildWireNodeWithoutLiftedPaths($widgetFields['view'], 'view');
        if ($keptWireView === null) {
            unset($widgetFields['view']);
            return $widgetFields;
        }
        $widgetFields['view'] = $keptWireView;
        return $widgetFields;
    }

    /** One written node without the lifted paths below it, walked with the reader's paths; null when the node itself is removed. */
    protected function buildWireNodeWithoutLiftedPaths(mixed $wireNode, string $path): mixed
    {
        if (in_array($path, $this->liftRemovedWirePaths, true)) {
            return null;
        }
        if (!is_array($wireNode)) {
            return $wireNode;
        }
        if (array_key_exists('children', $wireNode) && is_array($wireNode['children']) && array_is_list($wireNode['children'])) {
            $wireNode['children'] = $this->buildWireSlotNodesWithoutLiftedPaths($wireNode['children'], $path . '.children');
        }
        if (is_array($wireNode['slots'] ?? null)) {
            foreach ($wireNode['slots'] as $slotName => $slotWireNodes) {
                $isDefaultSlotWrittenTwice = (string)$slotName === ComposedViewNodeSlot::DEFAULT_SLOT_NAME && array_key_exists('children', $wireNode);
                if ($isDefaultSlotWrittenTwice || !is_array($slotWireNodes) || !array_is_list($slotWireNodes)) {
                    continue;
                }
                $slotPath = (string)$slotName === ComposedViewNodeSlot::DEFAULT_SLOT_NAME ? $path . '.children' : $path . '.slots.' . $slotName;
                $wireNode['slots'][$slotName] = $this->buildWireSlotNodesWithoutLiftedPaths($slotWireNodes, $slotPath);
            }
        }
        return $wireNode;
    }

    /**
     * @param array<int, mixed> $slotWireNodes
     * @return array<int, mixed>
     */
    protected function buildWireSlotNodesWithoutLiftedPaths(array $slotWireNodes, string $slotPath): array
    {
        $keptSlotWireNodes = [];
        foreach ($slotWireNodes as $childPosition => $slotWireNode) {
            $keptSlotWireNode = $this->buildWireNodeWithoutLiftedPaths($slotWireNode, $slotPath . '[' . $childPosition . ']');
            if ($keptSlotWireNode !== null) {
                $keptSlotWireNodes[] = $keptSlotWireNode;
            }
        }
        return $keptSlotWireNodes;
    }

    /**
     * The lifted widgets as their own blocks, reading order: each the ```widget fence of the widget's canonical type and
     * its node's `fields`.
     *
     * @return string[]
     */
    public function buildLiftedWidgetBlocks(): array
    {
        $liftedWidgetBlocks = [];
        foreach ($this->liftedWidgetNodes->getElements() as $composedViewLiftedWidgetNode) {
            $liftedWidgetNode = $composedViewLiftedWidgetNode->widgetComposedViewNode;
            $liftedWidgetFields = $liftedWidgetNode->getEmbeddedWidgetFields();
            unset($liftedWidgetFields['type']);
            $liftedWidgetBlocks[] = WidgetBlockSerializer::buildFenceBlock((string)$liftedWidgetNode->getEmbeddedWidgetType(), null, $liftedWidgetFields);
        }
        return $liftedWidgetBlocks;
    }

    /**
     * The lifted widgets as the parity fixture and the frontend reader hold them: path, canonical type, fields.
     *
     * @return array<int, array{path: string, widget: string, fields: array<string, mixed>}>
     */
    public function buildLiftedWidgetRecords(): array
    {
        $liftedWidgetRecords = [];
        foreach ($this->liftedWidgetNodes->getElements() as $composedViewLiftedWidgetNode) {
            $liftedWidgetNode = $composedViewLiftedWidgetNode->widgetComposedViewNode;
            $liftedWidgetRecords[] = ['path' => $composedViewLiftedWidgetNode->path, 'widget' => (string)$liftedWidgetNode->getEmbeddedWidgetType(), 'fields' => $liftedWidgetNode->getEmbeddedWidgetFields()];
        }
        return $liftedWidgetRecords;
    }

    /** Whether any string in `$value` (keys included) contains three backticks. */
    protected function containsTripleBackticks(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, '```');
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $nestedKey => $nestedValue) {
            if (str_contains((string)$nestedKey, '```') || $this->containsTripleBackticks($nestedValue)) {
                return true;
            }
        }
        return false;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
