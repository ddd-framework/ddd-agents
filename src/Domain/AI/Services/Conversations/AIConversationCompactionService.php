<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionExposedNodesByLevel;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionConsistencyReport;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationMessageFootprints;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionLeafRanges;
use DDD\Domain\AI\Entities\Conversations\Compaction\CompactionBudgets;
use DDD\Domain\AI\Entities\Conversations\Compaction\CompactionModelLimits;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionSummary;
use DDD\Domain\AI\MessageHandlers\AIConversationCompactionMessage;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIConversationCompaction;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Throwable;

/**
 * Drives conversation compaction — the `bool $async` seam. **Sync** (`async:false`) runs the compaction
 * INLINE in the current process: it's the path an application's agent console command and its eval
 * suite take, exactly like delegations stay inline there. **Async** (`async:true`) dispatches a Messenger job for the
 * HTTP/admin path. The pure planning logic (anchor selection, span, input assembly, VO build) lives ON
 * {@see AIConversation}; this service only resolves config + runs the cheap-model summarizer + persists.
 */
class AIConversationCompactionService extends Service
{
    use AITraceLogTrait;

    /** {@see CompactionModelLimits::DEFAULT_EFFECTIVE_CONTEXT_TOKENS} — the 100k fallback and ceiling, kept here for its readers. */
    public const int DEFAULT_EFFECTIVE_CONTEXT_TOKENS = CompactionModelLimits::DEFAULT_EFFECTIVE_CONTEXT_TOKENS;

    /**
     * The `bool $async` seam. Async dispatches the Messenger job; sync runs the compaction inline (the CLI/eval path —
     * this is what makes sync ephemeral compaction work).
     */
    public function compactConversation(AIConversation $conversation, bool $async = false): void
    {
        if ($async) {
            (new AIConversationCompactionMessage((int)$conversation->id))->dispatch();
            return;
        }
        $this->runCompactionTreeRound($conversation);
    }

    /** Called by the loop AFTER a turn completes: compact only if the per-conversation / global threshold is met. */
    public function compactConversationIfDue(AIConversation $conversation, bool $async = false): void
    {
        $effectiveContextTokens = $conversation->findCompactionModelLimits()->effectiveContextTokens;
        // The threshold ACTUALLY applied (per-conversation triggerTokens override, else the model/global fallback) — so
        // the trace logs the real number, not the fallback that a low per-conversation override silently replaces.
        $effectiveThreshold = $conversation->getEffectiveCompactionThreshold($effectiveContextTokens);
        if (!$conversation->shouldConsiderCompaction($effectiveContextTokens)) {
            $this->aiTrace('compaction.notDue', ['conversationId' => $conversation->id ?? null, 'tokens' => $conversation->getLatestContextInputTokens(), 'threshold' => $effectiveThreshold]);
            return;
        }
        $this->aiTrace('compaction.due', ['conversationId' => $conversation->id ?? null, 'tokens' => $conversation->getLatestContextInputTokens(), 'threshold' => $effectiveThreshold]);
        $this->compactConversation($conversation, $async);
    }

    /**
     * A purpose's declared context budget clamped to what a model can carry
     * ({@see CompactionModelLimits::clampContextBudget()}: 80 % of its hard window, no 100k ceiling) — for a model NAME
     * that need not be a conversation's (the purposed start, the eval). An empty or unknown name → the budget as declared.
     */
    public function clampContextBudgetToModelWindow(int $contextBudgetTokens, ?string $aiModelName): int
    {
        $aiModel = null;
        if ($aiModelName !== null && $aiModelName !== '') {
            /** @var AIModelsService $aiModelsService */
            $aiModelsService = DDDService::instance()->getService(AIModelsService::class);
            $aiModel = $aiModelsService->getAIModelByName($aiModelName);
        }
        return CompactionModelLimits::forModel($aiModel)->clampContextBudget($contextBudgetTokens);
    }

