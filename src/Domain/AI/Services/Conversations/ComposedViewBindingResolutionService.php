<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindingResolution;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewToolCallResult;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewReadableEntityRows;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewToolCallResults;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceResolverClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceRow;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;
use WeakMap;

/**
 * The `$from` resolution of `ComposedView` blocks when their row is stored. {@see AIConversationMessagesService} calls it in both persist paths — `persistMessageUnderRun()` (the
 * model's own rows, re-writes, transient eval conversations) and the widget-row writer (tool and platform rows,
 * `appendWidget()` → `persistNewRowUnderRunClaim()`) — right BEFORE the row is written and therefore before its
 * realtime publication.
 *
 * Two entry points:
 * - {@see resolveBindingsOfRowBeforePersist()} — the row's own blocks: every `$from` binding and `openEntity`
 *   reference resolved against the stored results of the tool calls of the SAME conversation, by tool call id
 *   ({@see ComposedViewBindingResolution} — the rules, the states, the snapshot form). A binding to a call of the same
 *   step is `pending`: the loop stores the step's prose before it dispatches the step's calls.
 * - {@see findRowsResolvedByStoredRow()} — after a row is stored: a call row that now carries its result, or the head
 *   of a later step, re-resolves the pending bindings of the turn's loaded rows; the caller writes the changed rows.
 *
 * The calls are looked up by conversation + tool call id, never through the context window or
 * `findWidgetAndReactionRows()`: first in the loaded rows, then — only for an id not loaded, e.g. the call row was
 * compacted out of the loaded window — in the stored rows ({@see findStoredToolCallResults()}). A row without a
 * `ComposedView` block costs one substring check and no query. A stored row is never re-resolved on read: a replay
 * reads the snapshot.
 */
class ComposedViewBindingResolutionService extends Service
{
    /** @var string[] The statuses of a call that ran to its end — its row now carries the result a binding reads. */
    public const array ENDED_TOOL_CALL_STATUSES = [AIConversationMessage::STATUS_COMPLETED, AIConversationMessage::STATUS_FAILED, AIConversationMessage::STATUS_CANCELLED];

    /** @var WeakMap<AIConversationMessage, true>|null The rows of transient conversations resolved once (they have no stored id to tell). */
    protected static ?WeakMap $resolvedTransientRows = null;

    /**
     * Resolves the `$from` bindings and `openEntity` references of the row's `ComposedView` blocks in place; a row
     * without such a block is left untouched (no query). A row seen for the first time is resolved fresh — a snapshot
     * the model typed into its block is dropped first. Before that, a `Widget` node the tree does not embed is lifted
     * out of the block into a block of its own after it ({@see liftWidgetNodesOutOfRowText()}).
     */
    public function resolveBindingsOfRowBeforePersist(AIConversationMessage $message): void
    {
        if (!self::isResolvableRow($message)) {
            return;
        }
        $this->liftWidgetNodesOutOfRowText($message);
        $conversation = isset($message->aiConversation) ? $message->aiConversation : null;
        $isStoredRow = $this->isStoredRow($message);
        $this->resolveRowText($message, $conversation, $isStoredRow, $this->isHostStepOver($message, $conversation));
        $this->rememberResolvedRow($message);
    }

    /**
     * Rewrites the row's text so every `Widget` node a `ComposedView` tree does not embed (not embeddable, or too large for
     * its slot) stands as its own widget block after the view, and a view the lift emptied is gone
     * ({@see WidgetBlocks::buildMarkdownWithLiftedWidgetBlocks()}): the owner never sees a title over
     * an empty Grid, and the lifted widget is a top-level shown widget (awaiting input, answer readers). The
     * model reads the rewritten row from its next call on. Pure; idempotent — a rewritten text lifts nothing.
     */
    public function liftWidgetNodesOutOfRowText(AIConversationMessage $message): void
    {
        $liftedText = WidgetBlocks::fromMarkdown($message->message)->buildMarkdownWithLiftedWidgetBlocks();
        if ($liftedText !== $message->message) {
            $message->message = $liftedText;
        }
    }

