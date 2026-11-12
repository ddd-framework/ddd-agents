<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationSearchGroup;
use DDD\Domain\AI\Entities\Conversations\Recall\SemanticSearchHit;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Exceptions\Texts\Embeddings\TextEmbeddingNotGeneratedException;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusSearchRerank;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBConversationCompactionNodes;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\ArrayParameterType;
use Throwable;

/**
 * The ONE search primitive behind every conversation-recall surface: the REST search box, the global
 * "search all my chats" endpoint, and the agent recall tools (`recallConversation`, `searchConversations`, `searchMemory`).
 * They differ only in WHAT they search, the EXCERPT size, and the OUTPUT shape — the retrieval + fuse + rerank +
 * excerpt logic is shared HERE so a fix lands once for all of them.
 *
 * Pipeline — HYBRID multi-signal retrieval (the proven pattern: combine whichever signal a query needs), then LLM
 * precision. FOUR over-fetched candidate signals, all at MESSAGE granularity (the messages carry the recall signal —
 * conversation/node summaries are too generic/sparse to gate on, so nothing here FILTERS recall):
 *   (a) DENSE — cosine over each message's {@see \DDD\Domain\AI\Entities\Conversations\Messages\Embeddings\AIConversationMessageEmbedding} row (semantic);
 *   (b) NODE-region expansion — for each matched compaction node, its best in-range messages (bridges the vocabulary
 *       gap for COMPACTED regions; the summary is never returned — the unit is always a MESSAGE; a BONUS signal, only
 *       present where compaction ran, never a gate);
 *   (c) BM25/KEYWORD — FULLTEXT `MATCH(message) AGAINST(:q)` over `ft_message` (exact terms/names/ids the embedding
 *       misses, needs NO embedding → also covers un-embedded messages); ranked over the message ids, the rows are
 *       loaded in a second query;
 *   (d) RECENCY — a newest-first ranking OVER the retrieved union (nudges fresh-among-relevant; never injects).
 * → dedup by `messageId` → Reciprocal-Rank-Fusion the four rankings → {@see rerank()} (the LLM precision-ORDERING stage,
 * FAST_CHEAP, with a RECALL FLOOR so it can never empty the result) → trim to `limit` → window each excerpt.
 *
 * **DEBUGGING ("search returns nothing"):** do NOT curl the endpoint — run
 * the application's search inspection command (a console command that takes a conversation id and a query).
 * It drives THIS pipeline stage-by-stage (embedding-rows → raw vector, rights off/on → fused+reranked → grouped →
 * within-conv) and prints the hit count at each stage, so you see exactly where it drops to zero. It reproduces the two
 * non-obvious bits of the HTTP context that otherwise make a local repro lie: the read rights of the account, and the
 * `#[WithAIEnvelope]` (without an active envelope the {@see rerank()} fail-opens to vector order and HIDES a bad
 * reranker — the very bug that emptied the search box).
 */
class SemanticSearchService extends Service
{
    /** Over-fetch factor before the rerank/trim — vector recall casts wide, the rerank tightens to `limit`. RECALL
     *  queries are meta ("what did we discuss about X"), so the relevant CONTENT often ranks well outside the top few
     *  by raw vector distance; a wide candidate window is what lets the LLM rerank actually SEE and promote it (a
     *  small window starves the rerank — it can only order what it receives). FAST_CHEAP reranks short snippets, so a
     *  wider window is cheap. */
    public const int OVERFETCH_FACTOR = 8;

    /** Hard bound on the LLM rerank INPUT — the wide over-fetch (and the node-expansion fan-out) must NEVER flood the
     *  FAST_CHEAP reranker: at most this many candidates are sent. They arrive best-first (RRF / vector), so the cap
     *  keeps the most-promising ones. Bounds the call to ~MAX_RERANK_CANDIDATES × RERANK_CANDIDATE_CHARS chars. */
    public const int MAX_RERANK_CANDIDATES = 40;

    /** Per-candidate char cap fed to the rerank — a single GIGANTIC message/memory (a 20k-char tool result, a long
     *  SOLUTION) is windowed to a snippet around the query hit, NEVER sent whole. The reranker scores relevance, not
     *  content — a snippet is enough. */
    public const int RERANK_CANDIDATE_CHARS = 400;

    /** Messages pulled from EACH matched node's covered range (the node→messages resolution). */
    public const int MESSAGES_PER_NODE = 2;

    /** Default excerpt size for the lightweight UI path; the agent path passes a large value or 0 (full text). */
    public const int DEFAULT_EXCERPT_CHARS = 200;

    /** Cross-conversation search: max hits kept PER conversation, so the result spans several chats (E4) rather than
     *  being dominated by one. */
    public const int MAX_HITS_PER_CONVERSATION = 2;

