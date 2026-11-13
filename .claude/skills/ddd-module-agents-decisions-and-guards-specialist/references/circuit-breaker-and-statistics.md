# Circuit breaker and statistics

**Reader:** host developer and package maintainer

Contents: the outage contract · how a call is classified · the breaker · split calls · cost · the decision statistics · eval traffic · the host's statistics command

## The outage contract

A decision may never wedge a turn. Three things bound it:

1. **A per-call timeout** — `timeoutSeconds` of `decisionModel.php` (default `DEFAULT_TIMEOUT_SECONDS`, a few times the
   measured warm latency so the first call of a cold process still fits). The tool-result screen runs off the turn path
   with its own longer timeout.
2. **One shared circuit breaker** — after a transport failure no runner site calls the model for `breakerSeconds`.
   The background tool-result screen is outside it: it loads its batch directly, so an open breaker does not stop it
   and its failures bench nothing (it has its own outcome on the call row: `failed`).
3. **Fail-open sites** — every site catches its own failures and behaves as without the decision. Owner consent is
   the one fail-closed site.

## How a call is classified

`DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService` classifies every runner call once into an `AgentDecisionCallOutcome` (which sites are
runner sites: [decision sites](decision-sites.md#which-site-goes-through-what)):

| Outcome | When | Breaker | Stamp |
|---|---|---|---|
| `answered` | the batch returned; whether the answer is usable is the site's question | closes only on a usable answer | the model's (`on`) or `shadow`; `fallback:unparsable` when unusable |
| `breakerOpen` | the breaker was open; no request was sent | — | `fallback:breaker` |
| `refused` | `AiBudgetExceededException`, `AIScopeNotEligibleException`, `MissingAIEnvelopeException`, `MissingAIScopeException` | never trips | `fallback:refused` |
| `transportFailed` | a Guzzle `TransferException`, or a child without answers whose status is none or one of `transportFailureStatusCodes()` | trips | `fallback:transport` |
| `timeoutSpentOnOwnWork` | a split call whose caller's own work outlasted the decision timeout | never trips | `fallback:ownWork` |
| `error` | any other exception — our own code or configuration, a registry prompt that fails to load when the usage is booked included (logged as a warning) | never trips | `fallback:error` |

An HTTP 400, 401 or 422 is our request or key being wrong: the answer is unusable, the breaker does not trip, because
a benched model would hide a misconfiguration. A transport failure of a **sibling** child of the batch trips the
breaker but leaves the site's own answer usable (`siblingTransportFailed`). `resolveFallbackStamp()` and
`resolveDecidedBy()` are the one mapping from outcome to stamp.

## The breaker

`DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService` keeps one key, `AgentDecisionModelCircuitBreakerService::CACHE_KEY_OPEN_UNTIL`,
in the shared cache (`Cache::CACHE_GROUP_REDIS`), so every worker sees the same state — process memory would not cross
workers.

- `trip()` logs the reason first, then stores the open-until time with the window as its lifetime.
- `isOpen()` is one read; a caller with an open breaker sends nothing.
- After the window the key is gone (half-open): the next call is allowed; its usable answer closes, its failure trips
  again. `AgentDecisionCallsService::closeCircuitBreakerAfter()` closes only on an answered call with a usable answer and
  no failed sibling.
- A cache outage never blocks: every method falls back to the closed reading, and the call's own timeout bounds the
  damage.
- **It is shared by every runner site.** One key for every runner site, every conversation and every worker: a
  transport failure at one runner site benches all of them for `breakerSeconds`, each taking its fallback without a
  request. The owner-message injection check neither trips nor closes it; the tool-result screen never reads it.
- **Harness mode.** While a recorded-run comparison runs in a console process,
  `AgentDecisionModelCircuitBreakerService::$harnessMode` keeps the breaker closed and never reads or writes the key — a
  recorded timeout must not bench the model for the replay or for another run. `AgentWorkerStateResetter` clears it
  before every worker job.

**Symptom → cause:** many `fallback:breaker` stamps right after one `fallback:transport` is the breaker working; many
`fallback:refused` is the budget or usage-envelope gate (see `ddd-module-ai-specialist`); `fallback:error` on every call
is a missing registry prompt or key — read the warning log.

## Split calls

The turn start sends its batch, does the loop's own work, then receives (`sendDecisionBatchWithoutBreakerCheck()`,
`receiveDecisionBatch()`). `ArgusAgentDecisions` writes the requests within `REQUEST_WRITE_MAX_SECONDS`, gives the
transport a longer timeout (`TRANSPORT_TIMEOUT_MINIMUM_SLACK_SECONDS` of slack) and enforces the decision deadline on
receive, counted from when the requests were written. When the caller's own work took longer than the decision timeout
and left the model less than its time (`wasDecisionTimeoutSpentOnTheCallersOwnWork()`), a missing answer says nothing
about the model and does not trip. If the loop never reaches the receive,
`AgentTurnStartDecisionBatchService::receiveTurnStartDecisionsNotApplied()` still receives, so the call's usage is
booked and its transfers closed.

## Cost

A decision call is booked like any model call: the model's price row times the reported tokens, with the provider's
reported cost as a cross-check, under the class's registry prompt and scope, against the account's usage cap (the
budget bypass grants of `ddd-ai` apply). The usage is booked **before** the answers are judged — an answer the code
cannot read still cost money. The billed account is the billing entity of the conversation's agent space
(`AgentSpacesService::findLoadedBillingEntityOfConversation()`), else the conversation's account. The default
model-call gate of `ddd-ai` (`AIModelCallGateService`) refuses nothing; a host gate that refuses a locked or erased
agent space, a call without a usage envelope or a call over budget turns that into `fallback:refused`. With the
default gate a decision class whose registry prompt does not load is sent and billed, and fails when the usage is
booked (`fallback:error`, the spend unbooked).

## The decision statistics

`DDD\Domain\AI\Services\Decisions\AgentDecisionStatisticsService::buildStatistics()` reads the turn-events table (read-only) for one site and one window
(`DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics\AgentDecisionStatisticsRequest`: `site`, `from`, `to`, `cutoff`, optional `mode`, `includeEval`,
`withAccrualArithmetic`; `forLastDays()` gives the last seven days with a cutoff thirty minutes ago). It answers an
`AgentDecisionStatistics` of titled sections (`AgentDecisionStatisticsSection`), in print order:

- the reconciliation over the turns' terminal rows by outcome and arm;
- decisions per day, mode and arm: applied, would-preload and would-apply counts, fallbacks, average and maximum
  latency;
- skip reasons; follow-up passes of the skill preload;
- the turn-start request latency, p50 and p95;
- per site: for `turnClosing` the decisions by turn-closing mode, who decided and fallback reason with the cost of the
  fallbacks, bounce signals per run and the turn's wall clock; for `reasoningEffort` the raise share by precondition and
  the call time per effort; for `stallRecovery` the moves by shape and the forced re-drives; for `skillPreload` the
  agreement of shadow picks with what the chat model loaded afterwards, per-skill precision, top confusions, a sweep of
  the floors over the recorded probabilities, and a "Preload outcomes" section (were the preloaded skills' tools used)
  that appears only when the host writes `preloadOutcome` turn events (`AIConversationTurnEvent::KIND_PRELOAD_OUTCOME`)
  — the package declares that kind and reads it, but writes none, so without the host's events the section never
  appears.

The skill-preload agreement carries a 95 % interval (`AgentDecisionWilsonInterval`).

**What it can measure.** Only sites that write a `decision` turn event. Per-site sections exist for `turnClosing`,
`reasoningEffort`, `stallRecovery` and `skillPreload`; the other event-writing sites (plan-item preload, plan dynamics,
direct widget, turn-start line, owner consent, and a host site that writes its own event) get the generic sections.
Reply language, the owner-message screen and the tool-result screen write no event: the service shows nothing for
them.

- For an event-writing site: read its statistics in `shadow` before you switch it `on`, and again after a question or
  threshold change.
- For reply language and the owner-message screen: read the decision log (`AgentWorkingState::$decisions`, the site,
  `decidedBy` and the outcome of each record) on a sample of conversations under `shadow`.
- For the tool-result screen: count the call rows' `injectionScreening` values (`flagged` under `shadow`, `failed`,
  `pending`) for the marked tools.

## Eval traffic

Evaluation runs are excluded twice: the `evalRun` marker the run settings put on every turn event, and the accounts
`DDD\Domain\AI\Services\Conversations\Agent\AgentEvalAccountsService::getEvalAccountIds()` names. The package default names none; a host with evaluation accounts
puts a same-path subclass of `AgentEvalAccountsService` under its own root namespace.

## The host's statistics command

The package ships the service, not the command. A host writes a console command that builds an
`AgentDecisionStatisticsRequest` from its options (site, window, mode, eval included), calls `buildStatistics()` and
prints each section in order — a section is built by `AgentDecisionStatisticsSection::withTable()` (a title, column
names, rows) or `AgentDecisionStatisticsSection::withTextLines()` (a title, lines). Print its `title`, the table of `columns` and `rows` when `columns` is not empty, then its
`textLines` and `note` — or print `toLlmMarkdown()` as it is. It reads, never writes, and needs no
usage envelope. An admin endpoint or panel that shows the statistics and the decision layer
(`AgentDecisionLayerStatus`, `AgentDecisionModelSettingsSummary`) is equally the host's — an optional host duty listed
in the duty table of `ddd-module-agents-specialist`; the control panel around it is
`ddd-module-agents-observability-specialist`'s.
