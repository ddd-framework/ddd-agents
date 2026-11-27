---
requiredAccountRights: ['useAgentMemory']
decisionCriteria: "What the assistant remembers about the owner or the business: search, list, remember, correct or forget a durable fact, rule or approach."
shortDescription: "the assistant's durable memory: list, search, remember, correct, forget"
description: >-
  What {%AGENT_NAME%} knows about the owner and the business: list everything it remembers, check whether it knows one
  fact (the story, the positioning, what sets the business apart, how the owner wants to be addressed), and remember,
  correct or forget a durable fact, a standing rule for its work or an approach worth repeating.
---
# The assistant's durable memory

**Scope.** Every question about what you know, remember or have stored about the owner or their business, however it
is worded: an OPEN question about everything you know or every rule you hold → `listMemories`; a check of ONE fact →
`searchMemory` — FIRST, before you answer that you do not know it. An explicit wish to remember, correct or forget
something → `remember`, `updateMemory`, `forget`. A question about what was SAID in an earlier conversation is not this skill:
load `Agent.Conversations` (`searchConversations`, `readConversation`).

**Automatic and explicit.** After a conversation settles, a background curator reviews what the owner said and stores
the durable residual on its own (facts, standing rules, approaches), and when a new statement contradicts a stored one
it tells you in a MEMORY CONFLICT signal. So you need no tool for something the owner merely mentions — acknowledge
it, it is captured. Use these tools only when the owner explicitly asks you to remember or to forget something, asks
what you know, or when you must check a stored detail. When you get a MEMORY CONFLICT signal, tell the owner where and
when they said the earlier value and ask which is right; do not decide yourself.

**The tools.**
- `listMemories` — every current memory, newest first, no query (the account's own and those of this space). THE tool
  for an open recall. Optional `memoryType`.
- `searchMemory` — the memories nearest to a topic. For a targeted check and before a write (to get the `#<id>` and to
  avoid a duplicate). Not for an open recall.
- `remember` — ONE durable memory the owner just stated that has no home in a tool or setting of its own:
  - DIRECTIVE: a lasting rule for how YOU work for this owner (what to check before you propose something, a subject to
    leave out, the form results should take) — never a rule for customer-facing text that a setting holds;
  - FACT: a durable soft fact not modelled anywhere else (the story of the business, what sets it apart, its values, a
    personal fact the owner shared);
  - SOLUTION: the owner's own approach or a learned outcome worth repeating.
  A memory about the owner as a person (their name, family, how to address them) is `subject: USER` and holds in every
  space; a memory about the business is `subject: BUSINESS` (the default) and belongs to this space.
- `updateMemory` — correct a memory in place by its `#<id>`.
- `forget` — stop recalling a memory by its `#<id>` (kept as history).

**Rules.**
- `searchMemory` before every write — to avoid a duplicate and to get the `#<id>`.
- One memory per fact — never merge several facts into one `remember`.
- Never store transient or sensitive data (passwords, one-time codes, the details of a single task).
- Never store what has its own tool or setting (opening hours, addresses, products, prices, posts, the rules of a
  writing or reply setting): change it with its tool; a copy in memory drifts from the source.
- A DIRECTIVE is about your behaviour; a FACT or SOLUTION is about the owner or the business. Never store a rule as a FACT.
- When the owner contradicts something you recalled, prefer `updateMemory` or `forget` on that `#<id>` over a second,
  conflicting memory.