    /**
     * One round of hierarchical (RAPTOR-style) compaction. (1) Close every ready
     * level-0 leaf segment — pure planning picks the contiguous settled range, the cheap model summarizes it, the
     * node is stamped + persisted (INSERT, UNIQUE-deduped for at-least-once); the in-memory node set advances so the
     * next leaf starts at the new prefix edge. (2) Cascade roll-ups bottom-up: at each level, the oldest contiguous K
     * exposed nodes beyond retention are consolidated into one higher node. Leaves are written BEFORE roll-ups, so a
     * child always has an earlier `created` time than its parent (the bi-temporal invariant the point-in-time read needs).
     * Best-effort: a model that returns nothing simply ends the round (the cover stays as-is); never blocks the turn.
     */
    protected function runCompactionTreeRound(AIConversation $conversation): void
    {
        // Load the tree + the round's messages BEFORE sizing the budget: buildCompactionBudgets reads the
        // message footprint, and on the async path `messages` is unset → the #[LazyLoad] default returns only the ~50
        // OLDEST messages (AIConversationAgentService:291-292/:978-980), which would size the budget AND the whole
        // round off the wrong turns (the round compacts the oldest 50 of a long conversation and stops). Nodes first
        // (the cover edge needs them), then the settle-able tail past that edge.
        $this->ensureCompactionNodesLoaded($conversation);
        $this->ensureRoundMessagesLoaded($conversation);

        // The round's settings: the token budget derived from the ABSOLUTE threshold — the model's
        // effectiveContextTokens, or the per-conversation triggerTokens override — or the message-count fallback on a
        // degenerate budget; the leaf ranges planned in one pass, the roll-up width and the per-level retention.
        $compactionRoundSettings = $conversation->buildCompactionRoundSettings($conversation->findCompactionModelLimits()->effectiveContextTokens);

        // (1) close all ready level-0 leaf segments.
        $nodesLandedThisRound = $this->closeLeafRanges($conversation, $compactionRoundSettings->leafRanges, $compactionRoundSettings->messageFootprints);

        // (2) cascade roll-ups bottom-up — shared with the finalize completion pass.
        $nodesLandedThisRound += $this->cascadeRollUps($conversation, $compactionRoundSettings->childNodesPerRollUp, $compactionRoundSettings->retainedExposedNodesPerLevel);

        // After a compaction landing, let the SHARED staleness-gated refresh decide — the settle-path
        // refresh usually already covered the newest index, so generateAndPersistSummary SKIPS here (marker
        // not stale) and the former per-compaction re-summary model call is saved; it runs only when the compaction
        // caught up on messages no summary covered yet (e.g. the finalize completion pass over an idle backlog). The
        // manuallyEdited latch + the unset/re-fire mechanics live INSIDE generateAndPersistSummary now. Run SYNC
        // inline — this handler already holds the ENVELOPE_AI_CONVERSATION_COMPACTION envelope the summarizer needs.
        // Best-effort: a summary failure must never break the compaction round. Skip entirely when NO node
        // landed this round.
        if ($nodesLandedThisRound > 0) {
            try {
                /** @var \DDD\Domain\AI\Services\Conversations\AIConversationsService $aIConversationsService */
                $aIConversationsService = AIConversation::getService();
                $aIConversationsService->generateAndPersistSummary($conversation, async: false);
            } catch (Throwable $summaryError) {
                // best-effort: leave the current summary — but LOG (no silent compaction-path
                // swallows; the finalize twin below documents why).
                \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->error(
                    "AIConversationCompactionService: round summary refresh failed for conversation {$conversation->id}: "
                    . $summaryError->getMessage()
                );
            }
        }
        $this->aiTrace('compaction.done', ['conversationId' => $conversation->id ?? null]);
    }