    /**
     * Search ONE conversation. Returns {@see SemanticSearchHit}[] best-first. Empty when the conversation is
     * unpersisted, the query is blank, or nothing matches. `$turnRange` (a `[from, to]` index pair) optionally scopes
     * the direct-message search to a slice the user named ("at the start", "earlier").
     *
     * @param int[]|null $turnRange
     * @return SemanticSearchHit[]
     */
    public function searchWithinConversation(
        AIConversation $conversation,
        string $query,
        int $limit = 5,
        int $excerptChars = self::DEFAULT_EXCERPT_CHARS,
        ?array $turnRange = null
    ): array {
        if (!isset($conversation->id) || trim($query) === '') {
            return [];
        }
        $conversationId = (int)$conversation->id;
        // no query embedding → the vector legs find nothing, the keyword leg still answers
        $queryEmbedding = $this->embedQueryOrEmptyEmbedding($query);
        $overFetch = max($limit * self::OVERFETCH_FACTOR, $limit);

        // (a) direct message hits. A failing vector query (a bad row, the index) degrades to the other legs.
        [$turnFrom, $turnTo] = [$turnRange[0] ?? null, $turnRange[1] ?? null];
        try {
            $directMessages = $this->findMessagesSimilarWithinConversation($queryEmbedding, $conversationId, $overFetch, $turnFrom, $turnTo);
        } catch (Throwable $vectorSearchError) {
            $this->logVectorSearchFailure($vectorSearchError, 'search within conversation ' . $conversationId . ' runs without the message vector leg');
            $directMessages = [];
        }

        // (b) node-region expansion: each matched node → its best in-range messages (the summary itself is dropped).
        try {
            $nodeExpandedMessages = $this->expandNodeRegionsToMessages($queryEmbedding, $conversationId, $overFetch);
        } catch (Throwable $nodeSearchError) {
            $this->logVectorSearchFailure($nodeSearchError, 'search within conversation ' . $conversationId . ' runs without the compaction-node leg');
            $nodeExpandedMessages = [];
        }

        // (c) BM25/keyword leg — lexical complement (exact terms/names the embedding misses, no embedding needed).
        $keywordMessages = $this->findMessagesByKeywordWithinConversation($query, $conversationId, $overFetch, $turnFrom, $turnTo);

        // Build the messageId → message map + the ranked id-lists for fusion (dense + node + keyword + recency).
        $messagesById = $this->indexMessagesById($directMessages, $nodeExpandedMessages, $keywordMessages);
        $directRanking = $this->rankingIds($directMessages);
        $nodeRanking = $this->rankingIds($nodeExpandedMessages);
        $keywordRanking = $this->rankingIds($keywordMessages);
        $recencyRanking = $this->recencyRanking($messagesById);

        $fusedIds = $this->reciprocalRankFusion([$directRanking, $nodeRanking, $keywordRanking, $recencyRanking]);
        $fusedMessages = [];
        foreach ($fusedIds as $messageId) {
            if (isset($messagesById[$messageId])) {
                $fusedMessages[] = $messagesById[$messageId];
            }
        }

        $rankedMessages = $this->rerank($query, $fusedMessages, $conversation->account ?? null);
        $rankedMessages = array_slice($rankedMessages, 0, $limit);

        $hits = [];
        $rank = 0;
        $total = max(count($rankedMessages), 1);
        foreach ($rankedMessages as $message) {
            $hits[] = new SemanticSearchHit(
                messageId: isset($message->id) ? (int)$message->id : null,
                indexInConversation: $message->indexInConversation ?? null,
                role: $message->role ?? null,
                created: $message->getCreatedTime(),
                updated: $message->getModifiedTime(),
                excerpt: $this->windowExcerpt(AIConversationMessage::toSearchableText($message->message ?? ''), $query, $excerptChars),
                relevance: round(1.0 - ($rank / $total) * 0.5, 4), // graded by the rerank order (best-first)
            );
            $rank++;
        }
        return $hits;
    }

    /**
     * The LLM precision stage: rerank the vector candidates by true relevance to the query
     * via the FAST_CHEAP scope (fastest-provider, non-agentic, cheap — reranking N short candidates is light
     * classification, not generation, and the user waits on a search → latency-first). Only the first
     * {@see MAX_RERANK_CANDIDATES} candidates (best-first) are rendered and sent; the rest is dropped. When the call
     * fails, the input order of those candidates is returned unchanged.
     *
     * @param AIConversationMessage[] $candidates
     * @return AIConversationMessage[]
     */
    public function rerank(string $query, array $candidates, ?Account $budgetAccount = null): array
    {
        $candidates = array_slice(array_values($candidates), 0, self::MAX_RERANK_CANDIDATES);
        if (count($candidates) <= 1) {
            return $candidates;
        }
        // Feed the model a bounded snippet per candidate (cheap+fast: short classification, not generation).
        $texts = array_map(
            fn (AIConversationMessage $message): string => $this->windowExcerpt(AIConversationMessage::toSearchableText((string)($message->message ?? '')), $query, self::RERANK_CANDIDATE_CHARS),
            $candidates
        );
        $reordered = [];
        foreach ($this->rerankTextIndices($query, $texts, $budgetAccount) as $candidateIndex) {
            if (isset($candidates[$candidateIndex])) {
                $reordered[] = $candidates[$candidateIndex];
            }
        }
        return $reordered;
    }

