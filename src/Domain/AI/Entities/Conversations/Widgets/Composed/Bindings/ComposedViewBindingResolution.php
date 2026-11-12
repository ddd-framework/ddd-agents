<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewBinding;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewBudgets;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReference;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceMinting;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceRow;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The persist-time resolution of ONE `ComposedView` block: the block's fields in,
 * the fields with every `$from` binding and every `openEntity` reference resolved out. Pure — the stored tool calls the
 * block names ({@see ComposedViewToolCallResults}) and the readable entity rows are handed in by
 * {@see \DDD\Domain\AI\Services\Conversations\ComposedViewBindingResolutionService}.
 *
 * A `$from` binding (in `data` or in any node prop) gets its STATE written into its own object — the snapshot the
 * frontend reads (`resolve-composed-view-values.ts`) and a replay shows without resolving anything again:
 *
 * | State | When | Written |
 * |---|---|---|
 * | `pending` | the named call has no result yet — a call of the same step, or one still running | `toolCall`, `path`, `state` |
 * | `resolved` | the call COMPLETED and the path selects a value | `value` (a list capped to {@see $maxRows} rows, each object row with its `entityRef` + `entity` when the call listed it), `resolvedAt` (ISO 8601 UTC), `rowCount` / `cappedToRows` for a list |
 * | `failed` | the call ended without a usable result, the path selects nothing, the result binds nothing, the id names no call (once the host step is over) or two calls | `value` = `[]` (an empty dataset), `reason` |
 *
 * `toolCall` and `path` stay as provenance. An `openEntity` action gets `entity` = `{entityType, entityId, <key field>}`
 * of the row its `ref` names — REPLACING whatever the model wrote there, never trusting it — plus `refState`; a
 * reference that names no readable row of the call loses its `entity` (the node renders its text without the click,
 * the frontend reports `UNRESOLVED_REF`). Nothing is refused: data validity, not a gate.
 *
 * A block seen for the FIRST time ({@see $trustsStoredSnapshots} false — the model wrote it) is resolved fresh: a
 * `state`, `value` or `entity` the model typed is dropped first, so the model cannot write a "resolved" snapshot of its
 * own. A stored block keeps its `resolved` / `failed` snapshots and resolves only what is still pending.
 */
class ComposedViewBindingResolution extends ValueObject
{
    public const string STATE_PENDING = 'pending';
    public const string STATE_RESOLVED = 'resolved';
    public const string STATE_FAILED = 'failed';

    /** The keys a `$from` object keeps from the model; every other key is the platform's. */
    public const array FROM_BINDING_MODEL_KEYS = ['toolCall', 'path'];

    /** The row keys the resolution adds to a resolved row the call listed as an entity. */
    public const string ROW_ENTITY_REFERENCE_KEY = 'entityRef';
    public const string ROW_ENTITY_KEY = 'entity';

    /** Containers a decoded block may nest (`json_decode(…, 32)` on both sides: 31 nested containers). */
    public const int MAX_BLOCK_NESTING = 31;

    /** @var int Rows a resolved list keeps at most (the `Repeat` budget). */
    public int $maxRows = ComposedViewBudgets::MAX_REPEAT_ROWS;

    /** @var bool The block is stored already: its `resolved` / `failed` snapshots stand. */
    public bool $trustsStoredSnapshots = false;

    /** @var bool A later model step has started: an id the conversation does not know will not come any more. */
    public bool $hostStepIsOver = false;

    /** @var int|null The key of the conversation's agent space — a row of another space gets no entity. */
    public ?int $widgetSelfHydrationKey = null;

    /** @var string The moment a snapshot is resolved, ISO 8601 UTC (`2026-10-25T12:30:00Z`). */
    public string $resolvedAt = '';

    /** @var ComposedViewToolCallResults|null The calls the block names. */
    public ?ComposedViewToolCallResults $toolCallResults = null;

    /**
     * @var ComposedViewReadableEntityRows|null The rows `openEntity` references name that are readable for the space.
     */
    public ?ComposedViewReadableEntityRows $readableEntityRows = null;

    /**
     * @var string `refState` of an `openEntity` action whose `ref` is bound to the current row (`{"$item":"entityRef"}`):
     *      the entity is the row's own — the `entity` the resolution wrote into each row of a resolved `$from` list.
     */
    public const string STATE_FROM_ROW = 'row';

