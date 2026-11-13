---
name: ddd-module-agents-decisions-and-guards-specialist
description: "Explains where a typed decision model judges a step of the mgamadeus/ddd-agents loop and what guards the owner against a wrong or hostile step. Covers every decision site (reply language, skill and plan-item preload, reasoning effort, plan dynamics, direct widget, turn-start line, turn closing, stall recovery, injection screening, owner consent), modes off/shadow/on/cohort, decisionModel.php, run settings and the kill switch, fallbacks, adding a site (ArgusAgentDecisionTrait, AgentDecisionCallsService, question rules, probes), injection defence (MCPToolReturnsUntrustedContent, gate rules, sentinel-canary probe), owner consent before an irreversible step (OwnerConsentsService), the circuit breaker and decision statistics. Use when switching off or tuning a decision site, a reply comes in the wrong language, a final text is bounced, a tool answers NOT DONE or OWNER_CONFIRMATION_REQUIRED, a tool result was removed for security, decisions show fallback:breaker, or a decision site is added."
metadata:
  author: mgamadeus
  version: "0.1.0"
  module: mgamadeus/ddd-agents
---

# Agents Decisions and Guards Specialist

Where the agent loop asks a **decision model** — a small model that answers typed questions with probabilities and
never writes text — to judge one step, how a host switches, tunes or adds such a decision, and what protects the owner
and the application: the injection screens, the owner's consent before an irreversible step, the shared circuit
breaker, and the statistics that show whether a decision earns its place.

> Installation, override points, host duties: `ddd-module-agents-specialist`. The loop that consumes the decisions
> (stall ladder, checked stop, final text): `ddd-module-agents-loop-specialist`.

## Scope

| This skill | Another skill |
|---|---|
| every decision site, its mode, its inputs, its answer, its fallback | how a turn runs, the stall ladder and the checked stop as loop steps (`ddd-module-agents-loop-specialist`) |
| `AgentDecisionModelSettings`, `decisionModel.php`, the per-conversation switches | the model catalog and its agentic effort (`ddd-module-ai-specialist`) |
| the decision protocol, the batch, the runner, the breaker, the decision log | trace events, the decision rail of a control panel (`ddd-module-agents-observability-specialist`) |
| adding a decision site | the skill file fields the preload reads (`ddd-module-agents-skills-and-purposes-specialist`) |
| injection defence: the owner-message screen, the tool-result screen, the gate rules | declaring a tool and its attributes (`ddd-module-agents-tools-specialist`) |
| owner consent: the check, the reservation, the use record | the attribute that marks a tool as needing it (`ddd-module-agents-tools-specialist`) |
| the decision statistics service | mandates, standing permissions for outward actions (`ddd-module-agents-autonomy-specialist`) |

## Rules that always hold

1. **The package default asks no decision model.** The shipped `decisionModel.php` sets `mode` to `off`. A host
   switches sites on in its own `config/app/AI/decisionModel.php`, which **replaces** the package file whole (keys are
   never merged). Copy every key you keep.
2. **Settings come from the committed config, never from the environment.** `AgentDecisionModelSettings::current()`
   reads `AI.decisionModel`; the per-conversation row (`RunSettings`) comes last. An evaluation arm is a conversation
   row, not an environment variable.
3. **Precedence for one conversation:** global `mode: off` vetoes everything → the site's own mode (an unlisted site is
   `off`, except `skillPreload` and `planItemSkillPreload`, which run `on` unless listed) → the conversation's kill
   switch `decisionModelOff` → the site's row. Ask it through
   `AgentDecisionModelSettings::siteModeForConversation()`, never by reading the config yourself.