    /**
     * The generic LLM precision primitive (shared by message search, memory search, both endpoints):
     * given the query + the candidate TEXTS (index-aligned), return the candidate indices the model judges RELEVANT,
     * best-first — and ONLY those. The model PRUNES the irrelevant tail (omitted indices are DROPPED, not re-appended),
     * which is what stops a diffuse query from returning a scatter of loosely-related hits. Routed via FAST_CHEAP.
     *
     * Safety: only a genuine CALL FAILURE (unparseable verdict / a thrown call) falls back to the full vector-recall
     * order, so a broken rerank can never silently empty the box. A PARSEABLE verdict is honoured EXACTLY — including an
     * empty one ("none of these are relevant" → empty result). Trusting the drop is safe now that the candidates are
     * LABELLED so the cheap model's order survives serialization (see {@see ArgusSearchRerank} — before that fix it
     * emitted [2013] for [2,0,1,3], which is exactly why the old code kept a recall floor that re-appended every drop).
     *
     * @param string[] $texts
     * @return int[]
     */
    public function rerankTextIndices(string $query, array $texts, ?Account $budgetAccount = null): array
    {
        $texts = array_values($texts);
        // Flood guard (applies to EVERY caller, message or memory): cap the candidate COUNT (keep the best-first head)
        // and hard-truncate each candidate so one giant message can't blow up the rerank call. The returned indices
        // map back to the caller's first MAX_RERANK_CANDIDATES candidates (best-first), which is exactly the window we
        // want the LLM to tighten.
        $texts = array_slice($texts, 0, self::MAX_RERANK_CANDIDATES);
        $texts = array_map(
            static fn (string $text): string => mb_substr($text, 0, self::RERANK_CANDIDATE_CHARS),
            $texts
        );
        $count = count($texts);
        if ($count <= 1) {
            return array_keys($texts);
        }
        try {
            $rerank = new ArgusSearchRerank();
            $rerank->query = $query;
            $rerank->candidateTexts = $texts;
            $rerank->budgetAccount = $budgetAccount;
            $rerank->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
            if (!$rerank->reranked) {
                return range(0, $count - 1); // the call FAILED / was unparseable → vector recall order holds (never empty)
            }
            // A parseable verdict IS the precision decision — honour it EXACTLY: the model's relevant picks, best-first,
            // and NOTHING else. Candidates it omitted are judged irrelevant and are DROPPED (not re-appended) — that is
            // what prunes the long tail of loosely-related hits a diffuse query otherwise scatters. An empty verdict
            // ("none relevant") legitimately yields an empty result; only a genuine call failure (above + the catch)
            // falls back to vector order. Safe because the candidates are LABELLED so the order survives serialization.
            $order = [];
            $taken = [];
            foreach ($rerank->rankedIndices as $candidateIndex) {
                if ($candidateIndex >= 0 && $candidateIndex < $count && !isset($taken[$candidateIndex])) {
                    $order[] = $candidateIndex;
                    $taken[$candidateIndex] = true;
                }
            }
            return $order;
        } catch (Throwable) {
            return range(0, $count - 1); // graceful: vector recall already holds, the rerank is precision-only
        }
    }

    /**
     * Search the ACCOUNT's persistent memories (the `search_memory` tool) — the SAME primitive over a
     * different store: vector recall (the memory component's similar memories, {@see AgentMemoriesService::findSimilarMemories()};
     * none without a memory component) → FAST_CHEAP rerank → windowed excerpts. The
     * agent path passes a large `$excerptChars` (0 = full memory text). Returns {@see SemanticSearchHit}[] best-first;
     * the memory TYPE rides in the hit's `role` slot, the memory text in the excerpt.
     * `$agentSpace` is the space the search runs for: the memories that apply to it are searched; without a space the
     * search runs at account level and reads the account-wide memories only — never those of the account's spaces.
     *
     * @return SemanticSearchHit[]
     */
    public function searchMemory(
        int $accountId,
        ?AgentSpace $agentSpace,
        string $query,
        int $limit = 5,
        int $excerptChars = 0,
        ?Account $budgetAccount = null
    ): array {
        if (trim($query) === '') {
            return [];
        }
        $overFetch = max($limit * self::OVERFETCH_FACTOR, $limit);
        /** @var AgentMemoriesService $agentMemoriesService */
        $agentMemoriesService = DDDService::instance()->getService(AgentMemoriesService::class);
        $memories = array_values(
            $agentMemoriesService->findSimilarMemories($query, $accountId, $agentSpace, $overFetch)->getElements()
        );
        if ($memories === []) {
            return [];
        }
        // Window each memory to a query-anchored snippet (a SOLUTION/FACT is TEXT-typed and can be long) — same flood
        // guard as the message paths; rerankTextIndices hard-caps it again as a backstop.
        $texts = array_map(
            fn ($memory): string => $this->windowExcerpt((string)($memory->content ?? ''), $query, self::RERANK_CANDIDATE_CHARS),
            $memories
        );
        $order = $this->rerankTextIndices($query, $texts, $budgetAccount);

        $hits = [];
        foreach (array_slice($order, 0, $limit) as $memoryIndex) {
            $memory = $memories[$memoryIndex] ?? null;
            if ($memory === null) {
                continue;
            }
            $hits[] = new SemanticSearchHit(
                messageId: $memory->memoryId,
                indexInConversation: null,
                role: $memory->memoryType,
                created: $memory->createdTime,
                updated: $memory->modifiedTime,
                excerpt: $this->windowExcerpt((string)($memory->content ?? ''), $query, $excerptChars),
                relevance: 1.0,
            );
        }
        return $hits;
    }

