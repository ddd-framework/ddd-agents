# Recall and search

**Reader:** host developer and package maintainer

Contents: 1. The one primitive · 2. The pipeline · 3. Scope · 4. Embeddings · 5. Endpoints and recall tools ·
6. Cost · 7. Symptoms

## 1. The one primitive

`SemanticSearchService` serves every recall surface: the in-conversation search box, the "search all my chats"
search, and a host's recall tools for the agent. They differ in what they search, the excerpt size and the output
shape; retrieval, fusion, rerank and excerpting are shared.

| Method | Searches |
|---|---|
| `searchWithinConversation()` | one conversation, optionally a turn range (`RecallTurnRange::resolveFromPhrasing()` turns "at the beginning" into an index window — a filter, not a boost) |
| `searchAcrossConversations()` | every conversation of one account, at most `MAX_HITS_PER_CONVERSATION` (2) hits per conversation |
| `searchAcrossConversationsGrouped()` | the same, grouped per conversation with its stored summary as title (`AIConversationSearchGroup`) |
| `searchMemory()` | the host's memories through `AgentMemoriesService::findSimilarMemories()` (none by default) |

The unit of a hit is always a MESSAGE (`SemanticSearchHit`); a compaction summary is never returned.

## 2. The pipeline

1. Embed the query (`AITextEmbeddingsService`); no vector = the vector legs find nothing, the keyword leg still runs.
2. Four candidate rankings, each over-fetched (`OVERFETCH_FACTOR` 8 × limit — wide on purpose: a recall question
   such as "what did we discuss about X" is a question about the conversation, so the message that answers it often
   ranks far down by raw vector distance; the wide window keeps it in reach of the rerank, which can only order what
   it receives):
   - dense: cosine distance over the message embedding rows;
   - node expansion: compaction nodes nearest to the query, each resolved to its best `MESSAGES_PER_NODE` (2) messages
     in its covered range — recalls a compacted topic no raw message matches;
   - keyword: full-text match over the message text (needs no embedding, covers un-embedded rows);
   - recency: newest first over the union.