    /**
     * Close the given level-0 leaf ranges (facts → summarize → build → embed → persist), advancing the in-memory
     * prefix edge per persist. Shared by the threshold round and the finalize completion pass. Stops on the
     * first unusable model result (retried on the next round/dispatch). Returns the number of nodes landed.
     *
     */
    protected function closeLeafRanges(AIConversation $conversation, AIConversationCompactionLeafRanges $leafRanges, ?AIConversationMessageFootprints $footprints): int
    {
        $nodesLanded = 0;
        foreach ($leafRanges as $leafRange) {
            // Extract the preserved facts ONCE per leaf and thread them into BOTH the summarizer input and the node — the
            // identical extraction over the same range used to run twice (input + node). (The range fetch + sort underneath
            // is memoized in AIConversationMessages::getRowsSortedByIndex, so getRowsInIndexRange does not re-sort the tail.)
            $preservedFacts = $conversation->buildPreservedFactsForRange($leafRange->fromIndex, $leafRange->toIndex);
            $compactionSummary = $this->summarizeForCompaction($conversation, $conversation->buildLeafCompactionInput($leafRange->fromIndex, $leafRange->toIndex, $preservedFacts));
            if ($compactionSummary->isEmpty()) {
                // Stop this pass, don't spin — but LOG (traceability): a silently
                // unclosed leaf leaves the conversation uncompacted with zero signal — repeated empty summarizer
                // results ARE the "compaction never lands" failure mode.
                $this->logCompactionAnomaly(
                    $conversation,
                    "compaction summarizer returned EMPTY for conversation " . ($conversation->id ?? 'transient')
                    . " leaf range [{$leafRange->fromIndex}..{$leafRange->toIndex}] (model " . ($compactionSummary->aiModelName ?? '?')
                    . ') — leaf pass stopped (roll-ups still run), '
                    . (count($leafRanges) - $nodesLanded) . ' planned leaf range(s) left unclosed for this pass.'
                );
                break;
            }
            // The degenerate fallback plans its ranges without footprints: they are built at the first leaf that lands.
            $footprints ??= $conversation->buildMessageFootprints();
            $leafNode = AIConversationCompactionNode::buildLeafFor($conversation, $leafRange, $compactionSummary, $footprints, $preservedFacts);
            $this->embedCompactionNode($leafNode);
            $this->persistCompactionNode($conversation, $leafNode);
            $nodesLanded++;
        }
        return $nodesLanded;
    }

    /**
     * Cascade roll-ups bottom-up over the exposed frontier: compute the frontier for ALL levels ONCE,
     * then MAINTAIN it incrementally — after rolling the oldest K at level L into a parent at L+1, drop those K from
     * level L's exposed list and append the parent to L+1's. Equivalent to the per-call recompute
     * (RollUpFrontierIncrementalEqualsRecomputeTest), O(M) once for the whole phase. `$levelRetention` 0 (the finalize
     * completion pass) rolls everything groupable; the threshold round keeps its recent-fine retention.
     * Returns the number of roll-up nodes landed.
     */
    protected function cascadeRollUps(AIConversation $conversation, int $fanIn, int $levelRetention): int
    {
        $nodesLanded = 0;
        $exposedNodesByLevel = $conversation->compactionNodes?->buildExposedNodesByLevel() ?? AIConversationCompactionExposedNodesByLevel::fromNodesByLevel([]);
        for ($level = 0; $level < CompactionBudgets::MAX_TREE_LEVELS; $level++) {
            $rolledUpAtThisLevel = false;
            while (($rollUpGroup = $exposedNodesByLevel->buildNextRollUpGroupAtLevel($level, $fanIn, $levelRetention)) !== null) {
                $compactionSummary = $this->summarizeForCompaction($conversation, $conversation->buildRollUpCompactionInput($rollUpGroup));
                if ($compactionSummary->isEmpty()) {
                    // Same traceability rule as the leaf pass: an empty roll-up summary stops the level silently
                    // otherwise — log so the stall is visible in the runtime-errors pipeline.
                    $this->logCompactionAnomaly(
                        $conversation,
                        "compaction summarizer returned EMPTY for conversation " . ($conversation->id ?? 'transient')
                        . " roll-up at level {$level} (" . $rollUpGroup->countChildNodes() . ' child nodes, model '
                        . ($compactionSummary->aiModelName ?? '?') . ') — roll-up cascade stopped at this level for this pass.'
                    );
                    break;
                }
                $rollUpNode = AIConversationCompactionNode::buildRollUpOf($rollUpGroup, $compactionSummary);
                $this->embedCompactionNode($rollUpNode);
                $this->persistCompactionNode($conversation, $rollUpNode);
                $nodesLanded++;
                $rolledUpAtThisLevel = true;
                // Incremental frontier update: the rolled K children are now covered by $rollUpNode → they leave level L;
                // the parent is the newest exposed node at L+1.
                $exposedNodesByLevel->replaceRolledUpGroupWithParent($rollUpGroup, $rollUpNode);
            }
            if (!$rolledUpAtThisLevel) {
                break; // nothing rolled at this level → no new input for the level above
            }
        }
        return $nodesLanded;
    }