    /**
     * Search the conversations of ONE account (episodic recall): the SAME primitive over the
     * account's message corpus → FAST_CHEAP rerank → cap PER conversation (so the result spans chats) → hits
     * carrying their `conversationId` (which chat + when). Every one of the three retrieval queries filters on the
     * conversation's `accountId`; the read rights of the authenticated account apply on top. The filter holds for an
     * account with the admin role too, whose read rights alone restrict nothing. The rerank is attributed to the
     * same account.
     *
     * @return SemanticSearchHit[]
     */
    public function searchAcrossConversations(
        Account $account,
        string $query,
        int $limit = 5,
        int $excerptChars = 0
    ): array {
        if (trim($query) === '' || !isset($account->id)) {
            return [];
        }
        // no query embedding → the vector legs find nothing, the keyword leg still answers
        $queryEmbedding = $this->embedQueryOrEmptyEmbedding($query);
        $overFetch = max($limit * self::OVERFETCH_FACTOR, $limit);

        // (a) direct leaf hits across the account's conversations. Like (b), a failing vector query (a bad row, the
        // index) must never take the whole search of the account down: it degrades to the node and keyword legs.
        try {
            $directMessages = $this->findMessagesSimilarAcrossConversations($account, $queryEmbedding, $overFetch);
        } catch (Throwable $vectorSearchError) {
            $this->logVectorSearchFailure($vectorSearchError, 'search across the conversations of account ' . $account->id . ' runs without the message vector leg');
            $directMessages = [];
        }

        // (b) RAPTOR node-region expansion ACROSS conversations: each matched compaction-node summary → its best
        // in-range messages (in that node's OWN conversation, the summary itself dropped). Recalls a topic that was
        // COMPACTED in any past conversation — the summary matches even when no single raw leaf does — exactly the
        // (b) path searchWithinConversation already has; this brings the cross-conversation search to the same hybrid.
        // The node path is an ENHANCEMENT over the leaf baseline — a failure here must never break the core recall
        // (graceful degrade to leaf-only, same spirit as the rerank fallback).
        try {
            $nodeExpandedMessages = $this->expandNodeRegionsToMessagesAcrossConversations($account, $queryEmbedding, $overFetch);
        } catch (Throwable $nodeSearchError) {
            $this->logVectorSearchFailure($nodeSearchError, 'search across the conversations of account ' . $account->id . ' runs without the compaction-node leg');
            $nodeExpandedMessages = [];
        }

        // (c) BM25/keyword leg across the account's conversations — lexical complement.
        $keywordMessages = $this->findMessagesByKeywordAcrossConversations($account, $query, $overFetch);

        // Fuse the ranked id-lists (dense + node + keyword + recency) then sharpen with the rerank — same as within-conversation.
        $messagesById = $this->indexMessagesById($directMessages, $nodeExpandedMessages, $keywordMessages);
        $directRanking = $this->rankingIds($directMessages);
        $nodeRanking = $this->rankingIds($nodeExpandedMessages);
        $keywordRanking = $this->rankingIds($keywordMessages);
        $recencyRanking = $this->recencyRanking($messagesById);
        $fusedMessages = [];
        foreach ($this->reciprocalRankFusion([$directRanking, $nodeRanking, $keywordRanking, $recencyRanking]) as $messageId) {
            if (isset($messagesById[$messageId])) {
                $fusedMessages[] = $messagesById[$messageId];
            }
        }
        // an eval run is no owner history — its conversations never answer "what did we talk about"
        $fusedMessages = $this->withoutMessagesOfEvalRuns($fusedMessages);
        // the platform's own runs are no conversation the customer had
        $fusedMessages = $this->withoutMessagesOfCustomerHiddenConversations($fusedMessages);
        if ($fusedMessages === []) {
            return [];
        }
        $rankedMessages = $this->rerank($query, $fusedMessages, $account);

        $hits = [];
        $perConversationCount = [];
        $rank = 0;
        $total = max(count($rankedMessages), 1);
        foreach ($rankedMessages as $message) {
            $conversationId = $message->aiConversationId ?? null;
            // Cap per conversation so one chat can't crowd out the rest (E4 grouping intent).
            if ($conversationId !== null) {
                if (($perConversationCount[$conversationId] ?? 0) >= self::MAX_HITS_PER_CONVERSATION) {
                    $rank++;
                    continue;
                }
                $perConversationCount[$conversationId] = ($perConversationCount[$conversationId] ?? 0) + 1;
            }
            $hits[] = new SemanticSearchHit(
                messageId: isset($message->id) ? (int)$message->id : null,
                indexInConversation: $message->indexInConversation ?? null,
                role: $message->role ?? null,
                created: $message->getCreatedTime(),
                updated: $message->getModifiedTime(),
                excerpt: $this->windowExcerpt(AIConversationMessage::toSearchableText((string)($message->message ?? '')), $query, $excerptChars),
                relevance: round(1.0 - ($rank / $total) * 0.5, 4), // graded by the rerank order (best-first), matching searchWithinConversation
                conversationId: $conversationId,
            );
            $rank++;
            if (count($hits) >= $limit) {
                break;
            }
        }
        return $hits;
    }

