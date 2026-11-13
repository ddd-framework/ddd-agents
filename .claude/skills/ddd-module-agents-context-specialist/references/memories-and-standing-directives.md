# Memories and standing directives

**Reader:** host developer

The package ships the interface of a durable memory, not the memory store: `AgentMemoriesService` answers "no memory" in
every method until a host puts a same-path subclass under its own root namespace. This reference says what the core
asks of that subclass and where its answers land in the model input.

## 1. What belongs in a memory

A memory holds only the durable, unstructured residual that has no field anywhere: a way the owner wants to be
addressed, a rule for the agent's own replies, a soft fact or approach of the owner. Anything with a home in the host's
structured data (settings, records a tool edits) is edited there through its tool and never copied into a memory: a
copy drifts from the source of truth. Standing directives are the one kind the core injects on every call; the rest is
searched on demand.

## 2. What the core asks of the host

| Method of `AgentMemoriesService` | Called | Default |
|---|---|---|
| `findStandingDirectivesForAgentSpace($accountId, $agentSpace)` | while building the model input (phase J), once per conversation object, again only after a call of a tool named `remember`, `updateMemory` or `forget` completed | null |
| `curateConversationAfterTurn($conversation, $async)` | after an owner turn of a root that ended with nothing new arriving during it (`AIConversationTurnEndFollowUpsService`), with `$async = true`; a failure is swallowed | nothing |
| `findSimilarMemories($query, $accountId, $agentSpace, $limit)` | by `SemanticSearchService::searchMemory()` | empty `AgentMemoryHits` |
| `getEmbeddingRepairTargets()` | by the embedding repair ([recall-and-search.md](recall-and-search.md)) | empty `AgentEmbeddingRepairTargets` |

`$agentSpace` null means the account-wide entries only, never those of the account's spaces.

## 3. Standing directives in the model input

- `AgentStandingDirectives::$directivesBlock` is appended as the MEMORY_DIRECTIVES segment exactly as returned, after
  the time rule and the owner presence, before the episodic history: the block carries its own leading separator and
  header.
- `AIConversation::findMemoryDirectivesBlock()` memoizes the block on the conversation object under a key of account,
  space and the number of completed calls of the tools named `remember`, `updateMemory` or `forget` — three fixed
  names. **Trap:** a host memory tool with another name never refreshes the block within one conversation object; its
  change reaches the model with the next conversation object (the next run). Name the host's tools so, or expect the
  delay.
- Under `onceWhenChanged` the block is part of the epoch record; a change arrives once as a `standing-instructions`
  note that replaces the directives of the instructions ([volatile-context-once-per-change.md](volatile-context-once-per-change.md)). Under `everyCall` a
  change rewrites the prefix from that segment on.
- `AgentStandingDirectives::$newestValidFrom` lets the reply-language decision weigh a directive against the age of the
  conversation's anchor (decisions-and-guards skill).

Keep the block short and stable in byte order (sort the directives): it sits in the cached prefix of every call of the
space.

## 4. Episodic history

At creation a root gets a narrative of recent sessions (`AIConversationsService::composeAndPersistEpisodicHistory()` →
`AIConversations::buildEpisodicHistoryText()`), stored on `AIConversation::$episodicHistory` and rendered as the
EPISODIC_HISTORY segment for the whole conversation:

- the `EPISODIC_RECENT_WINDOW_SIZE` (5) newest sessions of the space (without a space: across all spaces of the account) that hold a
  usable summary, one line each with date, title, outcome and `#conversationId`;
- up to `EPISODIC_SURVIVOR_CAP` (3) older sessions whose summary importance is at least
  `EPISODIC_SURVIVOR_MIN_IMPORTANCE` (4);
- a closing line that points the model at the host's recall tools.

It is never re-read during the conversation, so it never moves the prefix. A purpose that loads earlier sessions itself
(`AIConversationPurpose::$loadsEarlierSessionsItself`) gets none; a delegation child never gets one. The pointer line
names recall tools the package does not ship: a host without such tools replaces the text through a same-path subclass
of `AIConversations`.

## 5. Moments in memories

A memory that records when something was said keeps the instant and the zone it was read in; the zone of a call is
`AgentToolCallProvenance::getTimezone()` and a recorded call's zone is on its request snapshot
([owner-presence-device-and-time.md](owner-presence-device-and-time.md)).

## 6. Host checklist

1. Same-path subclass of `AgentMemoriesService` (template in [SKILL.md](../SKILL.md)).
2. The memory tools the model calls, in a skill of the host that loads on demand, not as tools offered on every call
   (tools and skills-and-purposes skills): an always-offered tool's schema is sent and billed again on every call, and a
   long flat tool list weakens a cheap model's tool choice, while memory operations are rare. Only the names
   `remember`, `updateMemory` and `forget` refresh the directives within a run.
3. An embedding provider (`AITextEmbeddingsService` subclass) if `findSimilarMemories()` is vector-based, and the
   memory table in `getEmbeddingRepairTargets()`.
4. Curation off the critical path: `curateConversationAfterTurn()` is called with `$async = true`; dispatch a message,
   never run model calls inline.