4. **`off` is not one call and not one changed byte; `shadow` asks and records but never acts; only `on` acts.**
   `cohort` is meaningful only for `skillPreload` (the account's arm decides) and reads as `shadow` everywhere else.
5. **Every site fails open, except owner consent.** No answer (breaker open, timeout, refusal by the budget or scope
   gate, an unparsable answer, our own error) means "behave as without the decision". Owner consent fails **closed**:
   no verdict is no consent, and `off` or `shadow` on `ownerConsent` refuses every gated write.
6. **The model answers facts; PHP decides.** The question never asks for a precedence, a threshold or the action. The
   thresholds are constants on the decision value objects (for example
   `AgentTurnClosingDecision::MINIMUM_ANNOUNCE_PROBABILITY`), changed only with a new measurement.
7. **Every turn-path call goes through the runner.** The turn start, skill preload passes, turn closing, stall
   recovery and owner consent call `AgentDecisionCallsService` (`runDecisionBatch()`, or `isCircuitBreakerOpen()` then
   `runDecisionBatchWithoutBreakerCheck()`): breaker check, outcome classification, fallback stamp. A new site does the
   same. The one exception is the background tool-result screen: it calls `loadDecisions()` itself, ignores the
   breaker, never trips or closes it, and records only on the call row (see the table in
   [decision sites](references/decision-sites.md)).
8. **A decision is spend like any other model call:** scope, usage envelope, budget gate and cost booking come from
   `ArgusAILanguageModelTrait`. A decision class must name a loadable registry prompt: with the default gate a missing
   one is noticed only when the usage is booked — the request was sent and billed, the booking fails, the call ends as
   `fallback:error`. Check the prompt loads before you switch a new site on.
9. **The lost run claim is the one exception that crosses a site.** Every site catches `Throwable`, traces it and goes
   on; `AgentRunClaimLostException` is rethrown.

## Traps

- **Switching `off` the whole layer also refuses every owner consent.** A tool that calls `OwnerConsentsService` gets
  NOT DONE on every agent call while `ownerConsent` is not `on`. The attribute `MCPToolRequiresOwnerConfirmation` gates
  nothing by itself — no package code reads it; only the call in the tool body does. Keep the site `on` (it needs a
  decision model in the host's catalog), or do not call the consent step.
- **Redeclaring `SITES` in a same-path subclass replaces the list.** `AgentDecisionModelSettings::getSites()` reads the
  host's constant; list the package's sites you show next to your own, or they disappear from the panels and from
  `canonicalSite()`.
- **`planDynamics` has no mode word.** Its two switches live under `sites.planDynamics`
  (`askDecisionModel`, `modelReceivesVerdict`); not asked = `off`, asked without the model receiving the verdict =
  `shadow`, both = `on`.
- **`turnClosingMode` and its per-conversation row change no sequence today.** The turn-closing decision is asked only
  under a purpose that works on without the owner, and there the counter always judges first; the model is asked only
  where the counter does not bounce. What decides is the `turnClosing` site mode alone. Every other conversation runs
  the counter alone (the sequence itself: `ddd-module-agents-loop-specialist`).
- **A transient conversation is never screened** for injections: its message ids are not real rows.
- **The breaker is shared by every runner site.** One transport failure of a runner site benches every runner site in
  every worker for `breakerSeconds`. The owner-message injection check neither trips nor closes it; the tool-result
  screen does not see it at all.
- **Not every site writes a durable row.** Reply language, the owner-message screen and the tool-result screen write
  no decision turn event, so the decision statistics cannot measure them; read their decision log or call rows.
- **A recorded-run comparison must not touch the breaker.** `AgentDecisionModelCircuitBreakerService::$harnessMode`
  keeps it closed and untouched for that process; the worker-state reset clears it.
- **Consent before a free refusal uses up the owner's answer.** Call the consent step after the tool's own refusals
  that cost nothing, right before the write.

## Template — reading a site's mode in host code

The smallest correct use of the layer in a host service: ask the mode for this conversation, do nothing when it is
`off`, act only under `on`. A full decision site (question class, stamp, record) is in
[adding a decision site](references/adding-a-decision-site.md).

```php
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Infrastructure\Services\Service;

class NoteDuplicateChecksService extends Service
{
    /** The site name the host lists in its own SITES and in its decisionModel.php `sites`. */
    public const string SITE_NOTE_DUPLICATE = 'noteDuplicate';

    public function shouldAskTheDecisionModel(AIConversation $conversation): bool
    {
        $decisionModelSettings = AgentDecisionModelSettings::current();
        $siteMode = $decisionModelSettings->siteModeForConversation(self::SITE_NOTE_DUPLICATE, $conversation);
        return $siteMode !== AgentDecisionModelSettings::MODE_OFF && !$conversation->isDelegation();
    }

    public function appliesTheVerdict(AIConversation $conversation): bool
    {
        return AgentDecisionModelSettings::current()->siteModeForConversation(self::SITE_NOTE_DUPLICATE, $conversation)
            === AgentDecisionModelSettings::MODE_ON;
    }
}
```

And the host's config file (a whole replacement of the package's):

```php
return [
    'mode' => 'on',
    'sites' => [
        'replyLanguage' => 'on',
        'turnClosing' => 'shadow',
        'ownerMessageInjection' => 'on',
        'toolResultInjection' => 'on',
        'ownerConsent' => 'on',
        'noteDuplicate' => 'shadow',
        'planDynamics' => ['askDecisionModel' => true, 'modelReceivesVerdict' => false],
    ],
    'turnClosingMode' => 'deterministicCounter',
    'timeoutSeconds' => 2.0,
    'breakerSeconds' => 60,
];
```

## Task or symptom → reference

### Host developer

| Task or symptom | Reference |
|---|---|
| Which decision sites exist, what each sees, returns and changes, how to switch one off | [decision sites](references/decision-sites.md) |
| A reply comes in the wrong language; a final text is bounced; a stall gets a forced tool call; skills load unasked | [decision sites](references/decision-sites.md) |
| Switch the layer or one site for a single conversation (kill switch, row, admin override) | [decision sites](references/decision-sites.md) |
| Add a decision of the host's own (a tool-side check, a host service) | [adding a decision site](references/adding-a-decision-site.md) |
| Write or tune a question; probe it on recorded cases before wiring | [adding a decision site](references/adding-a-decision-site.md) |
| Mark a tool whose result carries third-party text; replace the gate rules; a result reads "removed for security" | [injection defence](references/injection-defence.md) |
| An owner message shows the filter notice to the model | [injection defence](references/injection-defence.md) |
| A tool must not act without the owner's explicit yes; the tool answers NOT DONE | [owner consent](references/owner-consent.md) |
| Decisions show `fallback:breaker` or `fallback:transport`; the decision model seems down | [circuit breaker and statistics](references/circuit-breaker-and-statistics.md) |
| Measure a site before switching it `on`; build the host's statistics command | [circuit breaker and statistics](references/circuit-breaker-and-statistics.md) |

### Package maintainer

| Task or symptom | Reference |
|---|---|
| Add a site of the loop itself to the turn-start batch or as its own boundary | [adding a decision site](references/adding-a-decision-site.md) |
| Change how a call is classified, how the breaker trips or closes | [circuit breaker and statistics](references/circuit-breaker-and-statistics.md) |
| Change the order in which the turn-start sites apply | [decision sites](references/decision-sites.md) |
| Change the consent reservation, the use record, the refusal floor | [owner consent](references/owner-consent.md) |
| Why the layer is built this way, and what would make a rule fall | [design decisions](references/design-decisions.md) |

## Cross-references

- `ddd-module-agents-specialist` — installation, the transport `ai_tool_result_screening`, override points, host duties.
- `ddd-module-agents-loop-specialist` — the stall ladder, the checked stop and the final text as loop steps.
- `ddd-module-agents-tools-specialist` — the tool attributes (`MCPToolRequiresOwnerConfirmation`,
  `MCPToolReturnsUntrustedContent`, `MCPToolProposesIdentityOrClass`) and the gate-refusal answer.
- `ddd-module-agents-autonomy-specialist` — mandates: a standing permission for a class of outward actions, checked by
  the executor; a mandate never replaces an owner-consent step — a tool that calls the consent service still asks.
- `ddd-module-agents-skills-and-purposes-specialist` — the skill fields the preload reads and the purpose flags the
  sites check.
- `ddd-module-agents-observability-specialist` — the decision log in a control panel, trace events, record and replay.
- `ddd-module-ai-specialist` — the model catalog (`AIModel::MODEL_TYPESAFE_JEV_1_13`), budgets, usage envelopes.
