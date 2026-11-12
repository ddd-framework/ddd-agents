<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

/**
 * The fixed budgets of a `ComposedView` tree. The backend
 * enforces the structural ones when it reads a block; the frontend evaluator and renderer read every number from
 * `composed-node-schema.json` (`ComposedViewNodeSchema`), so both sides use the same values. A tree over a budget is
 * not refused: the part over it is dropped and reported (`BUDGET_EXCEEDED`), the rest renders.
 */
class ComposedViewBudgets
{
    /** Bytes of the block's canonical JSON (its fields without `type`, compact — {@see ComposedViewTreeReading::measureSourceBytes()}); a larger block renders no view. */
    public const int MAX_SOURCE_BYTES = 65536;

    /** Nodes in the tree (all slots, `Repeat` templates counted once). */
    public const int MAX_NODES = 400;

    /** Nesting depth of the view (the root is depth 1). */
    public const int MAX_DEPTH = 12;

    /** Names in `state`. */
    public const int MAX_STATE_KEYS = 40;

    /** Length of a string in `state` (initial values and values an input writes). */
    public const int MAX_STATE_STRING_LENGTH = 500;

    /** Expressions in `computed`. */
    public const int MAX_COMPUTED_EXPRESSIONS = 40;

    /** Characters of one expression's source. */
    public const int MAX_EXPRESSION_SOURCE_LENGTH = 600;

    /** AST nodes of one compiled expression (frontend). */
    public const int MAX_EXPRESSION_AST_NODES = 200;

    /** Dependency depth between computed values (a cycle is rejected outright, frontend). */
    public const int MAX_COMPUTED_DEPENDENCY_DEPTH = 8;

    /** Operations one state update may spend over all expressions, nested `map` / `filter` / `sortBy` included (frontend). */
    public const int MAX_OPERATIONS_PER_UPDATE = 50000;

    /** Elements of a collection an expression reads or builds (frontend). */
    public const int MAX_COLLECTION_ITEMS = 1000;

    /** Elements allocated for intermediate collections in one evaluation (frontend). */
    public const int MAX_ALLOCATED_ITEMS_PER_EVALUATION = 5000;

    /** Rows a `Repeat` expands (frontend). */
    public const int MAX_REPEAT_ROWS = 200;

    /** Nodes rendered after `Repeat` expansion (frontend). */
    public const int MAX_RENDERED_NODES = 2000;

    /** Elements of an inline data array (a `data` prop written into the block). */
    public const int MAX_INLINE_ARRAY_ITEMS = 500;

    /** Scalar cells of all inline data in the block — "a few hundred cells", with room for a year table. */
    public const int MAX_INLINE_CELLS = 3000;

    /** Characters of one text a node shows. */
    public const int MAX_TEXT_LENGTH = 4000;

    /** Named actions. */
    public const int MAX_ACTIONS = 20;

    /** State updates an input may cause per second (frontend; the rest is dropped). */
    public const int MAX_INPUT_UPDATES_PER_SECOND = 30;

    /** Data-source reads one tree may start in a minute (state-bound parameters re-read; frontend, debounced 300 ms). */
    public const int MAX_SOURCE_REQUESTS_PER_TREE_PER_MINUTE = 20;

    /** @return array<string, int> every budget, as `composed-node-schema.json` lists them */
    public static function toSchemaArray(): array
    {
        return [
            'maxSourceBytes' => self::MAX_SOURCE_BYTES,
            'maxNodes' => self::MAX_NODES,
            'maxDepth' => self::MAX_DEPTH,
            'maxStateKeys' => self::MAX_STATE_KEYS,
            'maxStateStringLength' => self::MAX_STATE_STRING_LENGTH,
            'maxComputedExpressions' => self::MAX_COMPUTED_EXPRESSIONS,
            'maxExpressionSourceLength' => self::MAX_EXPRESSION_SOURCE_LENGTH,
            'maxExpressionAstNodes' => self::MAX_EXPRESSION_AST_NODES,
            'maxComputedDependencyDepth' => self::MAX_COMPUTED_DEPENDENCY_DEPTH,
            'maxOperationsPerUpdate' => self::MAX_OPERATIONS_PER_UPDATE,
            'maxCollectionItems' => self::MAX_COLLECTION_ITEMS,
            'maxAllocatedItemsPerEvaluation' => self::MAX_ALLOCATED_ITEMS_PER_EVALUATION,
            'maxRepeatRows' => self::MAX_REPEAT_ROWS,
            'maxRenderedNodes' => self::MAX_RENDERED_NODES,
            'maxInlineArrayItems' => self::MAX_INLINE_ARRAY_ITEMS,
            'maxInlineCells' => self::MAX_INLINE_CELLS,
            'maxTextLength' => self::MAX_TEXT_LENGTH,
            'maxActions' => self::MAX_ACTIONS,
            'maxInputUpdatesPerSecond' => self::MAX_INPUT_UPDATES_PER_SECOND,
            'maxSourceRequestsPerTreePerMinute' => self::MAX_SOURCE_REQUESTS_PER_TREE_PER_MINUTE,
        ];
    }
}
