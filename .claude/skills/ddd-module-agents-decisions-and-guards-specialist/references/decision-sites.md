# Decision sites

**Reader:** host developer and package maintainer

Contents: what a decision site is · the settings and their precedence · the turn-start batch · which site goes through what · the sites one by one · the per-conversation switches · reading what a site decided · tests that pin the sites

## What a decision site is

A decision site is one place in the loop where a decision model answers typed questions over a small `state` object:
`noul` (the probability that a statement is true) or `choice` (one named option, its confidence and, where asked,
the probability of every option). The answers are typed into a stamp (a subclass of `DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecision`), PHP resolves the
consequence, and the loop acts on it only when the stamp `isApplied()`. Every stamp carries `decidedBy`:

| `decidedBy` | Meaning |
|---|---|
| the value of `AgentDecision::DECIDED_BY_DECISION_MODEL` | the model answered under `on`; the loop acts |
| `shadow` | the model answered under `shadow`; recorded, never acted on |
| `fallback:breaker` | the shared circuit breaker was open; no call was made |
| `fallback:transport` | timeout, connection error, an HTTP 408, 425, 429, 500, 502, 503, 504 or 529, or no status at all (any other status without answers is `fallback:unparsable`) |
| `fallback:refused` | our own gate refused the call (budget, scope eligibility, a missing usage envelope or scope) |
| `fallback:unparsable` | the call answered, but the answer set was not usable |
| `fallback:error` | our own code failed (a missing registry prompt, a type error) — logged as a warning |
| `fallback:ownWork` | a split call: the turn's own work between send and receive outlasted the decision timeout |

Some stamps add their own values: the reply language knows `anchor` and `platform`, the plan dynamics `platform`, the
reasoning effort `precondition`. The strings are persisted and read by the statistics.

All decision classes of the loop call the same endpoint and model (`AIModel::MODEL_TYPESAFE_JEV_1_13`) under the usage
scope `AgentAIUsageScope::AI_AGENT_DECIDE_TURN`, except the tool-result screen, which books under
`AgentAIUsageScope::AI_SECURITY_DETECT_INJECTION`. Each names its registry prompt from `AgentAIPromptNames`; the prompt
file documents the questions and is never sent.

## The settings and their precedence

`DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings::current()` parses `AI.decisionModel` (the file `decisionModel.php`) with
`fromConfigured()`. Unknown values fall back safely: an unknown global mode is `off`, an unknown site mode is `off`
for that site, a non-positive timeout is `DEFAULT_TIMEOUT_SECONDS`, a non-positive breaker time
`DEFAULT_BREAKER_SECONDS`. The keys:

| Key | Values | Default |
|---|---|---|
| `mode` | `off`, `shadow`, `on` | `off` |
| `sites` | site → `off` / `shadow` / `on` (`cohort` for `skillPreload`); `planDynamics` → its two switches | none listed |
| `turnClosingMode` | `deterministicCounter`, `decisionModel`, `counterThenDecisionModel` | `deterministicCounter` |
| `timeoutSeconds` | the per-call timeout | 2.0 |
| `breakerSeconds` | how long the breaker stays open after a transport failure | 60 |

