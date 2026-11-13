# Design decisions of the decision layer and the guards

**Reader:** package maintainer

Contents: a second model class at the loop's boundaries · the model answers facts, PHP decides · questions are code · removable without effect · settings in committed config, a row per conversation · one batch per boundary, one runner · fail-open sites · one shared breaker that trips only on transport · a decision is an ordinary model call · deterministic first · detection is the lowest tier · provenance separates the owner from an attacker · filter the owner message, remove the tool result · the gate rules are one replaceable text · consent is read from the conversation's own rows · consent is checked in the tool · one owner row authorises one execution · a bounded log and a durable event table

## A second model class at the loop's boundaries

**Rule.** The loop's judgments that are neither the answer nor the customer's work — the reply language, whether a
final text delivers or announces, which skill to load, which move gets a stalled model unstuck — are asked of a
decision model: typed answers with probabilities over a small state, no text output, never on the wire the chat model
sees.

**Reason.** Made inside the chat model's own generation, such a judgment competes with everything else in the prompt
and loses (a configured language beats the language the owner writes in); made by counters, it is safe but late and
blind to the first occurrence. A decision model answers a batch of questions at a latency that stays flat in question
count and state size, at a fraction of a chat call's cost, and cannot drift into prose.

**Rejected.** A side question to the chat model: slower, disturbs its prompt cache, and its answer competes with the
prompt. More prompt rules: the prose grew without closing the class of error. A small chat model as a classifier:
slower per question set, free-text failure modes, no calibrated probabilities. Counters only: kept where they suffice,
but structurally late.

**Would change if.** The chat model's own adherence made the judgments reliably inside its generation, or a decision
model's latency or cost stopped being flat in the size of the batch.

## The model answers facts, PHP decides

**Rule.** A question asks for facts (the language written, the probability that a text announces); the precedence,
the threshold and the action are resolved in PHP on the stamp (`resolveByRecency()`, `resolveWouldBounce()`,
`resolveMoveToApply()`). A question that encodes a precedence is a defect.

**Reason.** Code is testable and stable; a model asked for a precedence answers the question as worded, not as meant,
and its errors cannot be fixed without re-measuring the question. Thresholds kept in code can be refitted on recorded
probabilities without touching the question.

**Rejected.** Asking the model for the final action: untestable, and a wrong precedence would be invisible in the
answers. Encoding thresholds into criteria text: numeric anchors worsen calibration.

**Would change if.** Never for precedence; thresholds move with each refit on recorded labels.

## Questions are code

**Rule.** Every question set is a pure public method (`buildQuestions()`), pinned by a unit test, written to the
question rules of [adding a decision site](adding-a-decision-site.md), and probed on labelled real-shaped cases before
it is wired (`ArgusAgentProbeDecision`).

**Reason.** A malformed question measured near zero on cases the well-formed one answered fully; criteria written
wrong scored below chance. The question text is the program; a change to it is a change to behaviour.

**Rejected.** Treating a question as prompt prose that can be tuned in place: results obtained with a malformed
question are no evidence about the model.

**Would change if.** A decision model became robust to question form — no measurement points that way.

## Removable without effect

**Rule.** Every site is gated by `off` / `shadow` / `on`; `off` makes not one call and changes not one byte; `shadow`
asks, records and never acts; every site is an addition beside the behaviour that runs without it. The package default
is `off` everywhere.

**Reason.** A second application must run the loop without a decision model in its catalog, and a site must be
measured on real turns (shadow) before it acts. A site that replaced the old behaviour could not be switched off in an
outage or a regression.

**Rejected.** Replacing the deterministic paths outright: no fallback, no comparison arm. A boolean per site: no way to
measure without acting.

**Would change if.** A site proved better than its fallback on every measured arm long enough that keeping the fallback
cost more than it protects — then the fallback, not the switch, would go.

## Settings in committed config, a row per conversation

**Rule.** The settings are one committed file (`decisionModel.php`, replaced whole by the host) read by
`AgentDecisionModelSettings::current()`; a conversation's `RunSettings` rows come last, set once at the start of a new
conversation or later by one admin-gated override that records who and when.
No environment variable feeds the layer; an evaluation arm is a conversation row.