    /** Below this many messages a finalized conversation gets NO tree (the summary alone carries it) — */
    public const int DEFAULT_FINALIZE_MIN_MESSAGES = 6;

    /**
     * The COMPLETION compaction (the idle finalize): cover the conversation to its LAST message — threshold
     * IGNORED (runs far below it), NO raw-tail reserve, the trailing partial leaf closed
     * ({@see AIConversation::buildLeafRangesToEnd}), roll-ups with retention 0 (everything groupable rolls). After a
     * resume, a re-run closes only the range from the last anchor to the new end — idempotent by construction
     * (append-only tree, UNIQUE-deduped inserts; a re-run over a fully-covered conversation finds no range and lands
     * nothing). Loads the frontier + the uncovered tail itself; callable standalone (CLI probe) or via
     * {@see finalizeConversationCompaction}.
     */
    public function compactConversationToCompletion(AIConversation $conversation): int
    {
        $this->ensureCompactionNodesLoaded($conversation);
        $this->ensureRoundMessagesLoaded($conversation);
        $budgets = $conversation->buildCompactionBudgets($conversation->findCompactionModelLimits()->effectiveContextTokens);
        $footprints = $conversation->buildMessageFootprints();
        $nodesLanded = $this->closeLeafRanges($conversation, $conversation->buildLeafRangesToEnd($budgets, $footprints), $footprints);
        $nodesLanded += $this->cascadeRollUps($conversation, $conversation->getChildNodesPerCompactionRollUp($budgets), 0);
        $this->aiTrace('compaction.completion', ['conversationId' => $conversation->id ?? null, 'nodesLanded' => $nodesLanded]);
        return $nodesLanded;
    }

    /**
     * The finalize worker's whole chain: completion compaction (skipped below `$finalizeMinMessages` — a
     * 3-message chat needs no tree, its summary carries it) → the staleness-gated summary refresh (the FINAL
     * outcome/importance over everything) → stamp `finalizeCompactedAt`. Idempotent per `finalizedAt` generation:
     * the compact-finalized sweep selects only rows whose stamp is older than their finalizedAt.
     */
    public function finalizeConversationCompaction(AIConversation $conversation, int $finalizeMinMessages = self::DEFAULT_FINALIZE_MIN_MESSAGES): void
    {
        $this->ensureCompactionNodesLoaded($conversation);
        $this->ensureRoundMessagesLoaded($conversation);
        // Total message count = highest index + 1 (indexes are 0-based and the loaded tail always contains the newest
        // message; covered messages count via the index, not the loaded-row count).
        $totalMessageCount = $conversation->messages->getNextIndex();
        if ($totalMessageCount >= $finalizeMinMessages) {
            $this->compactConversationToCompletion($conversation);
        } else {
            $this->aiTrace('compaction.completion.skippedTooShort', ['conversationId' => $conversation->id ?? null, 'messages' => $totalMessageCount]);
        }
        // The FINAL summary for the episodic layer — staleness-gated (a summary already covering the newest index is
        // NOT re-generated), best-effort like every summary refresh.
        try {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->generateAndPersistSummary($conversation, async: false);
        } catch (Throwable $summaryError) {
            // best-effort: the completion tree landed; the summary retries on the next refresh occasion. LOGGED as an
            // error — a silently missing summary starves the episodic layer and is otherwise undiagnosable (the first
            // live rollout lost every summary to a swallowed throw exactly here).
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->error(
                "AIConversationCompactionService: finalize summary refresh failed for conversation {$conversation->id}: "
                . $summaryError->getMessage()
            );
        }
        $conversation->finalizeCompactedAt = new \DDD\Infrastructure\Base\DateTime\DateTime();
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIConversationsService->persistFinalizeCompactedAt($conversation);
    }