`siteMode()` is the environment's mode of a site: the global `off` wins; `planDynamics` is derived from its two
switches; an unlisted site is `off` except the two in `DEFAULT_ON_SITES`; a listed site without its own mode takes the
global mode. `siteModeForConversation()` then applies the conversation's `RunSettings`: the kill switch
`decisionModelOff` silences every site, a site row overrides as listed in [the switches](#the-per-conversation-switches).
A row never lifts a site the environment has `off` — except the plan-dynamics switches, which select the site's mode
outright under a global `on` or `shadow`.

For a unit test that needs a mode the config does not run, `AgentDecisionModelSettings::useForCurrentProcess()` makes
`current()` answer with given settings for the rest of the process (null restores the file).

## The turn-start batch

Before step 1 of an owner turn the loop calls `AgentTurnStartDecisionBatchService::sendTurnStartDecisions()`, does its
own work while the requests travel, then `applyTurnStartDecisions()`. Every eligible turn-start site contributes its
questions to ONE request (`ArgusAgentTurnStartDecision`, the batch key `turnStart`); the sub-skill passes of skills
loaded before the turn and the owner-message injection check are parallel children of the same `ArgusAgentDecisions`
batch. The batch is classified once (`AgentTurnStartBatchResult`) and every site reads its own answer.

- Built in this order: skill preload (roots), the sub-skill passes of loaded skills, reasoning effort (applied at once
  when its precondition decides), the reopening of a plan the previous owner turn finished, plan dynamics, direct
  widget, reply language, turn-start line, the owner-message injection check.
- Applied in this order: owner-message injection, reply language, skill preload (with its follow-up passes), reasoning
  effort, plan dynamics, direct widget, turn-start line, each sub-skill pass, then the request's own turn event.
- The working-state slices the sites stamp are collected and written once at the end, then the turn events.
- Nothing asked (every site ineligible) = no request at all. An open breaker = no request; every site gets
  `fallback:breaker`.

## Which site goes through what

"Runner" = the call goes through `DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService`: breaker check, outcome classification, the shared
fallback stamps. "Log" = an `AgentDecisionRecord` in the bounded decision log. "Event" = a durable turn event of kind
`AIConversationTurnEvent::KIND_DECISION` — the only thing the [statistics](circuit-breaker-and-statistics.md) read.

| Site | Runner | Log | Event |
|---|---|---|---|
| `replyLanguage` | yes (turn-start batch) | yes | **no** |
| `skillPreload` | yes (turn-start batch, and its own batch after a chat-model load) | yes | yes |
| `planItemSkillPreload` | no model call | no | yes |
| `reasoningEffort` | yes (turn-start batch) | yes | yes |
| `planDynamics` | yes (turn-start batch) | yes | yes |
| `directWidget` | yes (turn-start batch) | yes | yes |
| `turnStartLine` | yes (turn-start batch) | yes | yes |
| `ownerMessageInjection` | a child of the turn-start batch, but it neither trips nor closes the breaker | yes | **no** |
| `turnClosing` | yes (own batch) | yes | yes |
| `stallRecovery` | yes (own batch) | yes | yes |
| `toolResultInjection` | **no**: loads its batch directly, ignores the breaker | **no** | **no** — only the call row's `injectionScreening` |
| `ownerConsent` | yes (own batch) | **no** | yes |

## The sites one by one

For each site: who calls it and when, what it sees, what it returns, what the loop does, what happens without an
answer or switched off. The constant is on `AgentDecisionModelSettings`.

### `replyLanguage` (`SITE_REPLY_LANGUAGE`)

- **When:** the turn start, once per owner message. Not for a delegation, a turn without owner words, or a chunk
  resume of a decided message. A run without the owner present gets a stamp from the platform (the configured
  language, `decidedBy` `platform`) and no call.
- **Sees:** the owner's recent words, the recent turns, the standing directives as the context renders them, the
  configured and the supported language codes (`AgentSpacesService::getActiveInterfaceLanguageCodes()`).
- **Returns:** three facts — the language the owner writes, the one the owner asks for, the one the directives
  prescribe — each with an abstention.
- **Consequence:** `AgentReplyLanguageDecision::resolveByRecency()` picks by recency (request now → written now → the
  younger of the conversation's anchor and the newest directive → the configured language). The stamp lands on the
  working state; every context build of the turn names the language.
- **Without an answer:** the configured chain decides; if it resolves to the conversation's own earlier applied
  decision (the anchor), that is applied under `on` with `decidedBy` `anchor`, so an outage never flips the owner back.
- **Service:** `AgentReplyLanguageDecisionsService`.

### `skillPreload` (`SITE_SKILL_PRELOAD`)

- **When:** the turn start, when the turn was started by the owner's words (a submitted form counts), never in a
  delegation or a purpose that writes its own owner text, never twice for one initiator. Also after a `load_skills`
  call of the chat model (`preloadSubSkillsOfSkillsLoadedByTheChatModel()`, at most
  `SKILL_PRELOAD_MAX_LOADED_SKILLS_ASKED` skills).
- **Sees:** the offered roots (or the sub-skills a root unlocks) with each skill's decision catalog entry, the loaded
  skills with their tool summaries, the recent turns, the task plan, the stored facts of the agent space, the meta tools,
  and the host's facts from `DDD\Domain\AI\Services\Conversations\Agent\AgentDecisionFactsService::collectSkillPreloadFacts()`.
- **Returns:** a distribution over the offered skills and `none`.
- **Consequence:** `AgentSkillPreloadDecision::resolvePreloadFromDistribution()` picks the top skill when its probability
  reaches `MINIMUM_TOP_PROBABILITY` and `none` stays at or below `MAXIMUM_NONE_PROBABILITY`. Under `on` the pick is
  admitted through the offer policy inside one claim-fenced write and loaded before the first model call; a root with
  sub-skills starts a follow-up pass, at most `AgentSkillPreloadDecision::MAX_PASSES` per turn. Under `shadow` the
  admission runs as a dry run.
- **Without an answer:** nothing is loaded; the chat model loads skills itself.
- **Default:** `on` when not listed. `cohort` splits accounts by `resolveArmForAccountId()` into control, shadow and on.
- **Service:** `AgentSkillPreloadDecisionsService`.

### `planItemSkillPreload` (`SITE_PLAN_ITEM`) — deterministic

- **When:** before every model call of a purposed conversation (`admitSkillsForNextPlanItem()`).
- **Sees:** the skills the next owed plan items name (`TaskPlanItems::collectSkillNamesDueNow()`); no model.
- **Consequence:** under `on` the not-yet-loaded ones are admitted through the offer policy; under `shadow` only
  recorded. The kill switch reaches it too. **Default:** `on` when not listed.

### `reasoningEffort` (`SITE_REASONING_EFFORT`)

- **When:** the turn start of an owner turn; not for a delegation, a fixed effort, a signal-started turn or a chunk
  resume.
- **Precondition in PHP** (`AgentReasoningEffortDecision::resolvePreconditionReason()`): the model is asked only for a
  message of at least `MINIMUM_OWNER_MESSAGE_WORDS` words, a reply to an identity or class proposal of the previous turn
  (a tool marked `MCPToolProposesIdentityOrClass` or such a widget), or after a visible tool failure in the previous
  turn. At the ceiling nothing is asked.
- **Returns:** four probabilities (disputes a claim, rejects an identity or class, asks for a different course, needs
  weighing of conflicting requirements).
- **Consequence:** `resolveEffortFromAnswers()` raises above the active floor only (`low`, `medium`, `high`), the row's
  pin is a floor, the ceiling clamps (`AgentReasoningEffortLimits`). The effort is stamped, and every chat-model step
  call of the turn is sent with it (`resolveDecidedReasoningEffortForCurrentTurn()`), the stall-forced re-drive
  included; the activity narrator, compaction, the supervisor and the decision calls are not.
- **Without an answer:** the floor — the configured effort raised by the purpose's and the plan item's minimums and the
  pin; never lower. The base is the model catalog's agentic effort or the purpose's own, not a setting of this file.
- **Service:** `AgentReasoningEffortDecisionsService`.

### `planDynamics` (`SITE_PLAN_DYNAMICS`)

- **When:** the turn start of an owner text turn, when no plan with owed open items is active; never in a delegation, a
  schedule-bound run or a purpose that seeds its plan or works on alone.
- **Returns:** the probability that the request needs a working plan.
- **Consequence:** `AgentPlanDynamicsDecision::resolveBand()` — `required` at or above `REQUIRED_THRESHOLD` (the chat
  model is told to create the plan first), `direct` at or below `DIRECT_THRESHOLD`, `middle` between. The directive
  reaches the chat model only under `on` (switch 2). Before asking, a plan the previous owner turn finished is reopened
  when the owner's next message continues it (`decidedBy` `platform`). At an accepted final answer a finished
  model-created plan is retired into the finished set under `on`
  (`AIConversationTaskPlanRecordService::retireFinishedTaskPlanIfDue()`); under `shadow` only `wouldRetireFinishedPlan`
  is recorded.
- **Service:** `AgentPlanDynamicsDecisionsService`.

### `directWidget` (`SITE_DIRECT_WIDGET`)

- **When:** the turn start, for a conversation whose reader is taught the composed-view kit; not scheduled, not a
  delegation, not a purpose that writes its own owner text.
- **Sees:** the owner message, the recent turns, and the `DIRECT_WIDGET_WHEN` line of every widget class that has one.
- **Consequence:** the same distribution rule as the skill preload; under `on` the picked widget type is announced at
  the wire tail (`AIConversation::getDecidedDirectWidgetTypeOfCurrentTurn()`). An answer that writes the widget ends
  the turn normally. An answer that lacks the widget's block — no work tool ran, no `delegate` call, the text does not
  end on a question to the owner — is the direct-widget-missing stall of `AgentStallClassification`: one nudge, no
  stall-recovery decision, no forced call (the stall handling: `ddd-module-agents-loop-specialist`). It decides
  nothing when the skill preload of the same turn loaded a skill.
- **Service:** `AgentDirectWidgetDecisionsService`.

### `turnStartLine` (`SITE_TURN_START_LINE`)

- **When:** the turn start, only while an owner follows the turn (present in the chat, or on a call with a reader
  attached).
- **Returns:** the probability that the agent must read, search or change something before it can answer.
- **Consequence:** at or above `AgentTurnStartLineDecision::MINIMUM_WORK_BEFORE_ANSWER_PROBABILITY` the activity stack
  opens with one line written by the activity narrator (never by the decision model).
- **Service:** `AgentTurnStartLineDecisionsService`.

### `ownerMessageInjection` (`SITE_OWNER_MESSAGE_INJECTION`)

A parallel child of the turn-start batch: see [injection defence](injection-defence.md).

### `turnClosing` (`SITE_TURN_CLOSING`)

- **When:** a candidate final text of a conversation whose purpose works on without the owner, through
  `AgentFinalTextAcceptanceService::bounceCandidateTextIfTheCheckedStopAsks()`. The pure gate
  `AgentCheckedStopPolicy::shouldAskTurnClosingDecision()` asks only when a plan has open items that owe evidence and
  none blocked on the owner's action, the bounce cap is not reached, no item was resolved this turn, no work tool ran,
  the turn is not a fold turn reading sub-agent results, and the answer text is not empty. Every other conversation
  runs the deterministic counter alone.
- **Returns:** two probabilities — the text announces work instead of delivering it; the text reports changes as made.
- **Consequence:** a bounce when the first reaches `AgentTurnClosingDecision::MINIMUM_ANNOUNCE_PROBABILITY`. Where the
  model may be asked, the counter always judges first and the model is asked only when the counter does not bounce.
  `turnClosingMode` and the row `decisionModelTurnClosing` are recorded on the stamp but change no sequence in this
  release: the model-first and counter-proposes orders (`AgentFinalTextAcceptance`) apply only where the counter is
  not mandatory, and no caller asks the model there. What decides is the site mode (`off` / `shadow` / `on`).
- **Without an answer:** the counter's verdict.
- **Its own batch** (key `turnClosing`), one child, through `runDecisionBatch()`.

### `stallRecovery` (`SITE_STALL_RECOVERY`)

- **When:** asked from the stall handling (`AgentStallRecoveryService::recoverStalledStep()`) at the second
  consecutive stall — the first stall already got its nudge — through `AgentStallRecoveryService::decideMove()`, at most
  once per owner turn; not for a delegation, a purpose that works on alone, a turn that reads sub-agent results, or an
  answer-shape stall (a missing dictated block or direct widget, widget-tree findings, code or a formula in prose).
- **Sees:** the stall shape, the stalled text, the owner message, the next plan item, the callable work tools, the
  loadable capabilities; PHP offers only moves the loop can take.
- **Returns:** a choice over `call_a_work_tool`, `load_required_capability`, `end_the_turn_with_text`, `none`.
- **Consequence:** `AgentStallRecoveryDecision::resolveMoveToApply()`; a forced work-tool call needs
  `MINIMUM_FORCED_CALL_PROBABILITY` and `MINIMUM_FORCED_CALL_MARGIN`, the two text moves only a unique top choice.
- **What the loop does with it:** only under `on` and only an applied move is returned; `shadow`, a fallback, `none`
  or a move below its floor return nothing and the ladder runs as without the site. An applied `call_a_work_tool`
  forces one work-tool call now and replaces the ladder's own last-resort forced call for the rest of the turn; the
  two text moves change only the nudge text. The ladder itself (how many nudges, the reset, the last-resort re-drive,
  the give-up) is `ddd-module-agents-loop-specialist`'s.

### `toolResultInjection` (`SITE_TOOL_RESULT_INJECTION`) and `ownerConsent` (`SITE_OWNER_CONSENT`)

Outside the turn path: see [injection defence](injection-defence.md) and [owner consent](owner-consent.md).

## The per-conversation switches

`RunSettings` holds one row per switchable site; `inherit` (or null) keeps the environment's mode.

| Row | Values | Effect |
|---|---|---|
| `decisionModelOff` | true / false | the kill switch: every site `off` for this conversation (owner consent then refuses) |
| `decisionModelSkillPreload` | `inherit`, `on`, `off` | `on` lifts `shadow` to `on`, `off` silences |
| `decisionModelTurnClosing` | `inherit`, `off`, the three turn-closing modes | `off` silences; a mode is recorded but changes no sequence today (see `turnClosing`) |
| `decisionModelReasoningEffort` | `inherit`, `off`, `low`, `medium`, `high` | `off` silences; a value pins a floor (applies under `shadow` too) |
| `decisionModelStallRecovery` | `inherit`, `on`, `off` | as the preload row |
| `decisionModelReplyLanguage` | `inherit`, `on`, `off` | as the preload row |
| `planDynamicsAskDecisionModel`, `planDynamicsModelReceivesVerdict` | `inherit`, yes, no | each overrides its environment switch |

The rows are written on two paths:

- **The start of a new conversation.** `AIConversationAgentService::driveConversation()` takes a `runSettings`
  argument (a `DDD\Domain\AI\Entities\Conversations\Messages\RunSettings`); when the call creates the conversation,
  that object is stored on it as given — its decision rows included, without an admin check and without the audit
  fields. On an existing conversation the argument is not written. This is how an evaluation starts a conversation
  with its arm.
- **The override of an existing conversation** (or of the new one, after the start).
  `DDD\Domain\AI\Services\Conversations\AIConversationsService::applyAdminRunSettingsOverride()` takes a
  `DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride` and writes only when its caller passes
  `adminIsOperatingSession` as true; it stamps who set each row and when, and refuses an invalid value with a
  `BadRequestException`. It checks no role itself: the caller establishes that an administrator operates the session,
  as `driveConversation()` does with `AuthService::isCurrentSessionAdmin()` (the authenticated or admin account's
  `isAdminWithAccessToAllResources()`); `driveConversation()` also accepts a `runSettingsOverride` argument and applies
  it the same way. Build the override with `RunSettingsOverride::fromValues()` (named arguments
  `decisionModelSkillPreload`, `decisionModelTurnClosing`, `decisionModelOff`, `decisionModelReasoningEffort`,
  `decisionModelStallRecovery`, `decisionModelReplyLanguage`, `planDynamicsAskDecisionModel`,
  `planDynamicsModelReceivesVerdict`, and three wire settings; it answers null when all are null), or with
  `newInstance()` and its properties.

Switch one site off for an existing conversation, from a host endpoint or console command run by an administrator:

```php
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Services\AuthService;

/** @var AIConversationsService $aIConversationsService */
$aIConversationsService = AIConversation::getService();
$conversation = $aIConversationsService->find($conversationId);
$runSettingsOverride = RunSettingsOverride::fromValues(decisionModelStallRecovery: 'off'); // the kill switch: decisionModelOff: true
$aIConversationsService->applyAdminRunSettingsOverride(
    $conversation,
    $runSettingsOverride,
    AuthService::instance()->isCurrentSessionAdmin()
);
```

`DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionLayerStatus::forConversation()` gives the read side
(every site's effective mode and the resolved rows), `AgentDecisionModelSettingsSummary` the committed settings for a
panel.

## Reading what a site decided

- **The stamp** on the working state (`replyLanguageDecision`, `skillPreloadDecision`, `reasoningEffortDecision`,
  `planDynamicsDecision`, `directWidgetDecision`, `turnStartLineDecision`, `turnClosingDecision`,
  `stallRecoveryDecision`) — the value the loop reads.
- **The decision log** — `AgentWorkingState::$decisions`, one `AgentDecisionRecord` per call (site, owner message,
  `decidedBy`, applied, elapsed time, model version, failure reason, the state as sent, the raw answers, the resolved
  consequence), bounded by `MAX_DECISION_RECORDS`, written by `AgentDecisionCallsService::recordDecision()`.
- **The turn events** — a durable `decision` row for the sites marked so in [the table](#which-site-goes-through-what)
  and a `request` row per turn-start request, read by the [statistics](circuit-breaker-and-statistics.md). Reply
  language, the owner-message screen and the tool-result screen have none.
- **The call row** — for the tool-result screen only: `injectionScreening` and the highest probability on the stored
  tool call.
- **Trace lines** — `turn.replyLanguage.decided`, `turn.closing.decided`, `turn.stallRecovery.decided` and their
  siblings, when the host's trace switch is on (`ddd-module-agents-observability-specialist`).

## Tests that pin the sites

The package suite holds a test per site value object (`AgentReplyLanguageDecisionResolveTest`,
`AgentTurnClosingDecisionTest`, `AgentStallRecoveryDecisionTest`, `AgentReasoningEffortDecisionTest`,
`AgentPlanDynamicsDecisionTest`), the settings (`AgentDecisionModelSettingsTest`,
`AgentDecisionModelSettingsSitesOfAnApplicationTest`), the runner (`AgentDecisionCallsServiceTest`), the batch
(`ArgusAgentDecisionsTest`), the injection checks (`ArgusAgentInjectionDecisionsTest`,
`AgentToolResultScreeningServiceDatabaseTest`) and owner consent (`OwnerConsentsServiceDatabaseTest`,
`ArgusAgentOwnerConsentDecisionTest`). A change of a threshold or a question fails one of them first.