**Reason.** A setting in the environment differs silently between machines and cannot be reviewed; a per-conversation
row lets an operator or an evaluation switch one conversation without touching any other, and is recorded with who and
when.

**Rejected.** Environment overlays on the file: hidden state, hard to reproduce. A database table of settings: one
more writer and no review. Merging the host file into the package file: a host could not be sure what runs.

**Would change if.** Hosts needed different settings per tenant at run time — then a tenant layer would sit between
the file and the row.

## One batch per boundary, one runner

**Rule.** All decision calls of one loop boundary go into one `ArgusAgentDecisions` batch (the turn start folds its
sites into one request; other children run in parallel under settle mode), and every turn-path call goes through
`AgentDecisionCallsService`, which checks the breaker, runs, classifies and trips. The background tool-result screen
is the one call outside it: it loads its batch directly, off the turn path, and keeps its outcome on the call row.

**Reason.** The boundary pays the latency once. One runner means one classification and one breaker rule; hand-written
copies of the mapping drifted apart before.

**Rejected.** One call per site: latency multiplies. Driving the Argus operations by hand: the settle mode, the timeout
and the breaker would be re-implemented per site.

**Would change if.** Sites at one boundary needed different models or endpoints that cannot share a batch.

## Fail-open sites

**Rule.** Every site behaves as without the decision when no answer comes, and catches every failure except the lost
run claim. The one exception is owner consent, which fails closed.

**Reason.** The decision model is an extra dependency on the answer path; its outage must cost quality, never a turn.
For owner consent the safe direction is the opposite: a wrong yes is irreversible, a wrong no costs one question.

**Rejected.** Retrying the decision call within the turn: the timeout is already the budget. A deterministic fallback
for consent: it would let the agent act on a guess about the owner's words.

**Would change if.** A site's fallback became more harmful than a delayed turn.

## One shared breaker that trips only on transport

**Rule.** One key in the shared cache, opened for `breakerSeconds` by a transport failure (timeout, connection, the
transport status codes) of any runner site; never by our own gate's refusal, our 4xx or our own error; closed by a usable
answer; read as closed on a cache outage; untouched in harness mode.

**Reason.** Process memory does not cross workers; one key does. A misconfiguration must stay loud, not be hidden by a
benched model. A cache outage must not take the layer down.

**Rejected.** A breaker per site or per worker: an outage of the provider would be hit once per site and worker. A
breaker that fails open on a cache error (reads as open): a cache hiccup would bench the layer.

**Would change if.** Sites used different providers — then one breaker per provider.

## A decision is an ordinary model call

**Rule.** The decision protocol overrides only the two chat-protocol methods of the language-model trait
(`ArgusAgentDecisionTrait`); scope, envelope, eligibility, budget gate, cost booking and usage log stay the trait's.
Each decision class names a registry prompt, which keys the cost counters. Usage is booked before the answers are
judged.

**Reason.** The spend must be visible, capped and attributed like every other model call; a copy of the gates would
drift. A response that cannot be read still cost money.

**Rejected.** A separate lightweight client for the decision model: no budget gate, no booking.

**Would change if.** Never while decisions cost money.

## Deterministic first

**Rule.** A judgment a counter, a fold over persisted rows or a field in memory can make stays deterministic: the
plan-item preload, the checked-stop counter, the effort precondition. The decision model takes what needs a reading of
text or intent.

**Reason.** Deterministic paths are exact, free and testable; every model call adds latency and an error rate.

**Rejected.** Asking the model where PHP already holds the fact.

**Would change if.** A deterministic rule proved structurally wrong for a class of cases that only a reading of text
can separate.

## Detection is the lowest tier

**Rule.** The injection screens are a best-effort filter. The load-bearing controls are structural: rights scoped by
agent space, owner consent on irreversible steps, mandates for outward actions, and no write path that trusts text
from a tool result.

**Reason.** A model processes instructions and data in one channel; a benign-looking, action-triggering payload is
invisible to any classifier, and adaptive attacks push every detection defence back to high success rates. A finite
test battery proves nothing about the next attack.

**Rejected.** Making the screen the security boundary. Dropping detection altogether: it stops the opportunistic
attacker cheaply.

**Would change if.** Never toward "detection as the boundary".

## Provenance separates the owner from an attacker

