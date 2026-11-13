# Context reduction

**Reader:** host developer and package maintainer

Contents: 1. Three growing parts · 2. Tool-result stubbing · 3. The threshold · 4. The budgets · 5. The compaction
tree · 6. Preserved facts · 7. When and where it runs · 8. Cost of the reads · 9. Settings and symptoms

## 1. Three growing parts

| Part | Grows with | Bounded by |
|---|---|---|
| History (messages) | every turn | the compaction tree: older rows are replaced by a recency-graded summary (sections 3–7) |
| Tool results inside the history | every tool call | stubbing on the wire (section 2), the image cap |
| Loaded skill text and tool schemas | every skill load | eviction ([skill-and-tool-eviction.md](skill-and-tool-eviction.md)); compaction cannot reach them |

## 2. Tool-result stubbing

`AIConversation::resolveToolResultRetention()` walks the context's raw tail once, newest first, and returns a
`ToolResultRetention`; the egress replaces every result it does not keep by a stub. The stored result is untouched.

- **Turn window**: results of the last `keepToolResultsRecentTurns` owner turns are kept
  (`AIConversation::CONTEXT_RETENTION_DEFAULT_KEEP_TOOL_RESULTS_RECENT_TURNS` = 2).
- **Token budget by fit**: inside the window, the newest results are kept while their sum (stored `toolResultChars` / 4)
  fits `toolResultTokenBudget` (`CONTEXT_RETENTION_DEFAULT_TOOL_RESULT_TOKEN_BUDGET` = 24000); a result that does not
  fit is stubbed alone and the walk goes on, so a small older result behind two large ones stays.
- **Never stubbed**: a result no model call has seen yet (after the newest model-call row), the single newest result,
  a result shorter than `ToolCall::MIN_RESULT_CHARS_TO_STUB` (256 characters), and results of the names in
  `AIConversation::BUDGET_EXEMPT_TOOL_RESULT_NAMES` while their turn is kept (exempt from the budget only).
- A reused read whose earlier result was stubbed runs again ([tool-call-efficiency.md](tool-call-efficiency.md)).
- `ContextRetentionSettings::$enabled = false` keeps every result.

The token budget is not scaled by a purpose's context budget; a purpose pins its own in its `contextRetention:` map.

## 3. The threshold

Compaction is due when the newest call's input tokens reach one absolute number
(`AIConversation::shouldConsiderCompaction()`):