    /**
     * ERROR-log one compaction anomaly WITHOUT ever throwing — the logger call itself is guarded, because the
     * empty-summary degradation branch must stay "stop quietly, re-plan on a later pass": on the sync CLI/eval
     * path an unguarded logger failure would abort the very agent turn this branch protects. A TRANSIENT
     * (ephemeral, id-less) conversation traces instead of error-logging — its empty summarizer result carries no
     * operator action, and every eval run would otherwise pollute the runtime-errors pipeline.
     */
    protected function logCompactionAnomaly(AIConversation $conversation, string $anomalyDescription): void
    {
        try {
            if (!isset($conversation->id)) {
                $this->aiTrace('compaction.anomaly', ['description' => $anomalyDescription]);
                return;
            }
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->error(
                'AIConversationCompactionService: ' . $anomalyDescription
            );
        } catch (Throwable) {
        }
    }

    /**
     * Run the cheap-model summarizer over a pre-built input (leaf raw transcript or K child summaries) and return the
     * gist + provenance. Mirrors the imperative Argus invocation in {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService::performModelCall()}.
     */
    protected function summarizeForCompaction(AIConversation $conversation, string $compactionInput): AIConversationCompactionSummary
    {
        $summarizer = new ArgusAIConversationCompaction();
        // setParent takes `?DefaultObject &$parent` BY-REF — a throwaway local keeps $conversation's AIConversation type.
        $summarizerParent = $conversation;
        $summarizer->setParent($summarizerParent);
        $summarizer->compactionInput = $compactionInput;
        $summarizer->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
        return AIConversationCompactionSummary::fromSummarizerOutput(
            trim($summarizer->summaryText),
            $summarizer->getAIModel()?->name,
            $summarizer->getLastOutputTokens()
        );
    }

    /**
     * Generate the node's semantic embedding from its summary text — the per-node key for the RAPTOR
     * "search within compactions" retrieval. Mirrors how the host embeds an account memory:
     * the shared {@see AITextEmbeddingsService} (Argus connects only in services), set BEFORE persist. A node is
     * immutable append-only, so this runs exactly once at creation. BEST-EFFORT: an embedding failure must NOT block
     * the node from being persisted (the gist is still useful to the compacted prompt).
     *
     * A node CANNOT be stored without a vector: `summaryEmbedding` is `vector(1536) NOT NULL` with the zero vector as
     * its default, so a node whose embedding failed is stored WITH THE ZERO DEFAULT — on purpose. The node search
     * excludes zero vectors (`!= zero vector`, a cosine distance on one raises an error), so such a node is only not
     * yet vector-searchable; the application's repair cron for zero embeddings re-embeds it later. The
     * failure is logged on the issues channel ({@see AITextEmbeddingsService::logEmbeddingFailure()}).
     */
    protected function embedCompactionNode(AIConversationCompactionNode $compactionNode): void
    {
        if (!isset($compactionNode->summaryText) || $compactionNode->summaryText === null || $compactionNode->summaryText === '') {
            return;
        }
        /** @var AITextEmbeddingsService $embeddingsService */
        $embeddingsService = DDDService::instance()->getService(AITextEmbeddingsService::class);
        try {
            $compactionNode->summaryEmbedding = $embeddingsService->generateEmbeddingForText($compactionNode->summaryText);
        } catch (\Throwable $embeddingError) {
            // the node is persisted with the zero-vector default (see the docblock) — the repair re-embeds it
            $embeddingsService->logEmbeddingFailure(
                $embeddingError,
                'compaction node of conversation ' . ($compactionNode->aiConversationId ?? '?')
                . ' stored with the zero-vector default (left out of the node search until repaired)',
                ['aiConversationId' => $compactionNode->aiConversationId ?? null, 'level' => $compactionNode->level ?? null]
            );
        }
    }