**Rule.** The owner's own top-level request about the owner's own data passes the screen whatever its verb; the same
action is an attack when embedded in untrusted content, attributed to a third party, or sending data to a foreign
destination.

**Reason.** A screen trained to block "change, delete, export" blocks the owner's legitimate self-service and does the
authorization layer's job badly; separating by provenance brought false alarms on real owner messages to zero without
opening attack holes.

**Rejected.** Blocking by action verb. A judgement of provenance by the chat model itself.

**Would change if.** Owners could not be authenticated, so that "the owner said so" stopped meaning anything.

## Filter the owner message, remove the tool result

**Rule.** A blocked owner message stays stored and is replaced by a filter notice only in what the model sees; a
flagged tool result is replaced by a removal notice in the background, and a candidate written on it is discarded and
the step re-driven once. The turn never waits for a screening verdict.

**Reason.** The owner should not be refused or alarmed by a false positive; the model must not act on the payload.
Waiting for the background screen would add its latency to every tool call that fetches content.

**Rejected.** A middle tier with a caution signal to the model: measured, it changed nothing. Gating write tools on a
model-side judgement of provenance. Waiting for the verdict before the next step.

**Would change if.** Screening became fast enough to run inside the tool call at no measurable cost.

## The gate rules are one replaceable text

**Rule.** The owner-message gate rules are a prompt file the host replaces whole; the question asks whether those rules
would pass the message. A change is made only with a re-measurement on an attack battery and on a replay of real owner
messages.

**Reason.** Measured numbers belong to exactly one text. A host's owners and data differ; the rules must name them.
The long rule text beat a short question on attacks let through.

**Rejected.** Merging host rules into the package's: no measured text would remain. A short single-sentence question:
let more attacks through.

**Would change if.** A decision model reached the same numbers with a short question.

## Consent is read from the conversation's own rows

**Rule.** The consent decision reads only the owner's own text rows after the tool's last use (never a signal, a row
the platform wrote, or a filtered row) and a request the tool built from what it loaded. Both probabilities — the
assistant named exactly this step, the owner agreed afterwards — must reach one threshold for every kind of step.

**Reason.** The model cannot write a consent into its own arguments, and a yes to another target or value is no yes to
this step. A probe of recorded dialogues put one threshold above every trap and below every genuine consent.

**Rejected.** A consent widget the owner clicks: a widget answer can be dictated and does not carry the explained
consequence. A higher threshold for deletions: it refused clear yeses.

**Would change if.** A channel appeared where the owner's words are not stored as rows.

## Consent is checked in the tool

**Rule.** The tool calls the consent step after its free refusals and before its write; the executor only translates
a missing consent into a gate refusal.

**Reason.** Only the tool knows its target, its consequence and its own refusals; an executor gate before the tool ran
would record a consent for calls the domain then refuses, using up the owner's answer.

**Rejected.** An executor gate on the attribute alone.

**Would change if.** The executor could build the target and consequence without the tool's domain code.

## One owner row authorises one execution

**Rule.** A use record per tool and owner row, a window and a rising refusal floor, all written by guarded single
statements fenced on the run claim; a write that changed nothing releases the record.

**Reason.** Two parallel calls, a retry after a lost worker, or a forked call must not spend one yes twice; a refused
row must never authorise later. Single guarded statements behave the same on both database engines.

**Rejected.** Read-modify-write of the working state: races. A consent valid for the rest of the turn: one yes would
cover several destructive steps.

**Would change if.** A tool needed one consent for a batch of steps — then the request would name the batch.

## A bounded log and a durable event table

**Rule.** A decision call writes a record into a bounded log on the working state (what was sent, what came back,
what PHP made of it) and, for most sites, a durable turn event; the statistics read only the events and exclude
evaluation traffic twice. Reply language and the owner-message screen keep only the log, owner consent only the event,
the tool-result screen only its call row — the statistics cannot measure those sites.

**Reason.** An operator needs the full trace under the owner message it belongs to; a measurement needs every decision
of every conversation without loading the conversations. Evaluation traffic would bias every rate.

**Rejected.** Columns on the message rows: never loaded into context but bloating every read. An unbounded log on the
working state: it grows with the conversation.

**Would change if.** The event volume outgrew the table — then an aggregate per day would replace raw rows.