    /**
     * The rows whose pending bindings the just stored `$storedRow` resolves: when it is a call row with its result, or
     * the head of a model step (a step after a pending row's step is over — a call it names that is still unknown
     * will not come), every loaded row of the current turn with a pending `ComposedView` binding is resolved again.
     * The rows are changed in memory; the caller writes them. In-memory only — no query unless a pending row names a
     * call the loaded rows do not hold.
     *
     * @return AIConversationMessage[]
     */
    public function findRowsResolvedByStoredRow(AIConversationMessage $storedRow): array
    {
        $storedToolCall = $storedRow->messageType === AIConversationMessage::MESSAGE_TOOL_CALL ? ($storedRow->toolCall ?? null) : null;
        $storedRowEndsACall = $storedToolCall !== null && in_array($storedToolCall->status ?? null, self::ENDED_TOOL_CALL_STATUSES, true);
        $storedRowStartsAStep = ($storedRow->inputTokens ?? 0) > 0;
        if (!$storedRowEndsACall && !$storedRowStartsAStep) {
            return [];
        }
        $conversation = isset($storedRow->aiConversation) ? $storedRow->aiConversation : null;
        return $conversation !== null ? $this->resolvePendingRowsOfCurrentTurn($conversation, $storedRow, false) : [];
    }

    /**
     * The rows whose pending bindings end at a turn boundary — the turn's end on any exit (final text, a text-less end,
     * a budget or error ending, an owner report) and the next turn's start (a turn whose claim was lost ended without
     * its own pass): every loaded row with a pending `ComposedView` binding is resolved once more, an id no row carries
     * now fails, and a call that ran to its end in the meantime (a delegation whose result was written into its row
     * later) resolves. A call still running stays pending. The rows are changed in memory; the caller writes them.
     *
     * @return AIConversationMessage[]
     */
    public function findRowsResolvedAtTurnBoundary(AIConversation $conversation): array
    {
        if (!isset($conversation->messages)) {
            return [];
        }
        $resolvedRows = [];
        foreach ($conversation->messages->getElements() as $loadedRow) {
            if (!self::textCarriesPendingComposedViewBinding($loadedRow)) {
                continue;
            }
            if ($this->resolveRowText($loadedRow, $conversation, true, true)) {
                $resolvedRows[] = $loadedRow;
            }
        }
        return $resolvedRows;
    }

    /**
     * Resolves again every loaded row of the current turn (except `$skippedRow`) that holds a pending `ComposedView`
     * binding; returns the rows whose text changed. In-memory: a query only for an id the loaded rows do not hold.
     *
     * @return AIConversationMessage[]
     */
    protected function resolvePendingRowsOfCurrentTurn(AIConversation $conversation, ?AIConversationMessage $skippedRow, bool $turnIsOver): array
    {
        if (!isset($conversation->messages)) {
            return [];
        }
        $resolvedRows = [];
        foreach ($conversation->messages->buildCurrentTurn()->getRows() as $turnRow) {
            if ($turnRow === $skippedRow || !self::textCarriesPendingComposedViewBinding($turnRow)) {
                continue;
            }
            if ($this->resolveRowText($turnRow, $conversation, true, $turnIsOver || $this->isHostStepOver($turnRow, $conversation))) {
                $resolvedRows[] = $turnRow;
            }
        }
        return $resolvedRows;
    }

    /** Whether the row's text holds a `ComposedView` block at all — the one check every stored row pays. */
    public static function textCarriesComposedView(AIConversationMessage $message): bool
    {
        $messageText = $message->message ?? null;
        return is_string($messageText) && str_contains($messageText, ComposedViewConversationWidget::WIDGET_TYPE);
    }

    /**
     * Whether the row is one the resolution writes into: an ASSISTANT-role row (the model's text, a tool's or the
     * platform's widget row) holding a `ComposedView` block. An owner's row or a signal that quotes the owner's words
     * keeps its bytes — the customer's text is never resolved.
     */
    public static function isResolvableRow(AIConversationMessage $message): bool
    {
        return ($message->role ?? null) === AIConversationMessage::ROLE_ASSISTANT && self::textCarriesComposedView($message);
    }

    /** Whether the row's text holds a `ComposedView` block with a binding or reference still waiting for a call. */
    public static function textCarriesPendingComposedViewBinding(AIConversationMessage $message): bool
    {
        if (!self::isResolvableRow($message)) {
            return false;
        }
        return str_contains($message->message, '"state":"' . ComposedViewBindingResolution::STATE_PENDING . '"')
            || str_contains($message->message, '"refState":"' . ComposedViewBindingResolution::STATE_PENDING . '"');
    }

