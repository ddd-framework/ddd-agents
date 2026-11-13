# Adding a decision site

**Reader:** host developer and package maintainer

Contents: before you add one · the steps · the decision class · the call · writing a question · probing before wiring · a site of the loop itself

## Before you add one

A decision site earns its place when the loop takes a judgment that needs a reading of text or intent and that the
chat model gets wrong inside its own generation. If a counter, a fold over persisted messages or a field the code
already holds can decide it, write that instead — the package does so for the plan-item preload and the checked-stop
counter. Name the boundary (when the question is asked) and the ONE consequence the loop or the tool would take.

Where a host site lives: in the host's own code — a host service or a tool of the host's tool package — never in the
package's turn-start batch. That batch has a fixed set of sites (`AgentTurnStartSiteInputs`); adding one there is a
package change (see [a site of the loop itself](#a-site-of-the-loop-itself)).

## The steps

1. **Name the site.** Put a lower-camel site name in the host's class at the path of `DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings`
   (it extends the package class) and redeclare `SITES` with the package sites you show plus yours, in panel order.
   The code reads `AgentDecisionModelSettings::getSites()`. A site that is only read through
   `siteModeForConversation()` works without the list; the list feeds `canonicalSite()` and `siteModesSummary()`.
2. **Switch it in the config.** Add the site to `sites` of the host's `decisionModel.php` with `shadow` first.
3. **Register a prompt name** and its file — the cost counters and usage rows key on the prompt name. The file
   documents the questions; it is never sent. With the default model-call gate of `ddd-ai`
   (`AIModelCallGateService::needsCostEstimateForBudgetCheck()` answers false) the prompt is first loaded when the
   usage is **booked**: a missing one lets the request go out and be billed, then the booking throws, the spend stays
   unbooked and the call ends as `fallback:error`. A host gate that estimates cost before the call refuses it earlier.
   **Check before you enable the site:** load the prompt by its name through the prompts service
   (`AIPromptsService::getAIPromptByName()`) in a unit test of the host, or run one probe and confirm a usage row was
   booked under the name.
4. **Write the decision class** ([below](#the-decision-class)).
5. **Write a stamp** — a value object extending `DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecision` with the typed facts, the PHP-resolved consequence and
   `isApplied()`. Set `ownerMessageIndex` (the owner message the decision belongs to) and `decidedAt`; the decision
   log and the turn event key on them. Persist the stamp where the host keeps per-conversation state, or only in the
   decision log.
6. **Book the cost on the right account.** Set both `billingEntity`
   (`AgentSpacesService::findLoadedBillingEntityOfConversation()`) and `budgetAccount` (the conversation's `account`) —
   every package site sets both; the billing entity wins, the account is the fallback when the agent space has none.
7. **Run inside a usage envelope.** Inside a tool call of a turn the turn's envelope covers the call. A host site that
   runs elsewhere — an HTTP endpoint, a console command, a message handler — opens one (the `WithAIEnvelope`
   attribute, or `AIUsageEnvelopesService::openEnvelope()` … `closeEnvelope()`); a host gate that requires an envelope
   otherwise refuses the call (`MissingAIEnvelopeException`, `fallback:refused`).
8. **Call it through the runner** ([below](#the-call)), record it, close the breaker by the one rule.
9. **Write a durable event** if the statistics should see the site: only `decision` turn events are counted. A host
   site gets the generic sections of the statistics (decisions per day, mode and arm, skip reasons, fallbacks,
   latency); the per-site sections exist only for the package's `turnClosing`, `reasoningEffort`, `stallRecovery` and
   `skillPreload`.
10. **Probe the questions** on recorded cases before the site acts ([below](#probing-before-wiring)).
11. **Ship in `shadow`**, read the decision log and the [statistics](circuit-breaker-and-statistics.md), then `on`.

## The decision class

A decision class is a value object that uses three traits: `ArgusTrait`, `ArgusAILanguageModelTrait` and
`DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisionTrait`, the last one replacing the two chat-protocol methods. Everything else — scope and envelope
checks, the eligibility of the billed agent space, the budget gate with its bypass, cost booking with the
provider-reported cost, the usage log — stays the language-model trait's. The class supplies `buildState()` (only the
fields the questions read), `buildQuestionsForRequest()` and `applyAnswers()` (type the answers, return whether the set
is usable). Read answers with `readNoulProbability()`, `readChoiceFrom()` or `readChoiceWithProbabilitiesFrom()` — the
answer space is enforced on our side; a choice outside the allowed set becomes the fallback choice you name.

```php
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisionTrait;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/systemone',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE,
    timeout: 2.0
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_TYPESAFE_JEV_1_13,
    defaultAIPromptName: 'Notes.Decisions.NoteDuplicateDecision',
    aiUsageScope: AgentAIUsageScope::AI_AGENT_DECIDE_TURN,
    enforceBudget: true,
)]
class ArgusNoteDuplicateDecision extends ValueObject
{
    use ArgusTrait;
    use ArgusAILanguageModelTrait, ArgusAgentDecisionTrait {
        ArgusAgentDecisionTrait::getLoadPayloadInternal insteadof ArgusAILanguageModelTrait;
        ArgusAgentDecisionTrait::handleLoadResponseInternal insteadof ArgusAILanguageModelTrait;
    }

    public const string QUESTION_REPEATS_A_KEPT_NOTE = 'repeats_a_kept_note';
    public const string STATE_NEW_NOTE = 'newNote';
    public const string STATE_KEPT_NOTES = 'keptNotes';

    public string $newNote = '';

    /** @var string[] */
    public array $keptNotes = [];

    public ?float $repeatsAKeptNoteProbability = null;

    /** Pure and public, so a unit test pins the question. */
    public static function buildQuestions(): array
    {
        return [
            self::QUESTION_REPEATS_A_KEPT_NOTE => [
                'type' => 'noul',
                'instructions' => 'Does `' . self::STATE_NEW_NOTE . '` say the same thing as one entry of `' . self::STATE_KEPT_NOTES . '`? '
                    . 'Judge the meaning, not the wording. `' . self::STATE_KEPT_NOTES . '` shows only the newest notes.',
                'criteria' => [
                    'true' => 'one kept note carries the same fact or the same request, even in other words or another language',
                    'false' => 'the new note adds a fact, changes a value or a date, or concerns another subject; '
                        . 'a note that only mentions the same subject is NOT the same thing',
                ],
            ],
        ];
    }

    public function buildState(): array
    {
        return [self::STATE_NEW_NOTE => mb_substr($this->newNote, 0, 2000), self::STATE_KEPT_NOTES => array_slice($this->keptNotes, -8)];
    }

    protected function buildQuestionsForRequest(): array
    {
        return self::buildQuestions();
    }

    protected function applyAnswers(object $answers): bool
    {
        $this->repeatsAKeptNoteProbability = $this->readNoulProbability($answers, self::QUESTION_REPEATS_A_KEPT_NOTE);
        return $this->repeatsAKeptNoteProbability !== null;
    }
}
```

The class needs no registration: `ArgusAgentDecisions::getDecisionChildClassesToLoad()` loads the class of every child
the batch holds. Set `billingEntity` (from `AgentSpacesService::findLoadedBillingEntityOfConversation()`) and
`budgetAccount` (the conversation's account) so the spend is booked to the right account, and `setTimeoutSeconds()`
from the settings.

## The call

Run the class as a one-child batch through the runner and stamp the outcome with the shared mapping. The code runs
inside a method of the host's site service (the constant SITE_NOTE_DUPLICATE is the host's site name, as in the
SKILL.md template); the class ArgusNoteDuplicateDecision is the host's class from above.

```php
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Infrastructure\Services\DDDService;

$decisionModelSettings = AgentDecisionModelSettings::current();
$siteMode = $decisionModelSettings->siteModeForConversation(self::SITE_NOTE_DUPLICATE, $conversation);
if ($siteMode === AgentDecisionModelSettings::MODE_OFF || $conversation->isDelegation()) {
    return null; // off: not one call, not one changed byte
}
$argusDecision = new ArgusNoteDuplicateDecision();
$argusDecision->newNote = $newNote;
$argusDecision->keptNotes = $keptNotes;
/** @var AgentSpacesService $agentSpacesService */
$agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
$argusDecision->billingEntity = $agentSpacesService->findLoadedBillingEntityOfConversation($conversation);
$argusDecision->budgetAccount = isset($conversation->account) ? $conversation->account : null;
$argusDecision->setTimeoutSeconds($decisionModelSettings->timeoutSeconds);
/** @var AgentDecisionCallsService $agentDecisionCallsService */
$agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
$decisionCallOutcome = $agentDecisionCallsService->runDecisionBatch(
    ArgusAgentDecisions::forChild($argusDecision, self::SITE_NOTE_DUPLICATE),
    $argusDecision,
    $decisionModelSettings->timeoutSeconds,
    $decisionModelSettings->breakerSeconds,
    self::SITE_NOTE_DUPLICATE
);
$decidedBy = $decisionCallOutcome->resolveDecidedBy($argusDecision->decided, $siteMode);
$agentDecisionCallsService->closeCircuitBreakerAfter($decisionCallOutcome, $argusDecision->decided);
```

Then resolve the consequence in PHP (a threshold constant on your stamp), act only when `decidedBy` is the value of
`AgentDecision::DECIDED_BY_DECISION_MODEL`, and record the call with
`DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService::recordDecision()` and an `DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionRecord` — built by your stamp's `buildRecord()`
(site, applied, elapsed ms, failure reason, state, answers, outcome, questions) or by `AgentDecisionRecord::build()`
with the named arguments `site`, `ownerMessageIndex`, `decidedBy`, `applied`, `elapsedMs`, `modelVersion`,
`failureReason`, `state`, `answers`, `outcome` and optionally `questions`. A durable row for the statistics goes
through `DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService::recordEventOfConversation()`, in this order: the conversation, the kind
`AIConversationTurnEvent::KIND_DECISION`, your site name, the owner message index, a subject (null for one decision per
turn), a request id (null), the payload array (put `decidedBy`, `applied` and `mode` in it — the statistics read those
keys), then optionally the run token and the elapsed milliseconds. Wrap the whole site in a `try` that traces any `Throwable` and rethrows
`AgentRunClaimLostException` only: a failure here must never fail the tool or the turn.

`AgentDecisionCallOutcome` classifies the call once: `answered`, `breakerOpen` (no call), `refused` (our gate),
`transportFailed` (trips the breaker), `timeoutSpentOnOwnWork`, `error` (our code, logged, never a trip). A caller with
work of its own while the answer travels splits the call into `sendDecisionBatchWithoutBreakerCheck()` and
`receiveDecisionBatch()`, after checking `isCircuitBreakerOpen()`.

## Writing a question

A decision model is only as good as its question: a malformed question measured far worse than a well-formed one on
the same cases. The rules:

- **One judgment per question.** Two facts are two questions in the same request; questions in one request are
  answered independently, so add every question the code might need and let PHP pick.
- **Backtick every state path** the question judges, and send only the fields the questions read.
- **Criteria are situations, not labels.** Say what passes, and enumerate the traps in the negative branch.
- **Name the blind spot** — what the state cannot show.
- **Never ask what code computes**: counting, arithmetic, date order, a precedence, a threshold. Count with one `noul`
  per item and sum in PHP; resolve precedence in PHP.
- **Declare an abstention** (`none`, `other`, `undecidable`) or the model cannot abstain.
- **Read literally.** The model answers the question you wrote; state the scope positively, put a negated case into the
  false branch.
- **Keep lists short:** about eight to ten named items per list-shaped state field.
- **Thresholds per action.** `noul` answers are probabilities quantised to 0.01; `choice` confidence is a distribution
  statistic, not a second opinion. Start conservative, refit on recorded labels, and validate a reported distribution
  with `AgentDecisionAnswerProbabilities::fromReported()`.
- **Customer-written text in the state is adversarial input.** Design the consequence so the worst answer is harmless
  (raise-only, demote-only, fail closed where a wrong yes is dangerous).

## Probing before wiring

`DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentProbeDecision` sends questions and state exactly as a fixture writes them (`probeQuestions`, `probeState`)
and returns the raw answers in `probeAnswers` (`AgentProbeDecisionAnswer` per question). It is never wired into the
loop; a host builds a console command around it that reads labelled cases (real-shaped, a dozen or more, the traps
included), runs each through the runner inside a usage envelope and prints the answers beside the labels. A
question change is a code change: re-probe and keep the numbers next to the question.

## A site of the loop itself

A package maintainer adds a site of the loop to `AgentDecisionModelSettings` (a `SITE_*` constant and an entry in
`SITES`), and either:

- **folds it into the turn-start request** — a question object, a `fill…Questions()` method and an
  `…AnswersUsable()` reader on `ArgusAgentTurnStartDecision`, a field on `AgentTurnStartSiteInputs`, a site service
  with `buildRequestInput()` and `applyDecision()`, and its place in both orders of
  `AgentTurnStartDecisionBatchService` (see [decision sites](decision-sites.md)). It must also be added to
  `AgentTurnStartSiteInputs::primaryRequestCarriesASite()`, `AgentTurnStartSiteInputs::asksNothing()` and
  `AgentTurnStartSiteInputs::getRequestedSiteNames()` — otherwise a turn where only the new site is eligible sends no
  request; to `ArgusAgentTurnStartDecision::hasAUsableSiteAnswer()` and to the per-site readers
  `ArgusAgentTurnStartDecision::stateForSite()`, `ArgusAgentTurnStartDecision::answersForSite()` and
  `ArgusAgentTurnStartDecision::questionsForSite()` that the decision record reads; and to
  `AgentTurnStartBatchResult::hasAParsableBreakerRelevantAnswer()`, or its answer can never close the breaker; or
- **runs at its own boundary** — its own `Argus…Decision` class listed in `ArgusAgentDecisions::DECISION_CHILD_CLASSES`
  (the unit test fails on a decision class that is not listed) and a one-child batch as above.

Either way: a stamp on `AgentWorkingState` with its own slice, persisted through
`AIConversationsService::persistAgentWorkingStateSlice()`; a `RunSettings` row only when operators need it per
conversation (then also `RunSettingsOverride` and `AgentDecisionLayerStatus`); a registry prompt in `AgentAIPromptNames`
and its file; a unit test pinning the questions, the state and the consequence; the site `off` in the package's
`decisionModel.php`; this skill's [decision sites](decision-sites.md) updated in the same release.