    /**
     * The dense leg within one conversation: its messages nearest to the query embedding, optionally in a turn range.
     *
     * @return AIConversationMessage[]
     */
    protected function findMessagesSimilarWithinConversation(TextEmbedding $queryEmbedding, int $conversationId, int $overFetch, ?int $turnFrom, ?int $turnTo): array
    {
        return array_values(
            $this->getMessagesRepoClassInstance()->findSimilarByEmbedding($queryEmbedding, $conversationId, $overFetch, $turnFrom, $turnTo)?->getElements() ?? []
        );
    }

    /**
     * The keyword leg within one conversation: its FULLTEXT matches of the query, best first.
     *
     * @return AIConversationMessage[]
     */
    protected function findMessagesByKeywordWithinConversation(string $query, int $conversationId, int $overFetch, ?int $turnFrom, ?int $turnTo): array
    {
        return array_values(
            $this->getMessagesRepoClassInstance()->findByKeyword($query, $conversationId, $overFetch, $turnFrom, $turnTo)?->getElements() ?? []
        );
    }

    /**
     * The dense leg across the account's conversations: the messages nearest to the query embedding.
     *
     * @return AIConversationMessage[]
     */
    protected function findMessagesSimilarAcrossConversations(Account $account, TextEmbedding $queryEmbedding, int $overFetch): array
    {
        return array_values(
            $this->getMessagesRepoClassInstance()->findSimilarAcrossConversationsByEmbedding($account, $queryEmbedding, $overFetch)?->getElements() ?? []
        );
    }

    /**
     * The keyword leg across the account's conversations: the FULLTEXT matches of the query, best first.
     *
     * @return AIConversationMessage[]
     */
    protected function findMessagesByKeywordAcrossConversations(Account $account, string $query, int $overFetch): array
    {
        return array_values(
            $this->getMessagesRepoClassInstance()->findByKeywordAcrossConversations($account, $query, $overFetch)?->getElements() ?? []
        );
    }

    /**
     * The candidates without the messages of conversations a customer never sees
     * ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::isHiddenFromCustomer()}). Fail-CLOSED: when the
     * lookup fails, nothing is returned rather than a hidden conversation's text.
     *
     * @param AIConversationMessage[] $messages
     * @return AIConversationMessage[]
     */
    protected function withoutMessagesOfCustomerHiddenConversations(array $messages): array
    {
        $conversationIds = [];
        foreach ($messages as $message) {
            if (($message->aiConversationId ?? null) !== null) {
                $conversationIds[(int)$message->aiConversationId] = true;
            }
        }
        if ($conversationIds === []) {
            return $messages;
        }
        try {
            $hiddenConversationIds = AIConversation::getService()->findCustomerHiddenConversationIds(array_keys($conversationIds));
        } catch (Throwable) {
            return [];
        }
        if ($hiddenConversationIds === []) {
            return $messages;
        }
        return array_values(array_filter(
            $messages,
            static fn (AIConversationMessage $message): bool => !in_array((int)($message->aiConversationId ?? 0), $hiddenConversationIds, true)
        ));
    }