3. Reciprocal rank fusion (`k` = 60) over the four lists.
4. Across conversations: drop eval runs without seeded history and conversations hidden from the owner (the platform's own runs).
5. Rerank (`ArgusSearchRerank`, a cheap fast model, prompt `AgentAIPromptNames::APP_AI_SEARCH_RERANK`): at most
   `MAX_RERANK_CANDIDATES` (40) candidates, each cut to `RERANK_CANDIDATE_CHARS` (400) around the query. A parseable
   verdict is honoured exactly — omitted candidates are dropped, an empty verdict is an empty result. Only a failed or
   unparseable call falls back to the fused order.
6. Trim to the limit and window each excerpt around the first query term (`windowExcerpt()`; `DEFAULT_EXCERPT_CHARS`
   200, 0 = the full text).

A failing vector query (a bad row) is logged and the search continues on the other legs.

The rerank is declared with `enforceBudget: false`: the account's AI budget never caps it, its usage is still logged.
Without an active AI usage envelope the call is not attributed to one. The default gate of `mgamadeus/ddd-ai`
(`AIModelCallGateService::refuseModelCallWithoutUsageScopeOrEnvelope()`) refuses nothing; a host gate that requires an
envelope refuses the call, the rerank then falls back to the fused order, and a query for a word that is nowhere
returns everything.

## 3. Scope

Every cross-conversation query filters on the conversation's account inside the repository method
(`DBAIConversationMessages`); the read rights apply on top. Read rights alone are no scope: an account with the admin
role reads everything by its rights.

A vector search inside a scope orders by the exact distance plus zero, which keeps a vector index out: an index
returns the globally nearest rows and the scope filter applied afterwards can leave none. The cost grows with the size
of the scope, not of the table.

Trap: the message context window (section 5) loads the anchor message by id under the read rights alone
(`DBAIConversationMessages::findContentMessageById()`), without an account filter; an admin caller can open any id.

## 4. Embeddings

- **Message embeddings** (`AIConversationMessageEmbedding`, one row per message): only for a recall unit — a persisted
  TEXT message with text (`AIConversationMessageEmbeddingsService::isEmbeddable()`). The loop embeds the owner
  messages and the messages it publishes through `AIConversationMessagesService::embedMessage()`: asynchronously when
  the turn options say so (one `AIConversationMessageEmbeddingMessage` per message on the embedding transport), else
  inline; the row is upserted. No vector = no row, never a zero vector.
- **Compaction node embeddings**: written at node creation; a failure stores the zero vector, which the node search
  excludes until repaired.
- **The provider**: `AITextEmbeddingsService::generateEmbeddingForText()` throws `TextEmbeddingNotGeneratedException`
  in the package. A host with a provider puts a same-path subclass under its root namespace; every caller absorbs the
  exception and logs it through `logEmbeddingFailure()`.
- **Repair** (`AIConversationEmbeddingsRepairService::repairZeroEmbeddings()` with `AIConversationEmbeddingsRepairSettings`):
  re-embeds zero vectors of message rows, compaction nodes and the host's memory tables
  (`AgentMemoriesService::getEmbeddingRepairTargets()`), in bounded id windows with pauses, a dry run by default.
  Running it on a schedule is a host duty (entry skill).

## 5. Endpoints and recall tools

The chat controller trait `AgentConversationReadsTrait` exposes three routes; the two search routes open a usage envelope when
none is open (so the rerank is attributed and a host gate that requires one does not refuse it), the context route makes no
model call:

| Route | DTOs |
|---|---|
| `GET /{aiConversationId}/messages/search` | `AIConversationMessageSearchGetRequestDto` / `AIConversationMessageSearchGetResponseDto` |
| `GET /messages/{messageId}/context` | `AIConversationMessageContextGetRequestDto` / `AIConversationMessageContextGetResponseDto` |
| `GET /search` | `AIConversationSearchGetRequestDto` / `AIConversationSearchGetResponseDto` |

The context window (`AIConversationMessagesService::loadMessageContext()` → `MessageContextWindow`) shows the anchor
and the owner and agent prose around it under three token caps (default `MESSAGE_CONTEXT_DEFAULT_CAP_TOKENS` 2000 for
the anchor); every line carries its message id so the caller can walk further.

The package ships no recall tool for the agent. A host builds its recall tools on these service methods (the tools
skill describes how); the episodic history segment points the model at them by `#conversationId`
([memories-and-standing-directives.md](memories-and-standing-directives.md)). A host endpoint or tool that searches runs inside a usage envelope, so the rerank is attributed.

## 6. Cost

One cross-conversation search is two model calls (query embedding, rerank) plus the vector queries over messages and
nodes, one message query per matched node, and the keyword query (ids ranked first, rows loaded in a second query).
The node expansion is the part that grows with the number of matched nodes.

What keeps the reads small:

- A message hit is loaded with the partial select `AIConversationMessage::SEARCH_HIT_LOAD_COLUMNS`, a node with
  `DBConversationCompactionNodes::SEARCH_HIT_LOAD_COLUMNS` (id, conversation, covered range): a node row also carries
  its summary, its preserved facts and a vector of 1536 floats, none of which the search reads.
- The keyword leg ranks message ids only, then loads those rows in a second query: a full-text query with many
  matches sorts every matching row, and with the message text in the row that sort moves a large volume.
- That second query sets `setMaxResults()` to the number of ranked ids: a query without its own limit is cut by the
  default page size of the query options (50).
- A result group takes the conversation's title from `AIConversation::getSummaryWithoutGenerating()`: reading the
  summary property of a conversation that has none starts the summary generator, a model call per result. Such a
  group has no title.
- When measuring a search, a repeated identical query may be answered by the database's query cache: measure the
  first run of a query, or vary it.

## 7. Symptoms

| Symptom | Cause |
|---|---|
| Search returns nothing | no embedding provider and no keyword match; the rerank judged nothing relevant; zero-vector rows not repaired |
| Search returns everything | the rerank threw (for example the host gate refused a call without an envelope) and the fused order was returned unfiltered |
| A compacted topic is not found | node embeddings are zero vectors (provider outage at creation): run the repair |
| Another account's conversation appears | a host query that relied on the read rights instead of an account filter |