    /**
     * Resolves the row's `ComposedView` blocks and writes the changed blocks into its text. True when the text changed.
     */
    protected function resolveRowText(AIConversationMessage $message, ?AIConversation $conversation, bool $trustsStoredSnapshots, bool $hostStepIsOver): bool
    {
        $widgetBlocks = WidgetBlocks::fromMarkdown($message->message);
        $replacementWidgetJsonsByOrdinal = [];
        foreach ($widgetBlocks->getElements() as $widgetBlock) {
            $replacementWidgetJson = $this->buildResolvedWidgetJson($widgetBlock, $conversation, $trustsStoredSnapshots, $hostStepIsOver);
            if ($replacementWidgetJson !== null) {
                $replacementWidgetJsonsByOrdinal[$widgetBlock->ordinal] = $replacementWidgetJson;
            }
        }
        if ($replacementWidgetJsonsByOrdinal === []) {
            return false;
        }
        $resolvedText = $widgetBlocks->buildMarkdownWithWidgetJsonsReplaced($replacementWidgetJsonsByOrdinal);
        if ($resolvedText === $message->message) {
            return false;
        }
        $message->message = $resolvedText;
        return true;
    }

    /** The block's JSON with its bindings resolved, or null when it is no valid `ComposedView` block or nothing changed. */
    protected function buildResolvedWidgetJson(WidgetBlock $widgetBlock, ?AIConversation $conversation, bool $trustsStoredSnapshots, bool $hostStepIsOver): ?string
    {
        if ($widgetBlock->outcome !== WidgetBlock::OUTCOME_VALID || !$widgetBlock->isOfType(ComposedViewConversationWidget::WIDGET_TYPE)) {
            return null;
        }
        try {
            $widgetFields = json_decode($widgetBlock->widgetJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($widgetFields)) {
            return null;
        }
        $namedToolCallIds = ComposedViewBindingResolution::findNamedToolCallIds($widgetFields, $trustsStoredSnapshots);
        if ($namedToolCallIds === [] && $trustsStoredSnapshots) {
            return null;
        }
        $composedViewBindingResolution = new ComposedViewBindingResolution();
        $composedViewBindingResolution->trustsStoredSnapshots = $trustsStoredSnapshots;
        $composedViewBindingResolution->hostStepIsOver = $hostStepIsOver;
        // the widget layer's key of the conversation, asked for every conversation: one without a space gets the
        // service's account-level answer (no key by default)
        $composedViewBindingResolution->widgetSelfHydrationKey = $conversation !== null
            ? $this->findWidgetSelfHydrationKeyOfConversation($conversation)
            : null;
        $composedViewBindingResolution->resolvedAt = gmdate('Y-m-d\TH:i:s\Z');
        $composedViewBindingResolution->toolCallResults = $this->findToolCallResults($conversation, $namedToolCallIds);
        $composedViewBindingResolution->readableEntityRows = $this->findReadableEntityRowsOfReferences($composedViewBindingResolution, $widgetFields);
        $resolvedFields = $composedViewBindingResolution->resolveWidgetFieldsWithinSourceBudget($widgetFields);
        if (!$composedViewBindingResolution->changedBlock) {
            return null;
        }
        $resolvedWidgetJson = json_encode($resolvedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        return is_string($resolvedWidgetJson) ? $resolvedWidgetJson : null;
    }

    /**
     * The key the widget blocks of `$conversation` hydrate themselves from, as the application answers it for the
     * conversation's space ({@see AgentSpacesService::findWidgetSelfHydrationKeyOfConversation()}).
     *
     * @return int|null null = no key: the conversation has no space, or its space gives none
     */
    protected function findWidgetSelfHydrationKeyOfConversation(AIConversation $conversation): ?int
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->findWidgetSelfHydrationKeyOfConversation($conversation);
    }

    /**
     * The calls the ids name, by id: the loaded call rows of the conversation first, then — for the ids not loaded —
     * the stored rows (one query, only when an id is missing and the conversation is stored).
     *
     * @param string[] $toolCallIds
     */
    protected function findToolCallResults(?AIConversation $conversation, array $toolCallIds): ComposedViewToolCallResults
    {
        $composedViewToolCallResults = new ComposedViewToolCallResults();
        if ($toolCallIds === [] || $conversation === null) {
            return $composedViewToolCallResults;
        }
        $wantedToolCallIds = array_flip($toolCallIds);
        $loadedRowIds = [];
        foreach (isset($conversation->messages) ? $conversation->messages->getElements() : [] as $loadedRow) {
            $loadedToolCall = $loadedRow->messageType === AIConversationMessage::MESSAGE_TOOL_CALL ? ($loadedRow->toolCall ?? null) : null;
            if ($loadedToolCall === null || !isset($wantedToolCallIds[(string)($loadedToolCall->toolCallId ?? '')])) {
                continue;
            }
            $composedViewToolCallResults->addToolCallResult(ComposedViewToolCallResult::fromToolCall($loadedToolCall));
            if (isset($loadedRow->id)) {
                $loadedRowIds[(int)$loadedRow->id] = true;
            }
        }
        $toolCallIdsNotLoaded = array_values(array_filter($toolCallIds, static fn (string $toolCallId): bool => !$composedViewToolCallResults->knowsToolCallId($toolCallId)));
        if ($toolCallIdsNotLoaded === [] || !isset($conversation->id)) {
            return $composedViewToolCallResults;
        }
        foreach ($this->findStoredToolCallResults($conversation, $toolCallIdsNotLoaded) as $storedRowId => $composedViewToolCallResult) {
            if (!isset($loadedRowIds[$storedRowId])) {
                $composedViewToolCallResults->addToolCallResult($composedViewToolCallResult);
            }
        }
        return $composedViewToolCallResults;
    }

    /**
     * The stored call rows of the conversation carrying the ids, by message id — the lookup outside the loaded window
     * (the method the tests override).
     *
     * @param string[] $toolCallIds
     * @return ComposedViewToolCallResult[]
     */
    protected function findStoredToolCallResults(AIConversation $conversation, array $toolCallIds): array
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService->findToolCallResultsByToolCallIds($conversation, $toolCallIds);
    }