    /**
     * The candidates without the messages of EVAL-run conversations (`runSettings.evalRun`): an eval conversation is
     * persisted like any other, but its owner is simulated. The history an eval SEEDS for a recall case
     * (`runSettings.evalSeededHistory`) stays. One id lookup over the candidates' conversations; fail-open — an
     * unreadable marker leaves the candidates as they are.
     *
     * @param AIConversationMessage[] $messages
     * @return AIConversationMessage[]
     */
    protected function withoutMessagesOfEvalRuns(array $messages): array
    {
        $conversationIds = [];
        foreach ($messages as $message) {
            if (($message->aiConversationId ?? null) !== null) {
                $conversationIds[(int)$message->aiConversationId] = true;
            }
        }
        if ($conversationIds === []) {
            return $messages;
        }
        try {
            $evalRunConversationIds = $this->findEvalRunConversationIdsAmong(array_keys($conversationIds));
        } catch (Throwable) {
            return $messages;
        }
        if ($evalRunConversationIds === []) {
            return $messages;
        }
        return array_values(array_filter(
            $messages,
            static fn (AIConversationMessage $message): bool => !in_array((int)($message->aiConversationId ?? 0), $evalRunConversationIds, true)
        ));
    }

    /**
     * The ids among `$conversationIds` whose conversation is an eval run (`runSettings.evalRun` is true) that holds
     * no seeded history (`runSettings.evalSeededHistory` is not true). The two marks are read as the text of the
     * JSON value and compared with the text `true`: an absent mark, JSON null and `false` are all "not true".
     *
     * @param int[] $conversationIds
     * @return int[]
     */
    protected function findEvalRunConversationIdsAmong(array $conversationIds): array
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        $evalRunMarkSql = $sqlDialect->jsonFieldText('`runSettings`', 'evalRun');
        $evalSeededHistoryMarkSql = $sqlDialect->jsonFieldText('`runSettings`', 'evalSeededHistory');
        return array_map('intval', EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT `id` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` IN (:conversationIds)'
            . " AND ($evalRunMarkSql) = 'true'"
            . " AND COALESCE(($evalSeededHistoryMarkSql), 'false') <> 'true'",
            ['conversationIds' => array_map('intval', $conversationIds)],
            ['conversationIds' => ArrayParameterType::INTEGER]
        ));
    }

    /**
     * The global "search all my chats" result: {@see searchAcrossConversations} flat
     * hits GROUPED by conversation, each group carrying the conversation's title + most-recent matched date, best-group
     * first. Frontend renders "in your chat '<title>' on <date> you said …" instead of a flat scatter. The title is the
     * summary the conversation holds already ({@see AIConversation::getSummaryWithoutGenerating()}); null when it
     * was never summarised. The search generates no summary: one generation is a model call of seconds per result.
     *
     * @return AIConversationSearchGroup[]
     */
    public function searchAcrossConversationsGrouped(Account $account, string $query, int $limit = 5, int $excerptChars = self::DEFAULT_EXCERPT_CHARS): array
    {
        // Over-fetch hits (cap per conversation already applied inside) so the grouping has several conversations to
        // show; the flat hits arrive best-first, so the first time a conversation appears is its best hit.
        $hits = $this->searchAcrossConversations($account, $query, $limit * self::MAX_HITS_PER_CONVERSATION, $excerptChars);

        /** @var AIConversationSearchGroup[] $groupsByConversationId */
        $groupsByConversationId = [];
        foreach ($hits as $hit) {
            $conversationId = $hit->conversationId;
            if ($conversationId === null) {
                continue;
            }
            if (!isset($groupsByConversationId[$conversationId])) {
                if (count($groupsByConversationId) >= $limit) {
                    continue; // the groups shown are complete — a further conversation is neither loaded nor shown
                }
                $group = new AIConversationSearchGroup();
                $group->conversationId = $conversationId;
                $group->relevance = $hit->relevance;       // first hit = best (hits are best-first)
                $conversation = $this->loadConversationForGroup($conversationId);
                // The conversation's own created/modified timestamps (its changeHistory) — modifiedTime is its
                // most-recent activity. The FE needs both for each result it lists, hence they ride on the group.
                $group->created = $this->formatChangeTime($conversation?->getCreatedTime());
                $group->updated = $this->formatChangeTime($conversation?->getModifiedTime());
                $summary = $conversation?->getSummaryWithoutGenerating();
                // isset() (not ?->) — `title`/`text` are typed properties WITHOUT a default: on a summary that holds
                // neither, `$summary?->text` throws "must not be accessed before initialization" (the `?->` only
                // guards a null $summary). isset() is false for a null $summary and for an uninitialised property.
                $group->title = isset($summary->title) ? $summary->title : null;
                $group->summary = isset($summary->text) ? $summary->text : null;
                $groupsByConversationId[$conversationId] = $group;
            }
            $group = $groupsByConversationId[$conversationId];
            $group->hits[] = $hit;
        }

        return array_values($groupsByConversationId);
    }

    /**
     * Load the conversation behind a search-result group ONCE so the group can read its title/summary AND its
     * created/modified timestamps off the same entity (instead of two finds). Read-only; returns null on any miss.
     */
    protected function loadConversationForGroup(int $conversationId): ?AIConversation
    {
        try {
            return AIConversation::getService()->find($conversationId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Window a message down to `$chars` around the first query-term hit (so the excerpt shows WHY it matched), with
     * ellipses when trimmed. `$chars <= 0` returns the full text (the agent path reasons over content directly). Pure.
     */
    public function windowExcerpt(string $text, string $query, int $chars): string
    {
        $text = trim($text);
        if ($chars <= 0 || mb_strlen($text) <= $chars) {
            return $text;
        }
        $matchPos = $this->firstQueryTermPosition($text, $query);
        if ($matchPos === null) {
            return rtrim(mb_substr($text, 0, $chars)) . '…';
        }
        $start = max(0, $matchPos - (int)($chars / 2));
        $excerpt = mb_substr($text, $start, $chars);
        return ($start > 0 ? '…' : '') . trim($excerpt) . ($start + $chars < mb_strlen($text) ? '…' : '');
    }

    /** Lowest case-insensitive position of any ≥3-char query word in the text, or null when none occur. */
    protected function firstQueryTermPosition(string $text, string $query): ?int
    {
        $haystack = mb_strtolower($text);
        $best = null;
        foreach (preg_split('/\s+/', mb_strtolower(trim($query))) ?: [] as $term) {
            if (mb_strlen($term) < 3) {
                continue;
            }
            $pos = mb_strpos($haystack, $term);
            if ($pos !== false && ($best === null || $pos < $best)) {
                $best = $pos;
            }
        }
        return $best;
    }

    /**
     * For each compaction node whose summary matches, pull its best in-range messages (node→messages resolution). The
     * node summary is the recall accelerator (matches a region's THEME even when no single message says it verbatim);
     * the messages of its `[coveredFrom, coveredTo]` range are the response units.
     *
     * @return AIConversationMessage[]
     */
    protected function expandNodeRegionsToMessages(TextEmbedding $queryEmbedding, int $conversationId, int $overFetch): array
    {
        $nodes = $this->getCompactionNodesRepoClassInstance()
            ->findSimilarByEmbedding($queryEmbedding, $conversationId, $overFetch)?->getElements() ?? [];
        $messageRepo = $this->getMessagesRepoClassInstance();
        $expanded = [];
        foreach ($nodes as $node) {
            if ($node->coveredFromMessageIndex === null || $node->coveredToMessageIndex === null) {
                continue;
            }
            $inRange = $messageRepo->findSimilarByEmbedding(
                $queryEmbedding,
                $conversationId,
                self::MESSAGES_PER_NODE,
                $node->coveredFromMessageIndex,
                $node->coveredToMessageIndex
            )?->getElements() ?? [];
            foreach ($inRange as $message) {
                $expanded[] = $message;
            }
        }
        return $expanded;
    }

    /**
     * CROSS-conversation twin of {@see self::expandNodeRegionsToMessages()}: rank the compaction-node summaries of
     * the account's conversations by the query, then expand each matched node to its best in-range messages within
     * THAT node's OWN conversation ({@see AIConversationCompactionNode::$aiConversationId} + its covered index range).
     * The node summaries cover the COMPACTED bulk of the whole history, so this is the DB-efficient way to reach old
     * content: search a small summary index instead of every leaf. Skips nodes missing a conversation or range.
     *
     * @return AIConversationMessage[]
     */
    protected function expandNodeRegionsToMessagesAcrossConversations(Account $account, TextEmbedding $queryEmbedding, int $overFetch): array
    {
        $nodes = $this->getCompactionNodesRepoClassInstance()
            ->findSimilarAcrossConversationsByEmbedding($account, $queryEmbedding, $overFetch)?->getElements() ?? [];
        $messageRepo = $this->getMessagesRepoClassInstance();
        $expanded = [];
        foreach ($nodes as $node) {
            if ($node->aiConversationId === null
                || $node->coveredFromMessageIndex === null
                || $node->coveredToMessageIndex === null
            ) {
                continue;
            }
            $inRange = $messageRepo->findSimilarByEmbedding(
                $queryEmbedding,
                (int)$node->aiConversationId,
                self::MESSAGES_PER_NODE,
                $node->coveredFromMessageIndex,
                $node->coveredToMessageIndex
            )?->getElements() ?? [];
            foreach ($inRange as $message) {
                $expanded[] = $message;
            }
        }
        return $expanded;
    }

    /**
     * The ordered list of the messages' ids (the input to RRF). Skips rows without an id (unpersisted).
     *
     * @param AIConversationMessage[] $messages
     * @return int[]
     */
    protected function rankingIds(array $messages): array
    {
        $ids = [];
        foreach ($messages as $message) {
            if (!isset($message->id)) {
                continue;
            }
            $ids[] = (int)$message->id;
        }
        return $ids;
    }

    /**
     * The id→message map over every candidate source, in source order (a message a later source returns again keeps
     * its first position and takes the later object). Skips rows without an id (unpersisted).
     *
     * @param AIConversationMessage[] ...$candidateMessageLists
     * @return AIConversationMessage[]
     */
    protected function indexMessagesById(array ...$candidateMessageLists): array
    {
        $messagesById = [];
        foreach ($candidateMessageLists as $candidateMessages) {
            foreach ($candidateMessages as $message) {
                if (isset($message->id)) {
                    $messagesById[(int)$message->id] = $message;
                }
            }
        }
        return $messagesById;
    }

    /**
     * A RECENCY ranking over the ALREADY-retrieved candidate union (newest-first) — one more RRF signal so a fresher
     * message rises among the relevant ones ("what did we discuss recently about X"). It only REORDERS what dense /
     * keyword / node already found — it never injects un-retrieved messages, so recency nudges relevance, never floods
     * it. RRF's rank-decay keeps its influence modest.
     *
     * @param AIConversationMessage[] $messagesById
     * @return int[] message ids, newest-first
     */
    protected function recencyRanking(array $messagesById): array
    {
        uasort($messagesById, static function (AIConversationMessage $a, AIConversationMessage $b): int {
            // The created time lives on the changeHistory (there is NO `createdAt` property — reading it returns null,
            // which silently flattened this recency leg to a no-op). getCreatedTime() is the canonical null-safe accessor.
            return ($b->getCreatedTime()?->getTimestamp() ?? 0) <=> ($a->getCreatedTime()?->getTimestamp() ?? 0);
        });
        return array_keys($messagesById);
    }

    /**
     * Reciprocal Rank Fusion (k=60) over the ranked id-lists (vector, node region, keyword, recency): a message
     * ranked high in ANY of the lists rises, one ranked high in several rises most. Returns ids best-first.
     *
     * @param int[][] $rankedLists
     * @return int[]
     */
    protected function reciprocalRankFusion(array $rankedLists, int $k = 60): array
    {
        $scoreById = [];
        foreach ($rankedLists as $list) {
            foreach (array_values($list) as $rank => $id) {
                $scoreById[$id] = ($scoreById[$id] ?? 0.0) + 1.0 / ($k + $rank + 1);
            }
        }
        arsort($scoreById);
        return array_keys($scoreById);
    }

    /**
     * @throws TextEmbeddingNotGeneratedException
     */
    protected function embedQuery(string $query): TextEmbedding
    {
        /** @var AITextEmbeddingsService $embeddingsService */
        $embeddingsService = DDDService::instance()->getService(AITextEmbeddingsService::class);
        return $embeddingsService->generateEmbeddingForText(trim($query));
    }

    /**
     * The query's embedding, or an EMPTY one when the embedding call yields no vector (logged): the vector legs then
     * return nothing and the keyword leg answers alone.
     */
    protected function embedQueryOrEmptyEmbedding(string $query): TextEmbedding
    {
        try {
            return $this->embedQuery($query);
        } catch (TextEmbeddingNotGeneratedException $embeddingNotGenerated) {
            $this->logVectorSearchFailure($embeddingNotGenerated, 'search runs on keywords only (no query embedding)');
            return TextEmbedding::newInstance();
        }
    }

    /** Log a vector leg the search degrades from (a WARNING on the issues channel), with what the search does instead. */
    protected function logVectorSearchFailure(Throwable $throwable, string $consequence): void
    {
        /** @var AITextEmbeddingsService $embeddingsService */
        $embeddingsService = DDDService::instance()->getService(AITextEmbeddingsService::class);
        $embeddingsService->logEmbeddingFailure($throwable, $consequence);
    }

    /** Format a changeHistory timestamp (createdTime / modifiedTime) to the host's `Y-m-d H:i:s`; null stays null. */
    protected function formatChangeTime(?DateTime $dateTime): ?string
    {
        return $dateTime?->format('Y-m-d H:i:s');
    }

    /**
     * The repository of the message set: the application's class when it subclasses the repository at the same path.
     *
     * @return DBAIConversationMessages
     */
    protected function getMessagesRepoClassInstance(): DBAIConversationMessages
    {
        /** @var DBAIConversationMessages $messagesRepo */
        $messagesRepo = AIConversationMessages::getRepoClassInstance();
        return $messagesRepo;
    }

    /**
     * The repository of the compaction node set: the application's class when it subclasses the repository at the
     * same path.
     *
     * @return DBConversationCompactionNodes
     */
    protected function getCompactionNodesRepoClassInstance(): DBConversationCompactionNodes
    {
        /** @var DBConversationCompactionNodes $compactionNodesRepo */
        $compactionNodesRepo = AIConversationCompactionNodes::getRepoClassInstance();
        return $compactionNodesRepo;
    }
}
