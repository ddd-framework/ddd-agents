# Design decisions — context

**Reader:** package maintainer

Contents: volatile values ride the tail · chars / 4 instead of a tokenizer · footprint before routing · an absolute
compaction threshold · a tree of summaries, five children per node · facts preserved by code · tool results stubbed on the
wire · skill-granular unloading once per owner turn · pins separate from initial residency · append-only skill loading
· appended items and notes are recorded wire items · volatile context once per change · reasoning carry-over capped ·
one clock at the tool boundary · the conversation owns no zone · presence per call, device per message · hybrid search
with an honoured rerank · the search scope is a query filter

## Volatile values ride the tail

**Rule.** Every value that can change within a conversation (clock, plan, situation provenance, unconfirmed zone,
hints) is a transient user entry after the history; the system content holds only what is stable for the conversation
or wider, ordered from the most to the least stable.

**Reason.** The provider caches the longest byte-identical prefix; one changed byte early in the system content makes
the whole history uncached on every call. A volatile block placed before the history once pinned the cached prefix at
the static head while the history never cached.

**Rejected.** A per-call timestamp or state block in the system content (cheap to write, kills the cache); removing
the hints entirely (the structural guards cover literal repeats, but the clock and the reply language must be current
at every call).

**Would change if.** Providers cached non-prefix segments independently, so a changed middle part no longer
invalidated what follows.

## chars / 4 instead of a tokenizer

**Rule.** Retention budgets, eviction sizes, the fixed overhead and the append-only tool-set ceiling are estimated as
characters divided by four, from stored counts.

**Reason.** Every one of these numbers is compared with a threshold calibrated in the same unit; a consistent cheap
estimate decides the same way, and running a tokenizer on every result and schema on every call is the CPU cost the
rules exist to avoid.

**Rejected.** The provider's tokenizer per call; the provider's reported usage (only known after the call, and absent
for tool rows).

**Would change if.** A decision needed an absolute token count against a hard provider limit rather than a ratio.

## Footprint before routing

**Rule.** The package bounds the size of what is resident (eviction, catalog in one segment, a skill hierarchy that
keeps the catalog at its top level) and lets the model choose skills from the full catalog; it does not retrieve tools
per step with an embedding search.

**Reason.** Reducing the resident skill and tool block improves accuracy, not only cost: excess resident context
degrades tool selection. Wrong-skill routing only collapses at catalogs several times larger than a typical host's,
while a retrieval miss fails a task silently.

**Rejected.** Retrieval of tools per step (weak recall on tool retrieval); provider-side tool search (not available on
every model, and an emulation missed obvious tools); tools as code in a sandbox (a large attack surface, no gain on
short call chains).

**Would change if.** A host's catalog grew to many dozens of top-level skills, or a provider-native deferred-tool
mechanism kept the cached prefix.

## An absolute compaction threshold

**Rule.** Compaction fires at one absolute token count: the conversation's override, else the model's
`effectiveContextTokens` capped at 80 % of its window and at 100000.

**Reason.** Reasoning accuracy falls at an absolute length that is a property of the model, far below a large window;
a fraction of the window would let a large-window model run into that region. Cost agrees: with caching, a lean cap
only raises cache-write frequency.

**Rejected.** A fraction of the context window; message-count triggers (they kept a fallback role for budgets too
small to tile).

**Would change if.** A model's measured reliable length moved, which changes its catalog value, not the rule.

## A tree of summaries, five children per node

**Rule.** Leaves summarize raw rows; a roll-up summarizes five child summaries, never raw rows; the cover tiles the
history coarsest first, and the newest share stays verbatim.

**Reason.** With a summary about one fifth of its source, a node over five children costs about what one leaf costs
while covering five times more: the cover grows logarithmically, so a fixed summary budget carries an unbounded
history. Nodes are immutable and append-only, which makes rounds idempotent and point-in-time rebuilds possible.

**Rejected.** One rolling summary rewritten every round (erodes old facts, rewrites the cached summary every time);
re-summarizing from raw rows at each level (cost grows with the history).

**Would change if.** The summarizer's compression ratio changed materially; the number of children per roll-up follows it.