1. `CompactionSettings::$triggerTokens` of the conversation (a purpose's `contextBudgetTokens` arrives here), else
2. the model's `effectiveContextTokens`, capped at 80 % of its window (`CompactionContextRotWall::WINDOW_SHARE`) and at
   `CompactionModelLimits::DEFAULT_EFFECTIVE_CONTEXT_TOKENS` (100000), else
3. that 100000 fallback for a model without a value.

A purpose's budget is clamped to the model's 80 % wall but not to the 100000 ceiling
(`CompactionModelLimits::clampContextBudget()`). The threshold is never a fraction of the window: reliable reasoning
ends at an absolute length.

## 4. The budgets

`CompactionBudgets::fromMeasured()` derives everything from the working limit (the threshold) and the measured fixed
overhead (the newest stamped system content plus tool schemas):

| Quantity | Formula |
|---|---|
| target after a round | `COMPACTION_TARGET_RATIO` 0.60 × limit (hysteresis, no thrash) |
| reserve for the next turn | `NEXT_TURN_RESERVE_RATIO` 0.10 × limit |
| history budget | target − overhead − reserve |
| raw recent tail | `RAW_RECENT_SHARE` 0.40 × history budget, kept verbatim |
| summary budget | the rest |
| leaf span | max(`MIN_LEAF_SEGMENT_TOKENS` 256, raw tail / `FINE_NODES_PER_LEVEL` 4) |
| children per roll-up | `ROLLUP_FAN_IN` 5 = 1 / `SUMMARY_COMPRESSION_RATIO` 0.20 |

A history budget below 4 × 256 tokens is **degenerate**: the round falls back to the message-count knobs of
`CompactionSettings` (`DEFAULT_KEEP_RECENT_MESSAGES` 12, `DEFAULT_SEGMENT_MESSAGES` 20, `DEFAULT_TIER_FAN_IN` 4,
`DEFAULT_LEVEL_RETENTION` 2). A large loaded skill set raises the overhead and can make the budget degenerate.

**Leaf sizing is counted by the model, not re-tokenized** (`AIConversation::buildMessageFootprints()`): an assistant
reply's footprint is the growth of its `inputTokens` over the previous reply's; an owner or tool row counts 0 (its cost
is in the next reply's growth); the first measured reply counts only its `outputTokens`, so the fixed overhead stays
out of the history; a non-positive growth (a compaction just shrank the prompt) falls back to the reply's
`outputTokens`, a deliberate lower bound. A conversation without any measured reply uses the estimated token count of
each row. A node's `summaryTokens` comes from the summarizer's output tokens.

**Debugging a deep tree.** A low `CompactionSettings::$triggerTokens` only makes the budget degenerate (the overhead
alone exceeds it). Set `CompactionSettings::$debugLeafSegmentTokensOverride` instead: it forces small leaves, keeps at
least one leaf of raw tail, and is independent of the threshold.

**Per-model defaults** of `effectiveContextTokens` for a model without its own value come from the model catalog of
`mgamadeus/ddd-ai` (by window size, capped at 80 % of the window): `ddd-module-ai-specialist`.

**Cost with prompt caching.** A call costs roughly the cached price of the prefix plus the full price of the tail, plus
a cache write at each compaction. A lower threshold shrinks a prefix that is already discounted but brings the next
compaction sooner, so cost alone never argues for the leanest threshold; accuracy sets it.

## 5. The compaction tree

Nodes (`AIConversationCompactionNode`, set `AIConversationCompactionNodes`) are append-only and immutable:

- **Level 0 leaf**: the summary of a contiguous range of raw rows that no running turn still needs (`AIConversation::buildLeafCompactionInput()`:
  the identifiers block plus the transcript, errors dropped, tool results capped).
- **Roll-up**: a level-L+1 node summarizes the oldest 5 contiguous exposed nodes of level L once at least
  `fineNodesPerLevel` (4) newer nodes stay fine after it (9 exposed nodes at the level) (`AIConversationCompactionExposedNodesByLevel::buildNextRollUpGroupAtLevel()`).
  It reads its children's summaries, never the raw rows, so every level costs about the same per node and covers five
  times more; the cover grows logarithmically with the conversation. At most `CompactionBudgets::MAX_TREE_LEVELS` (16).
- **Cover**: the minimal set of nodes tiling `[0 … edge]`, coarsest first (`AIConversation::buildCompactionCover()`,
  optional point in time for rebuilds); everything after the edge is the raw tail. Its rendered text is the
  COMPACTION_SUMMARY segment.
- Each node gets an embedding of its summary for the search ([recall-and-search.md](recall-and-search.md)); a failed embedding stores the
  zero vector and the node is left out of the vector search until repaired.
- A duplicate insert (redelivery, concurrent round) hits the unique index and is skipped.
- **Where a round must stop.** Every leaf planner, the threshold round and the completion pass alike, ends its range
  below `AIConversation::getCompactionSettleFloorIndex()`, the lower of two floors: the unfinished turn
  (`getUnfinishedTurnFloorIndex()`: while a turn is open, only earlier turns may be covered, so the running task keeps
  its tool results verbatim and its owner message) and the oldest unanswered inbound message
  (`getUnansweredInboundFloorIndex()`: the oldest owner message or injected signal no final assistant text answered;
  a covered owner message would look answered to a retry). A range also stops below the oldest delegate whose result
  has not arrived yet: that result lands later and must never be summarized away.
- **Leaves before roll-ups.** Within a round leaves are written first, so a child's `created` time always precedes its
  parent's and the point-in-time read of the cover stays correct.

## 6. Preserved facts

The cheap summarizer paraphrases ids. So code, not the model, extracts per leaf
(`AIConversation::buildPreservedFactsForRange()` → `AIConversationCompactionPreservedFacts`) two kinds of identifiers:

- **tool identifiers** (`AIConversationMessage::extractToolCallIdentifiers()`): from a tool call's arguments and the
  TOP-LEVEL keys of a JSON result, only keys named like an identifier — exactly "id" or "name", or ending in "Id", "Ids", "_id",
  "_ids", "Name", "_name", or containing keyword, url or locale (any case) — each kept as
  `key=value`; a nested key or another name is not kept;
- **quoted owner phrases** (`AIConversationMessage::extractQuotedUserFacts()`): phrases of 2 to 80 characters in
  straight or typographic quotes or guillemets, from the owner's own text rows only.

A roll-up merges its children's facts without loss (`AIConversationCompactionPreservedFacts::mergeOf()`). The cover
renders "PRESERVED FACTS & IDENTIFIERS" above the gists. The node also has a standing-constraints slot and the cover a
"STANDING CONSTRAINTS" block for it, but nothing in the package fills it. The tool telemetry on a node (`AIConversationCompactionToolTelemetry`)
is for the supervisor only and never reaches the summarizer or the agent.

The summarizer input starts with a CONVERSATION FRAME (what the conversation is: a purposed run, a schedule firing, a
delegation sub-task) so the summary is anchored in it; the prompt is `AgentAIPromptNames::APP_AI_CONVERSATION_COMPACTION`.

## 7. When and where it runs

- **After a turn**, never inside one: the loop calls `AIConversationCompactionService::compactConversationIfDue()` after
  the turn's loop returned, never for a delegation child. A failure is logged and never breaks the reply.
- `async: true` dispatches `AIConversationCompactionMessage` on the compaction transport
  (`AIConversationCompactionHandler`); `async: false` runs the round inline (a console or test caller).
- One round (`runCompactionTreeRound()`): load the exposed frontier and the rows after the edge, plan all ready leaf
  ranges in one pass, summarize and persist each leaf, then cascade the roll-ups bottom-up. An empty summarizer answer
  stops that pass and is logged; the next round retries.
- **Finalize**: `finalizeConversationCompaction()` (handler `AIConversationFinalizeHandler`) covers a finished
  conversation to its last row with no raw tail and retention 0, below `DEFAULT_FINALIZE_MIN_MESSAGES` (6) only the
  summary is refreshed, then stamps `finalizeCompactedAt`. The handler runs budget-exempt (system maintenance, still
  logged) and is suppressed when automation is refused for the conversation; afterwards it dispatches the
  conversation's scorecard, which belongs to the observability skill `ddd-module-agents-observability-specialist`.
- **Which conversations are finalized.** `AIConversationsService::markIdleConversationsFinalized($idleMinutes)` stamps
  `finalizedAt` on every root conversation (no delegation child) that is not yet finalized, has no run in a
  non-terminal state (`AIConversationRunState::NON_TERMINAL`) and whose `lastInboundMessageAt` is older than the idle
  window — one indexed UPDATE, no hydration (`countIdleConversationsToFinalize()` is its dry-run count). A new owner
  message or injected signal resets the stamp (`AIConversationsService::recordInboundMessageActivity()` sets
  `finalizedAt` back to null), so a resumed conversation is finalized and completion-compacted again after its next
  idle window. `findFinalizedConversationIdsOwingCompletionCompaction($batchSize)` selects the ids whose `finalizedAt`
  is set and newer than `finalizeCompactedAt` (or that have none), oldest first; one `AIConversationFinalizeMessage`
  per id goes on the compaction transport. Who calls the two methods and how often is the host duty described in the
  entry skill `ddd-module-agents-specialist`.
- After a node landed, the display summary refresh runs, staleness-gated (it skips when the summary already covers
  the newest row).

## 8. Cost of the reads

- The round loads the frontier with one query per level (`ConversationCompactionNodesService::findExposedNodesForConversation()`,
  always `CompactionBudgets::MAX_TREE_LEVELS`, 16 queries, whatever the depth) instead of every node, and the rows after the edge in one read (the first round of a long conversation
  reads its whole backlog; later rounds read the tail).
- The cover read for a call (`findCoverNodesForConversation()`) issues one single-row query per cover node, walking
  from index 0 to the edge.
- The roll-up frontier is computed once per round and updated incrementally after each roll-up.
- Memos collapse the repeated work of one context build or round: `AIConversationMessages::getRowsSortedByIndex()`
  (keyed on the row count and the last row) and `AIConversationCompactionNodes::buildCover()` (keyed on the point in time and
  the node count); the preserved facts are extracted once per leaf for both the summarizer input and the node; the
  summary refresh reuses the round's tree and tail in memory.
- Summaries cost one cheap model call per leaf and per roll-up.
- A point-in-time inspection of a past call legitimately loads the full node set and every message
  (`AIConversationMessagesService::findAllMessagesForConversationInOrder()` without a limit: keyset windows of
  `CONTEXT_MESSAGE_WINDOW` (100), at most `MAX_CONTEXT_WINDOWS` (50), so 5,000 rows): the rows of a past tail are
  covered now, and the present cover and tail cannot rebuild it.

## 9. Settings and symptoms

| Symptom | Look at |
|---|---|
| Compaction never fires | `CompactionSettings::$enabled`; the newest call's input tokens vs the threshold of section 3; a delegation child |
| It fires but nothing lands | logged empty summarizer answers; a degenerate budget (huge overhead) |
| A fact or id is gone after compaction | it was not under an identifier-named key of a tool argument or a top-level JSON result (section 6), nor a 2–80-character quoted phrase in the owner's own text: return it under such a key or have the owner quote it |
| Old tool results vanish mid-task | the token budget by fit; raise `toolResultTokenBudget` for the purpose or shrink the results (tools skill) |
| A long research turn loses its page reads | `AIConversation::BUDGET_EXEMPT_TOOL_RESULT_NAMES` lists the reads the budget never stubs inside the kept turns |