    /**
     * Add the node to the in-memory set (so the round's next planning call sees the advanced prefix edge / new exposed
     * node), and persist it on a persisted conversation. The write-time bi-temporal key is the ChangeHistory `created`
     * column, auto-stamped by the EM at INSERT (no manual stamp). The INSERT is UNIQUE-deduped: a concurrent /
     * redelivered roll-up that produced the SAME (conversation, level, range) hits the unique index — caught and
     * skipped (the canonical node already exists). Ephemeral (no id) keeps the in-memory node.
     */
    protected function persistCompactionNode(AIConversation $conversation, AIConversationCompactionNode $compactionNode): void
    {
        $this->aiTrace('compaction.node', [
            'conversationId' => $conversation->id ?? null,
            'level' => $compactionNode->level ?? null,
            'fromIndex' => $compactionNode->coveredFromMessageIndex ?? null,
            'toIndex' => $compactionNode->coveredToMessageIndex ?? null,
        ]);
        // add() takes `DefaultObject &$element` BY-REF — pass a throwaway local so $compactionNode keeps its
        // AIConversationCompactionNode type for ->update() below (PHPStan would otherwise widen it to BaseObject).
        $nodeToAdd = $compactionNode;
        $conversation->compactionNodes?->add($nodeToAdd);
        if (!isset($conversation->id)) {
            return; // ephemeral: in-memory tree only (feeds the next turn within this process)
        }
        try {
            // update(0) — INSERT-once leaf node; its only relation is addAsParent (the conversation),
            // cascade-skipped. Depth 0 writes all own columns (level, covered index range, summaryText, embedding).
            $compactionNode->update(0);
            $this->aiTrace('compaction.persisted', ['nodeId' => $compactionNode->id ?? null]);
        } catch (UniqueConstraintViolationException) {
            // dedup: an at-least-once redelivery / concurrent round already inserted this exact node. Harmless — the
            // in-memory copy is functionally identical (same range/level) and only advances this round's planning.
        }
    }

    /**
     * Make sure the conversation's compaction tree is loaded in-memory before a round (the cover/roll-up planning is
     * pure over {@see AIConversation::$compactionNodes}). Persisted → load the whole set in one indexed query;
     * ephemeral → an empty set the round appends to.
     */
    protected function ensureCompactionNodesLoaded(AIConversation $conversation): void
    {
        // Persisted: load the EXPOSED FRONTIER only — the ~log_K(N) nodes the round actually needs,
        // NOT all M ≈ Θ(N) nodes. The round only ever touches the frontier: the cover (a subset of the frontier) for
        // the leaf edge, the per-level exposed nodes for roll-up, and the child groups it rolls. Every deeper
        // already-rolled-up node is irrelevant. Safe to overwrite: runs once at round entry before any in-memory add,
        // and every persisted node is INSERTed before it is added in-memory (persistCompactionNode). (The live turn
        // path may have left $compactionNodes as the cover-only subset; this replaces it with the full frontier.)
        if (isset($conversation->id)) {
            $conversation->compactionNodes = AIConversationCompactionNodes::getService()->findExposedNodesForConversation($conversation);
            return;
        }
        // Ephemeral: the in-memory tree is maintained across the round (no DB rows to load). isset() — NOT !== null — a
        // #[LazyLoad] prop read via !== null triggers __get → a DB lazy-load against a non-existent table.
        if (!isset($conversation->compactionNodes)) {
            $conversation->compactionNodes = AIConversationCompactionNodes::newInstance();
        }
    }