    /** @var bool The block stays over the source budget with no rows: every binding that resolved fails as too large. */
    public bool $failsResolvedBindingsAsTooLarge = false;

    /** @var array<string, true> The tool call ids a binding of the block still waits for (outcome). */
    public array $pendingToolCallIds = [];

    /** @var bool The resolution changed the block (outcome). */
    public bool $changedBlock = false;

    /**
     * The tool call ids the block's `$from` bindings and `openEntity` references name that need a lookup — on a stored
     * block only the still pending ones.
     *
     * @param array<mixed> $widgetFields
     * @return string[]
     */
    public static function findNamedToolCallIds(array $widgetFields, bool $trustsStoredSnapshots): array
    {
        $toolCallIds = [];
        self::collectFromBindingToolCallIds($widgetFields['data'] ?? null, $trustsStoredSnapshots, $toolCallIds);
        self::collectFromBindingToolCallIds($widgetFields['view'] ?? null, $trustsStoredSnapshots, $toolCallIds);
        foreach (self::findOpenEntityReferences($widgetFields, $trustsStoredSnapshots) as $entityReference) {
            $toolCallIds[$entityReference->toolCallId] = true;
        }
        return array_keys($toolCallIds);
    }

    /**
     * The references of the block's `openEntity` actions that need resolving, by action name (a malformed `ref` is
     * left out — it resolves to nothing either way).
     *
     * @param array<mixed> $widgetFields
     * @return EntityReference[] Keyed by action name
     */
    public static function findOpenEntityReferences(array $widgetFields, bool $trustsStoredSnapshots): array
    {
        $entityReferencesByActionName = [];
        foreach (is_array($widgetFields['actions'] ?? null) ? $widgetFields['actions'] : [] as $actionName => $action) {
            if (!is_array($action) || ($action['type'] ?? null) !== ComposedViewTreeReading::ACTION_OPEN_ENTITY || !is_string($action['ref'] ?? null)) {
                continue;
            }
            if ($trustsStoredSnapshots && in_array($action['refState'] ?? null, [self::STATE_RESOLVED, self::STATE_FAILED], true)) {
                continue;
            }
            $entityReference = EntityReference::fromWireValue($action['ref']);
            if ($entityReference !== null) {
                $entityReferencesByActionName[(string)$actionName] = $entityReference;
            }
        }
        return $entityReferencesByActionName;
    }

    /**
     * The block's fields as the MODEL reads them again (history, compaction, recall): every `$from` binding folded back
     * to what the model wrote — `{toolCall, path}` — and every `openEntity` action without its resolved `entity` and
     * `refState`. The stored snapshot stays for the frontend and the replay; the model never reads the bound rows back,
     * and a pending and a resolved row read byte for byte the same (the prompt cache survives the resolution).
     *
     * @param array<mixed> $widgetFields
     * @return array<mixed>
     */
    public static function foldSnapshotsForTheModel(array $widgetFields): array
    {
        $foldedFields = $widgetFields;
        foreach (['data', 'view'] as $fieldName) {
            if (array_key_exists($fieldName, $widgetFields)) {
                $foldedFields[$fieldName] = self::foldFromBindingsOfValue($widgetFields[$fieldName]);
            }
        }
        foreach (is_array($widgetFields['actions'] ?? null) ? $widgetFields['actions'] : [] as $actionName => $action) {
            if (is_array($action) && ($action['type'] ?? null) === ComposedViewTreeReading::ACTION_OPEN_ENTITY) {
                unset($action['entity'], $action['refState']);
                $foldedFields['actions'][$actionName] = $action;
            }
        }
        return $foldedFields;
    }