## Facts preserved by code

**Rule.** The identifiers of a range (identifier-named keys of tool arguments and top-level results) and the owner's
quoted phrases are extracted by code and pinned verbatim above the summaries; a roll-up merges its children's facts
without loss.

**Reason.** A cheap summarizer paraphrases ids, and a paraphrased id silently targets the wrong record.

**Rejected.** Asking the summarizer to keep ids (unreliable); re-reading the raw rows for ids at use time (the rows
are out of the context by design).

**Would change if.** A fact class appeared that code cannot recognise; it would need its own extractor, not a prompt.

## Tool results stubbed on the wire

**Rule.** Old or over-budget tool results are replaced by a stub when the call is built; the stored result stays.
The window is anchored to owner turns, the budget keeps results that fit, unseen and tiny results are never stubbed.

**Reason.** Results are the largest growing part of a working turn and compaction reaches them only much later.
Stubbing at build time keeps them restorable and inspectable; turn anchoring keeps the boundary still within a turn.

**Rejected.** Deleting or truncating stored results (loses forensics and the reuse of reads); a contiguous boundary
(evicted a small result behind two large ones, which the model then re-read).

**Would change if.** Providers offered server-side clearing that keeps the cached prefix.

## Skill-granular unloading once per owner turn

**Rule.** By default a skill leaves together with its tools and sub-skills after a number of idle owner turns, checked
once at the start of an owner turn; the hard cap is the only size lever.

**Reason.** Removing one cooled tool at any step changed the tools array mid-turn and cost the cached prefix on a large
share of calls; a sibling tool leaving alone also forced extra `load_tool` calls. One mutation per turn keeps all steps
of a turn on one prefix.

**Rejected.** The per-tool cost-benefit score after every step (kept as the `tool` granularity for comparison); a soft
pressure watermark (would evict fresh items).

**Would change if.** Append-only loading became the only strategy; inside an epoch nothing unloads anyway.

## Pins separate from initial residency

**Rule.** `#[MCPToolNoEviction]` / `nonEvictable` pin an item against every unloading; `#[MCPToolAlwaysLoad]` only
loads a tool's schema with its skill.

**Reason.** "Hot at the start" and "never reclaim" are different needs; one flag for both either pins every eager tool
or makes a pin evictable.

**Rejected.** A single "always" flag.

**Would change if.** Never; the two axes are independent by nature.

## Append-only skill loading

**Rule.** Under `conversationAppend` the system content and top-level tools are fixed for a prefix epoch; a later
skill load appends its instructions and an `additional_tools` item to the history. Only the Responses API, which
accepts tools added inside the history, runs it; a Chat Completions model falls back to the swap.

**Reason.** Under the swap every load changed the tools rendered first by the provider and was credited no cached
tokens. Appending keeps the whole earlier prefix cached. A second appended tools item still voids the cache, so a
purpose whose reachable tools fit a ceiling gets them all top-level from the start.

**Rejected.** allowed_tools (rendered near the start; every change loses the prefix; ignored by one gateway on Chat
Completions); provider tool search with deferred loading (missed obvious tools, adds a model call); persisted
skill-load rows (see the next entry).

**Would change if.** Chat Completions gained a cache-preserving way to add a tool mid-conversation.

## Appended items and notes are recorded wire items

**Rule.** Appended skills, joining tools, situation entries and state notes are recorded on the request snapshot of
the call that first sent them and re-rendered at the same position by every later call; they are never rows.

**Reason.** A new row type would become "the newest row" for the loop's turn checks, would enter the compaction input,
and every load path would have to write it. Recording on the snapshot covers all load paths and keeps rows API-neutral.

**Rejected.** Persisted signal rows for loads and notes.

**Would change if.** Reconstruction from snapshots became impossible (snapshots dropped for storage).

## Volatile context once per change

**Rule.** Under `onceWhenChanged` the space's context, addressing, zone, directives and product blocks render from an
epoch record; each change arrives once as a note. An epoch starts at the conversation start, a new cover, a cold
prefix (30 minutes) and a surface change.