    /**
     * VALIDATE the persisted compaction tree + the context build across ALL load paths (test tooling) — the
     * "does the DB build match the in-memory truth" check the live persisted suite asserts. Loads the conversation's
     * tree from DB three ways — the FULL set (the reference), the COVER-only read, the FRONTIER read — and the message indexes of the whole conversation and of the raw tail past the full-scan edge, then hands
     * them to {@see AIConversationCompactionConsistencyReport::buildFrom()}: the named invariants (each bounded loader
     * equals the full-scan reference, the cover tiles [0..edge], nothing is lost between cover and tail, the tree is
     * sound) and the efficiency counts (cover/frontier ≪ total nodes). Repo reads only, no model call; the conversation
     * is left untouched — its node set ({@see AIConversation::$compactionNodes}) stays the caller's.
     */
    public function validateCompactionConsistency(AIConversation $conversation): AIConversationCompactionConsistencyReport
    {
        $conversationId = (int)($conversation->id ?? 0);
        $nodesService = AIConversationCompactionNodes::getService();

        // FULL reference set — the full-scan cover, edge and frontier derive from it; never installed on the conversation.
        $fullNodes = $nodesService->findAllNodesForConversation($conversation) ?? AIConversationCompactionNodes::newInstance();
        $referenceEdge = $fullNodes->getCoveredUpToIndex(); // full-scan prefix edge

        // The two BOUNDED DB loaders the turn path / round actually use.
        $coverNodes = $nodesService->findCoverNodesForConversation($conversation);
        $frontierNodes = $nodesService->findExposedNodesForConversation($conversation);

        // The whole conversation's message indexes, and the raw tail past the full-scan edge.
        $allMessageIndexes = $this->collectSortedMessageIndexes($conversation->findMessagesInOrder(useEntityRegistrCache: false));
        $tailMessageIndexes = $this->collectSortedMessageIndexes($conversation->findRawTailMessagesInOrder($referenceEdge ?? -1, useEntityRegistrCache: false));

        return AIConversationCompactionConsistencyReport::buildFrom(
            $conversationId,
            $fullNodes,
            $coverNodes,
            $frontierNodes,
            $allMessageIndexes,
            $tailMessageIndexes
        );
    }

    /**
     * The `indexInConversation` values of the loaded messages, ascending; rows without an index are skipped.
     *
     * @return int[]
     */
    protected function collectSortedMessageIndexes(?AIConversationMessages $messages): array
    {
        $messageIndexes = [];
        foreach ($messages?->getElements() ?? [] as $message) {
            if (($message->indexInConversation ?? null) !== null) {
                $messageIndexes[] = (int)$message->indexInConversation;
            }
        }
        sort($messageIndexes);
        return $messageIndexes;
    }

    /**
     * Load the messages the round needs EXPLICITLY — everything past the cover edge (the settle-able
     * region the leaf walker summarizes + the raw tail). On the async path the conversation arrives bare
     * ({@see \DDD\Domain\AI\MessageHandlers\AIConversationCompactionHandler}) and the first `getSortedMessages()` would
     * otherwise trigger the `#[LazyLoad]` default = only the ~50 OLDEST messages, making the round compact the oldest
     * 50 of a long conversation and stop. coveredUpToIndex null (nothing compacted yet) → load from -1 = the whole
     * conversation (the first catch-up round is O(backlog); steady state is O(tail)). Runs AFTER
     * {@see self::ensureCompactionNodesLoaded()} (the cover edge needs the tree). Ephemeral (no id) keeps the in-memory
     * messages the eval/CLI caller maintains.
     */
    protected function ensureRoundMessagesLoaded(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return; // ephemeral: caller-maintained in-memory messages (eval/CLI sync path)
        }
        $coveredUpToIndex = $conversation->getCompactionCoveredUpToIndex();
        $roundMessages = $conversation->findRawTailMessagesInOrder($coveredUpToIndex ?? -1, useEntityRegistrCache: false);
        if ($roundMessages !== null) {
            $conversation->messages = $roundMessages;
        }
    }
}