    /** A value with every `$from` binding inside it reduced to `{toolCall, path}`. */
    protected static function foldFromBindingsOfValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $composedViewBinding = ComposedViewBinding::fromWireValue($value);
        if ($composedViewBinding !== null) {
            return $composedViewBinding->kind === ComposedViewBinding::KIND_FROM && is_array($value['$from'] ?? null)
                ? ['$from' => array_intersect_key($value['$from'], array_flip(self::FROM_BINDING_MODEL_KEYS))]
                : $value;
        }
        $foldedValue = [];
        foreach ($value as $key => $nestedValue) {
            $foldedValue[$key] = self::foldFromBindingsOfValue($nestedValue);
        }
        return $foldedValue;
    }

    /**
     * The row a reference names among the rows its call listed, or null — no call, an ambiguous id, no such row.
     */
    public function findEntityRowNamedByReference(EntityReference $entityReference): ?EntityReferenceRow
    {
        $composedViewToolCallResult = $this->toolCallResults?->findToolCallResult($entityReference->toolCallId);
        if ($composedViewToolCallResult === null || $composedViewToolCallResult->status !== AIConversationMessage::STATUS_COMPLETED) {
            return null;
        }
        return EntityReferenceMinting::findRowNamedByReference($entityReference, $composedViewToolCallResult->getEntityReferenceRows());
    }

    /**
     * The block's fields resolved (see the class doc); {@see $changedBlock} and {@see $pendingToolCallIds} tell the
     * outcome.
     *
     * @param array<mixed> $widgetFields
     * @return array<mixed>
     */
    public function resolveWidgetFields(array $widgetFields): array
    {
        $this->pendingToolCallIds = [];
        $this->changedBlock = false;
        $resolvedFields = $widgetFields;
        if (array_key_exists('data', $widgetFields)) {
            $resolvedFields['data'] = $this->resolveValue($widgetFields['data'], 2);
        }
        if (array_key_exists('view', $widgetFields)) {
            $resolvedFields['view'] = $this->resolveValue($widgetFields['view'], 2);
        }
        if (is_array($widgetFields['actions'] ?? null)) {
            foreach ($widgetFields['actions'] as $actionName => $action) {
                if (is_array($action) && ($action['type'] ?? null) === ComposedViewTreeReading::ACTION_OPEN_ENTITY) {
                    $resolvedFields['actions'][$actionName] = $this->resolveOpenEntityAction($action);
                }
            }
        }
        $this->changedBlock = $resolvedFields !== $widgetFields;
        return $resolvedFields;
    }

    /**
     * {@see resolveWidgetFields()} within the source budget: a block whose resolved values make it larger than
     * {@see ComposedViewBudgets::MAX_SOURCE_BYTES} (it would render no view) is resolved again with fewer rows per
     * list, down to none.
     *
     * @param array<mixed> $widgetFields
     * @return array<mixed>
     */
    public function resolveWidgetFieldsWithinSourceBudget(array $widgetFields): array
    {
        $configuredMaxRows = $this->maxRows;
        try {
            foreach ([$configuredMaxRows, 100, 50, 20, 10, 5, 0] as $maxRows) {
                if ($maxRows > $configuredMaxRows) {
                    continue;
                }
                $this->maxRows = $maxRows;
                $resolvedFields = $this->resolveWidgetFields($widgetFields);
                if (ComposedViewTreeReading::measureSourceBytes($resolvedFields) <= ComposedViewBudgets::MAX_SOURCE_BYTES) {
                    return $resolvedFields;
                }
            }
            // still over with no rows: a value that is no list (a whole result bound by `$`) or the block itself — every
            // resolved binding fails, so no block over the budget is ever stored
            $this->maxRows = 0;
            $this->failsResolvedBindingsAsTooLarge = true;
            return $this->resolveWidgetFields($widgetFields);
        } finally {
            $this->maxRows = $configuredMaxRows;
            $this->failsResolvedBindingsAsTooLarge = false;
        }
    }

    /** A value of the block's `data` or `view`, its `$from` bindings resolved at any depth. */
    protected function resolveValue(mixed $value, int $depth): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $composedViewBinding = ComposedViewBinding::fromWireValue($value);
        if ($composedViewBinding !== null && $composedViewBinding->kind === ComposedViewBinding::KIND_FROM && is_array($value['$from'] ?? null)) {
            return ['$from' => $this->resolveFromBinding($value['$from'], $depth + 1)];
        }
        if ($composedViewBinding !== null) {
            return $value;
        }
        $resolvedValue = [];
        foreach ($value as $key => $nestedValue) {
            $resolvedValue[$key] = $this->resolveValue($nestedValue, $depth + 1);
        }
        return $resolvedValue;
    }

    /**
     * One `$from` object resolved (see the class doc). `$depth` is its nesting in the block, so the written value stays
     * inside {@see MAX_BLOCK_NESTING}.
     *
     * @param array<mixed> $fromBinding
     * @return array<string, mixed>
     */
    protected function resolveFromBinding(array $fromBinding, int $depth): array
    {
        if ($this->trustsStoredSnapshots && in_array($fromBinding['state'] ?? null, [self::STATE_RESOLVED, self::STATE_FAILED], true)) {
            return $fromBinding;
        }
        $provenance = array_intersect_key($fromBinding, array_flip(self::FROM_BINDING_MODEL_KEYS));
        $toolCallId = is_string($provenance['toolCall'] ?? null) ? $provenance['toolCall'] : '';
        $composedViewToolResultPath = is_string($provenance['path'] ?? null) ? ComposedViewToolResultPath::fromPathString($provenance['path']) : null;
        if ($toolCallId === '' || $composedViewToolResultPath === null) {
            return $this->buildFailedSnapshot($provenance, 'the binding needs a `toolCall` id and a `path` like "$.rows[*]" (keys, [n], [*])');
        }
        if ($this->toolCallResults?->isAmbiguous($toolCallId) ?? false) {
            return $this->buildFailedSnapshot($provenance, sprintf('two tool calls of this conversation carry the id "%s" — the binding names neither', $toolCallId));
        }
        $composedViewToolCallResult = $this->toolCallResults?->findToolCallResult($toolCallId);
        if ($composedViewToolCallResult === null) {
            if ($this->hostStepIsOver) {
                return $this->buildFailedSnapshot($provenance, sprintf('no tool call "%s" in this conversation', $toolCallId));
            }
            return $this->buildPendingSnapshot($provenance, $toolCallId);
        }
        if (!$composedViewToolCallResult->hasEnded()) {
            return $this->buildPendingSnapshot($provenance, $toolCallId);
        }
        if ($composedViewToolCallResult->status !== AIConversationMessage::STATUS_COMPLETED || $composedViewToolCallResult->resultIsUnusable) {
            return $this->buildFailedSnapshot($provenance, sprintf('the tool call "%s" ended without a usable result', $toolCallId));
        }
        if (!$composedViewToolCallResult->hasBindableData) {
            return $this->buildFailedSnapshot($provenance, $composedViewToolCallResult->bindableDataOmittedBytes !== null
                ? sprintf('the result of "%s" is too large to bind (%d bytes)', $toolCallId, $composedViewToolCallResult->bindableDataOmittedBytes)
                : sprintf('the result of "%s" stores no structured data a path can address', $toolCallId));
        }
        $pathFound = false;
        $selectedValue = $composedViewToolResultPath->selectFrom($composedViewToolCallResult->bindableData, $pathFound);
        if (!$pathFound) {
            return $this->buildFailedSnapshot($provenance, sprintf('the path "%s" selects nothing in the result of "%s"', $composedViewToolResultPath->path, $toolCallId));
        }
        $resolvedSnapshot = $provenance + ['state' => self::STATE_RESOLVED];
        if (is_array($selectedValue) && array_is_list($selectedValue)) {
            $rowCount = count($selectedValue);
            $selectedValue = $this->attachEntityReferencesToRows(array_slice($selectedValue, 0, $this->maxRows), $composedViewToolCallResult);
            $resolvedSnapshot['rowCount'] = $rowCount;
            if ($rowCount > $this->maxRows) {
                $resolvedSnapshot['cappedToRows'] = $this->maxRows;
            }
        }
        $resolvedSnapshot['value'] = self::limitNesting($selectedValue, self::MAX_BLOCK_NESTING - $depth - 1);
        if (str_contains((string)json_encode($resolvedSnapshot['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '```')) {
            return $this->buildFailedSnapshot($provenance, sprintf('the selected value of "%s" holds three backticks, which would end the widget fence', $toolCallId));
        }
        if ($this->failsResolvedBindingsAsTooLarge) {
            return $this->buildFailedSnapshot($provenance, sprintf('the value the path selects in "%s" is too large for the widget (the block is limited to %d bytes) — bind a narrower path', $toolCallId, ComposedViewBudgets::MAX_SOURCE_BYTES));
        }
        $resolvedSnapshot['resolvedAt'] = $this->resolvedAt;
        return $resolvedSnapshot;
    }

    /**
     * One `openEntity` action: the model's `entity` replaced by the row its `ref` names (readable for the space),
     * or removed.
     *
     * @param array<mixed> $action
     * @return array<mixed>
     */
    protected function resolveOpenEntityAction(array $action): array
    {
        if ($this->trustsStoredSnapshots && in_array($action['refState'] ?? null, [self::STATE_RESOLVED, self::STATE_FAILED], true)) {
            return $action;
        }
        unset($action['entity'], $action['refState']);
        if (self::isRowBoundReference($action['ref'] ?? null)) {
            // the entity is the current row's own (`entity` of a resolved `$from` row) — never one the model wrote here
            return $action + ['refState' => self::STATE_FROM_ROW];
        }
        $entityReference = is_string($action['ref'] ?? null) ? EntityReference::fromWireValue($action['ref']) : null;
        if ($entityReference === null) {
            return $action + ['refState' => self::STATE_FAILED];
        }
        $composedViewToolCallResult = $this->toolCallResults?->findToolCallResult($entityReference->toolCallId);
        $callIsUnknown = $composedViewToolCallResult === null && !($this->toolCallResults?->isAmbiguous($entityReference->toolCallId) ?? false);
        if (($callIsUnknown && !$this->hostStepIsOver) || ($composedViewToolCallResult !== null && !$composedViewToolCallResult->hasEnded())) {
            $this->pendingToolCallIds[$entityReference->toolCallId] = true;
            return $action + ['refState' => self::STATE_PENDING];
        }
        $readableEntityRow = $this->readableEntityRows?->getRowOfReference($entityReference);
        if ($readableEntityRow === null) {
            return $action + ['refState' => self::STATE_FAILED];
        }
        return $action + ['entity' => $readableEntityRow->toWireArray(), 'refState' => self::STATE_RESOLVED];
    }

    /** Whether an `openEntity` `ref` names the current row's reference: `{"$item": "<key>"}` (inside a `Repeat`). */
    public static function isRowBoundReference(mixed $reference): bool
    {
        $composedViewBinding = ComposedViewBinding::fromWireValue($reference);
        return $composedViewBinding !== null && $composedViewBinding->kind === ComposedViewBinding::KIND_ITEM && $composedViewBinding->isWellFormed;
    }

    /**
     * Each object row of a resolved list with the reference of the entity it is, when the call listed that entity: the
     * row's own `id` or `<entityType>Id` (`workshopId`) equals the entity id, or the entity id's part after its prefix
     * (`ext-<externalId>`). No other key and no nested object counts. Exactly one listed entity must match; a row of
     * another space, an ambiguous match or a row that already has the key stays as it is.
     *
     * @param array<int, mixed> $rows
     * @return array<int, mixed>
     */
    protected function attachEntityReferencesToRows(array $rows, ComposedViewToolCallResult $composedViewToolCallResult): array
    {
        $mintedReferences = EntityReferenceMinting::mintForToolCall($composedViewToolCallResult->toolCallId, ...$composedViewToolCallResult->getEntityReferenceRows());
        if ($mintedReferences === []) {
            return $rows;
        }
        $referenceWireValuesByTypeAndIdentity = [];
        foreach ($mintedReferences as $referenceWireValue => $entityReferenceRow) {
            foreach (self::buildEntityIdentities($entityReferenceRow->entityId) as $entityIdentity) {
                $referenceWireValuesByTypeAndIdentity[$entityReferenceRow->entityType][$entityIdentity][$referenceWireValue] = true;
            }
        }
        foreach ($rows as $rowPosition => $row) {
            if (!is_array($row) || array_is_list($row) || array_key_exists(self::ROW_ENTITY_REFERENCE_KEY, $row) || array_key_exists(self::ROW_ENTITY_KEY, $row)) {
                continue;
            }
            $matchedReferenceWireValues = [];
            foreach ($referenceWireValuesByTypeAndIdentity as $entityType => $referenceWireValuesByIdentity) {
                foreach (self::collectRowIdentityValues($row, (string)$entityType) as $rowIdentityValue) {
                    $matchedReferenceWireValues += $referenceWireValuesByIdentity[$rowIdentityValue] ?? [];
                }
            }
            if (count($matchedReferenceWireValues) !== 1) {
                continue;
            }
            $referenceWireValue = (string)array_key_first($matchedReferenceWireValues);
            $entityReferenceRow = $mintedReferences[$referenceWireValue];
            if ($this->widgetSelfHydrationKey !== null && $entityReferenceRow->widgetSelfHydrationKey !== null && $entityReferenceRow->widgetSelfHydrationKey !== $this->widgetSelfHydrationKey) {
                continue;
            }
            $rows[$rowPosition][self::ROW_ENTITY_REFERENCE_KEY] = $referenceWireValue;
            $rows[$rowPosition][self::ROW_ENTITY_KEY] = $entityReferenceRow->toWireArray();
        }
        return $rows;
    }

    /** @return string[] the entity id and, for a prefixed id (`ext-123`), the part after its first `-` */
    protected static function buildEntityIdentities(string $entityId): array
    {
        $entityIdentities = [$entityId];
        $prefixSeparatorPosition = strpos($entityId, '-');
        if ($prefixSeparatorPosition !== false && $prefixSeparatorPosition < strlen($entityId) - 1) {
            $entityIdentities[] = substr($entityId, $prefixSeparatorPosition + 1);
        }
        return $entityIdentities;
    }

    /**
     * The values of a row that may be its identity as an entity of `$entityType`: its own `id` and `<entityType>Id`.
     *
     * @param array<mixed> $row
     * @return string[]
     */
    protected static function collectRowIdentityValues(array $row, string $entityType): array
    {
        $rowIdentityValues = [];
        foreach (['id', $entityType . 'Id'] as $identityKey) {
            $rowValue = $row[$identityKey] ?? null;
            if ((is_string($rowValue) || is_int($rowValue)) && (string)$rowValue !== '') {
                $rowIdentityValues[] = (string)$rowValue;
            }
        }
        return array_values(array_unique($rowIdentityValues));
    }

    /** `$value` with every container deeper than `$remainingNesting` levels replaced by null. */
    protected static function limitNesting(mixed $value, int $remainingNesting): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($remainingNesting <= 0) {
            return null;
        }
        $limitedValue = [];
        foreach ($value as $key => $nestedValue) {
            $limitedValue[$key] = self::limitNesting($nestedValue, $remainingNesting - 1);
        }
        return $limitedValue;
    }

    /**
     * @param array<string, mixed> $provenance
     * @return array<string, mixed>
     */
    protected function buildPendingSnapshot(array $provenance, string $toolCallId): array
    {
        $this->pendingToolCallIds[$toolCallId] = true;
        return $provenance + ['state' => self::STATE_PENDING];
    }

    /**
     * @param array<string, mixed> $provenance
     * @return array<string, mixed>
     */
    protected function buildFailedSnapshot(array $provenance, string $reason): array
    {
        return $provenance + ['state' => self::STATE_FAILED, 'value' => [], 'reason' => $reason];
    }

    /**
     * The `$from` bindings of the block whose stored snapshot is `failed` — a binding that resolved to nothing, the
     * empty dataset the view renders (the eval counts such a block as malformed, `scripts/evals/widgets-report.php`).
     *
     * @param array<mixed> $widgetFields
     */
    public static function countFailedFromBindings(array $widgetFields): int
    {
        return self::countFailedFromBindingsOfValue($widgetFields['data'] ?? null) + self::countFailedFromBindingsOfValue($widgetFields['view'] ?? null);
    }

    protected static function countFailedFromBindingsOfValue(mixed $value): int
    {
        if (!is_array($value)) {
            return 0;
        }
        $composedViewBinding = ComposedViewBinding::fromWireValue($value);
        if ($composedViewBinding !== null) {
            return $composedViewBinding->kind === ComposedViewBinding::KIND_FROM && ($value['$from']['state'] ?? null) === self::STATE_FAILED ? 1 : 0;
        }
        $failedFromBindingCount = 0;
        foreach ($value as $nestedValue) {
            $failedFromBindingCount += self::countFailedFromBindingsOfValue($nestedValue);
        }
        return $failedFromBindingCount;
    }

    /**
     * @param array<string, true> $toolCallIds
     */
    protected static function collectFromBindingToolCallIds(mixed $value, bool $trustsStoredSnapshots, array &$toolCallIds): void
    {
        if (!is_array($value)) {
            return;
        }
        $composedViewBinding = ComposedViewBinding::fromWireValue($value);
        if ($composedViewBinding !== null) {
            $fromBinding = $value['$from'] ?? null;
            if ($composedViewBinding->kind === ComposedViewBinding::KIND_FROM && $composedViewBinding->target !== '' && is_array($fromBinding)
                && !($trustsStoredSnapshots && in_array($fromBinding['state'] ?? null, [self::STATE_RESOLVED, self::STATE_FAILED], true))) {
                $toolCallIds[$composedViewBinding->target] = true;
            }
            return;
        }
        foreach ($value as $nestedValue) {
            self::collectFromBindingToolCallIds($nestedValue, $trustsStoredSnapshots, $toolCallIds);
        }
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