**Reason.** Rebuilding the base prompt on every input change was the largest single share of uncached tokens; one
note costs a few dozen tokens instead of a full prefix. A cold prefix costs a rewrite anyway, so it is the cheap moment
to refresh the record.

**Rejected.** Placing the space's context as the last system segment with its own breakpoint (the record makes the
base prompt stable without a new segment); freezing the surface-dependent sections in the record (a phone call needs
its sections at once, so a surface change starts an epoch).

**Would change if.** A value had to be current at every call; it then belongs to the tail.

## Reasoning carry-over capped

**Rule.** On Responses the previous steps' reasoning items go back up to a cap; past it all but the two newest steps
lose theirs in one block, at a recorded message index.

**Reason.** Sending none lowered task success in a measured comparison; sending all grows every call. A cut at a
recorded index, moved only when the cap is crossed, keeps the prefix stable between crossings within and across turns.

**Rejected.** All items (unbounded); only the current turn's (drops the plan's reasoning across turns); a sliding window
per call (moves the prefix every call).

**Would change if.** Providers kept reasoning server-side under a stateless request.

## One clock at the tool boundary

**Rule.** The model reads and writes local time of the agent space; the executor converts at hydration and render
through two request-scoped serializer values; days are never converted.

**Reason.** When the model converted, one intent produced different stored values across conversations and nothing in
the stored digits told which were converted. A leaf-type hook in the serializer reaches every nested moment and can
never leak into persistence, caches or external calls.

**Rejected.** The model converts; a walker over results (misses nested moments); a global serializer hook (leaks into
storage); a user- or browser-level zone (an unattended run has no browser).

**Would change if.** A host needed per-owner display zones different from the space's.

## The conversation owns no zone

**Rule.** A conversation reads its space's zone at every model request and tool call; only a schedule run and its
tree freeze the schedule's zone.

**Reason.** The zone of a space can change while a conversation runs (for example from an unverified default to the
exact zone once the host stores it), and the conversation must follow that change at once. Freezing the zone and following changes needed signals, locks and compare-and-set machinery that never converged.

**Rejected.** A zone frozen at creation that follows the space through signals; a zone computed on the fly from
stored facts with no stored column.

**Would change if.** Never for schedule-bound runs (cron digits and the run's clock must match); for the rest, only if
reading the space per call became expensive.

## Presence per call, device per message

**Rule.** Who reads the call is derived per call from the origin and a live call; the device is stamped on each owner
message; the surface is a transient variant from the newest owner message with a device.

**Reason.** An owner switches devices inside a conversation; a conversation stamp is wrong for every row after the
switch. A persisted presence would need a writer at every transition while it is a pure function of stored columns plus
the live call.

**Rejected.** A surface stamp on the conversation; a persisted presence column; the device as its own line under the
message (the bracket is the one anchor the reply-language cue already points at).

**Would change if.** A channel appeared whose presence cannot be derived from stored columns and one live look-up.

## Hybrid search with an honoured rerank

**Rule.** Four rankings (dense, compaction-node expansion, keyword, recency) are fused, then a cheap model reranks and
its verdict is honoured exactly, an empty verdict included; only a failed call falls back to the fused order.

**Reason.** Recall questions are meta questions whose answer often ranks low by vector distance; a wide window lets the
rerank promote it, and keyword and node legs cover un-embedded and compacted history. Honouring the drop prunes the
loosely related tail; a broken rerank still never empties the result.

**Rejected.** A recall floor that re-appended dropped candidates (scattered results); vector-only retrieval (misses
names and ids); summaries as hits (too generic to answer).

**Would change if.** An embedding model handled exact identifiers well enough to drop the keyword leg.

## The search scope is a query filter

**Rule.** Every cross-conversation query filters on the account in the repository method; a scoped vector search
computes the exact distance and never uses a vector index.

**Reason.** Read rights are wider than "my conversations" (an admin reads all). A vector index returns the globally
nearest rows and the scope filter afterwards can leave none.

**Rejected.** Scope through read rights; a vector index with post-filtering.

**Would change if.** The database supported a vector index with a pre-filter on the scope column.