    /**
     * The rows the block's `openEntity` references name that are readable for the conversation's space, one per
     * reference — each asked of its type's resolver ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceResolver::isReadableForWidgetSelfHydrationKey()},
     * the domain's read rights). A type without a resolver is not readable.
     *
     * @param array<mixed> $widgetFields
     */
    protected function findReadableEntityRowsOfReferences(ComposedViewBindingResolution $composedViewBindingResolution, array $widgetFields): ComposedViewReadableEntityRows
    {
        $composedViewReadableEntityRows = new ComposedViewReadableEntityRows();
        foreach (ComposedViewBindingResolution::findOpenEntityReferences($widgetFields, $composedViewBindingResolution->trustsStoredSnapshots) as $entityReference) {
            if ($composedViewReadableEntityRows->hasRowOfReference($entityReference)) {
                continue;
            }
            $entityReferenceRow = $composedViewBindingResolution->findEntityRowNamedByReference($entityReference);
            if ($entityReferenceRow !== null && $this->isEntityRowReadable($entityReferenceRow, $composedViewBindingResolution->widgetSelfHydrationKey)) {
                $composedViewReadableEntityRows->addRowOfReference($entityReference, $entityReferenceRow);
            }
        }
        return $composedViewReadableEntityRows;
    }

    /** Whether the entity row is readable for the space — its type's resolver asked; a resolver that throws says no. */
    protected function isEntityRowReadable(EntityReferenceRow $entityReferenceRow, ?int $widgetSelfHydrationKey): bool
    {
        $resolverClass = EntityReferenceResolverClasses::getResolverClassForEntityType($entityReferenceRow->entityType);
        if ($resolverClass === null) {
            return false;
        }
        try {
            return (new $resolverClass())->isReadableForWidgetSelfHydrationKey($entityReferenceRow, $widgetSelfHydrationKey);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether a later model step started after the row: a loaded row above it carries a step's usage stamp
     * (`inputTokens > 0`, the step-boundary marker). Then a call id the conversation does not know will not come.
     */
    protected function isHostStepOver(AIConversationMessage $message, ?AIConversation $conversation): bool
    {
        if ($conversation === null || !isset($conversation->messages) || !isset($message->indexInConversation)) {
            return false;
        }
        foreach ($conversation->messages->getElements() as $loadedRow) {
            if ($loadedRow !== $message && ($loadedRow->indexInConversation ?? -1) > $message->indexInConversation && ($loadedRow->inputTokens ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the row is stored already — its snapshots are the platform's: a row of a stored conversation with its
     * id, or a row of a transient conversation this process resolved once.
     */
    protected function isStoredRow(AIConversationMessage $message): bool
    {
        if (($message->aiConversationId ?? null) !== null) {
            return isset($message->id);
        }
        return isset(self::$resolvedTransientRows[$message]);
    }

    /** Records a resolved row of a transient conversation (a stored one tells by its id). */
    protected function rememberResolvedRow(AIConversationMessage $message): void
    {
        if (($message->aiConversationId ?? null) !== null) {
            return;
        }
        self::$resolvedTransientRows ??= new WeakMap();
        self::$resolvedTransientRows[$message] = true;
    }
}
