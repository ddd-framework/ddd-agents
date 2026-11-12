<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionCallOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadLoadedSkill;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadLoadedSkills;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadTurnInputs;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartSubSkillQuestions;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilitiesSnapshotUndo;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Skills\AdmittedAISkills;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Throwable;
use DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Entities\Skills\RequestedAISkills;

/**
 * The skill-preload decision site: the request inputs of the roots
 * pass, of the sub-skill passes (the skills loaded before the turn, a root the pick unlocked, a `load_skills` call of
 * the chat model) and their one-child requests; the application of an answer — the pick resolved from the validated
 * distribution, the admission ({@see admitAndPromoteSkillsOverCatalog()}) inside one claim-fenced write
 * (mode `on`) or as a dry run (`shadow`), the decision recorded on the working state, as a decision record and as a
 * turn event — and the follow-up pass under a root that unlocked sub-skills.
 */
class AgentSkillPreloadDecisionsService extends Service
{
    use AITraceLogTrait;

    /** The subject prefix of a sub-skill decision about a skill that was loaded BEFORE the turn (`loadedSkill:<name>`). */
    public const string SKILL_PRELOAD_SUBJECT_LOADED_SKILL = 'loadedSkill';

    /** At most this many loaded skills are asked about their sub-skills at one turn start — the newest loads. */
    public const int SKILL_PRELOAD_MAX_LOADED_SKILLS_ASKED = 3;

    /** The subject prefix of a sub-skill decision about a skill the CHAT MODEL loaded in this step (`modelLoad:<name>`). */
    public const string SKILL_PRELOAD_SUBJECT_MODEL_LOAD = 'modelLoad';

    /**
     * Site B's request input, or null when the site does not run for this turn: the site mode
     * resolved through the account's arm and the conversation's own switch (site `off` is the absolute veto, a row
     * `on` lifts `shadow`, a row `off` silences the site here), never in a delegation or a purpose that writes its own
     * USER text, only when the CURRENT initiator is an owner TEXT (the loop's own predicate), only when no decision
     * event exists for it yet (the bounded, fail-safe lookup), and only when at least one offered root is not loaded.
     *
     * `$skillPreloadTurnInputs`: what every question of this turn start reads alike ({@see buildSkillPreloadTurnInputs()}),
     * built once by the turn start for the roots question and the sub-skill questions of the skills loaded before the
     * turn; null = built here (a later pass, after an admission changed the loaded set). Inputs built for other
     * assumed-loaded skills are not reused.
     *
     * @param string[] $assumeLoadedSkillNames
     * @param string|null $subSkillPassSubject the subject prefix of a sub-skill pass that is no follow-up of the roots
     *                                         pass: {@see self::SKILL_PRELOAD_SUBJECT_LOADED_SKILL} (the skill was loaded
     *                                         in an earlier turn) or {@see self::SKILL_PRELOAD_SUBJECT_MODEL_LOAD} (the
     *                                         chat model loaded it in this step); null = `pass<n>`
     * @param AgentRun|null $agentRun the run that asks (its per-run reads), null outside a run
     */
    public function buildSkillPreloadRequestInput(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings, array $assumeLoadedSkillNames = [], ?string $unlockedByRootSkillName = null, int $pass = 1, ?string $subSkillPassSubject = null, ?AgentRun $agentRun = null, ?AgentSkillPreloadTurnInputs $skillPreloadTurnInputs = null): ?AgentSkillPreloadDecisionQuestion
    {
        try {
            if ($skillPreloadTurnInputs === null || !$skillPreloadTurnInputs->isBuiltForAssumedLoadedSkills($assumeLoadedSkillNames)) {
                $skillPreloadTurnInputs = $this->buildSkillPreloadTurnInputs($conversation, $decisionModelSettings, $assumeLoadedSkillNames, $agentRun);
            }
            if ($skillPreloadTurnInputs === null) {
                return null;
            }
            $initiatorMessageIndex = $skillPreloadTurnInputs->initiatorMessageIndex;
            /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
            $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
            if ($pass === 1 && $aIConversationTurnEventsService->hasEventFor((int)$conversation->id, $initiatorMessageIndex, AIConversationTurnEvent::KIND_DECISION, AgentDecisionModelSettings::SITE_SKILL_PRELOAD)) {
                return null; // already decided for this initiator (a chunk resume) — the stored decision stands
            }
            $offeredSkillCatalog = $skillPreloadTurnInputs->offeredSkillCatalog;
            // Pass 1 answers over the offered ROOTS; a follow-up pass only over the sub-skills the
            // root admitted in the pass before unlocked — never a second root in the same turn.
            $preloadableRootNames = $unlockedByRootSkillName === null
                ? $offeredSkillCatalog->getPreloadableRootSkillNames()
                : $offeredSkillCatalog->getPreloadableSubSkillNamesUnlockedBy($unlockedByRootSkillName);
            if ($preloadableRootNames === []) {
                return null;
            }
            /** @var AISkillsService $aiSkillsService */
            $aiSkillsService = DDDService::instance()->getService(AISkillsService::class);
            // The decision model reads what the chat model reads — the answer set is the CANONICAL
            // names (the wire lowercases a choice, so the map is lowercased name → name), each with the skill's full
            // catalog entry; a loaded skill is shown with its description AND its tools.
            // The feature-flag and account-rights gating of the chat model applies to the decision model
            // unchanged — the offered catalog is already the gated one; the tool lists and the sub-skill lines below are
            // filtered with the same flags / rights the wire schemas use (`buildToolSchemas()`).
            $entitlement = $skillPreloadTurnInputs->entitlement;
            $catalogTokens = [];
            $catalogCriteria = [];
            $offeredSnapshot = [];
            foreach (array_values($preloadableRootNames) as $skillName) {
                $token = strtolower($skillName);
                $aiSkill = $aiSkillsService->getAISkillByName($skillName);
                $catalogTokens[$token] = $skillName;
                $catalogCriteria[$skillName] = $aiSkill?->getDecisionCatalogEntry($offeredSkillCatalog->skills) ?? $skillName;
                $offeredSnapshot[$token] = ['name' => $skillName, 'toolNames' => $aiSkill !== null ? $aiSkill->getToolNames($entitlement) : []];
            }
            $rootSkillDescription = '';
            $loadedSubSkills = [];
            $actsAhead = [];
            if ($unlockedByRootSkillName !== null) {
                $rootSkill = $aiSkillsService->getAISkillByName($unlockedByRootSkillName);
                $rootSkillDescription = $rootSkill instanceof AISkill ? (string)preg_replace('/\s+/', ' ', trim($rootSkill->description)) : '';
                $residentSkillNames = array_values(array_unique(array_merge($offeredSkillCatalog->alreadyLoadedSkillNames, $assumeLoadedSkillNames, [$unlockedByRootSkillName])));
                try {
                    $actsAhead = $conversation->getTaskPlan()?->toActsAheadLines($residentSkillNames) ?? [];
                } catch (Throwable) {
                    $actsAhead = [];
                }
                foreach ($rootSkill instanceof AISkill ? $rootSkill->subSkillNames : [] as $subSkillName) {
                    if (in_array($subSkillName, $residentSkillNames, true)) {
                        $residentSubSkill = $aiSkillsService->getAISkillByName($subSkillName);
                        $loadedSubSkills[$subSkillName] = $residentSubSkill instanceof AISkill && $residentSubSkill->shortDescription !== '' ? $residentSubSkill->shortDescription : $subSkillName;
                    }
                }
            }
            $unlockedByRootSkill = $unlockedByRootSkillName !== null ? $aiSkillsService->getAISkillByName($unlockedByRootSkillName) : null;
            $skillPreloadQuestion = new AgentSkillPreloadDecisionQuestion();
            $skillPreloadQuestion->mode = $skillPreloadTurnInputs->siteMode;
            $skillPreloadQuestion->arm = $skillPreloadTurnInputs->arm;
            $skillPreloadQuestion->overrideSource = $skillPreloadTurnInputs->overrideSource;
            $skillPreloadQuestion->pass = $pass;
            $skillPreloadQuestion->unlockedByRootSkillName = $unlockedByRootSkillName;
            $skillPreloadQuestion->subject = $unlockedByRootSkillName !== null
                ? ($subSkillPassSubject ?? 'pass' . $pass) . ':' . $unlockedByRootSkillName
                : null;
            $skillPreloadQuestion->subSkillPassSubject = $subSkillPassSubject;
            // what the skill whose sub-skills are judged does by itself — the sub-skill question reads it
            $skillPreloadQuestion->unlockedByRootToolSummaries = $unlockedByRootSkill !== null
                ? $unlockedByRootSkill->getToolSummaries($entitlement, AISkill::MAX_DECISION_OWN_TOOL_SUMMARY_CHARS)
                : [];
            $skillPreloadQuestion->assumeLoadedSkillNames = $assumeLoadedSkillNames;
            $skillPreloadQuestion->initiatorMessageIndex = $initiatorMessageIndex;
            $skillPreloadQuestion->catalogTokens = $catalogTokens;
            $skillPreloadQuestion->catalogCriteria = $catalogCriteria;
            $skillPreloadQuestion->loadedSkills = $skillPreloadTurnInputs->loadedSkills;
            $skillPreloadQuestion->recentTurns = $skillPreloadTurnInputs->recentTurns;
            $skillPreloadQuestion->agentSpaceFacts = $skillPreloadTurnInputs->agentSpaceFacts;
            $skillPreloadQuestion->planRender = $skillPreloadTurnInputs->planRender;
            $skillPreloadQuestion->actsAhead = $actsAhead;
            $skillPreloadQuestion->rootSkillDescription = $rootSkillDescription;
            $skillPreloadQuestion->loadedSubSkills = $loadedSubSkills;
            $skillPreloadQuestion->metaTools = $skillPreloadTurnInputs->metaTools;
            $skillPreloadQuestion->ownerMessage = (string)$skillPreloadTurnInputs->initiatorMessage->message;
            $skillPreloadQuestion->assistantTextBefore = $skillPreloadTurnInputs->assistantTextBefore;
            $skillPreloadQuestion->openWorkItem = $skillPreloadTurnInputs->openWorkItem;
            $skillPreloadQuestion->facts = $skillPreloadTurnInputs->facts;
            $skillPreloadQuestion->criteriaVersion = $skillPreloadTurnInputs->criteriaVersion;
            $skillPreloadQuestion->snapshot = ['offered' => $offeredSnapshot, 'loaded' => $skillPreloadTurnInputs->loadedSkills->toCatalogSnapshot(), 'criteriaVersion' => $skillPreloadTurnInputs->criteriaVersion];
            return $skillPreloadQuestion;
        } catch (Throwable $inputError) {
            $this->aiTrace('turn.skillPreload.inputFailed', ['conversationId' => $conversation->id ?? null, 'message' => $inputError->getMessage()]);
            return null;
        }
    }

    /**
     * What every skill-preload question of one turn start reads alike, or null when the site does not run for this
     * turn: the site mode resolved through the account's arm and the conversation's own switch (site `off` is the
     * absolute veto, a row `on` lifts `shadow`, a row `off` silences the site here), never in a delegation or a purpose
     * that writes its own USER text, only when the CURRENT initiator is an owner TEXT (the loop's own predicate). The
     * offered catalog, the entitlement and the loaded skills' entries and tool summaries are read here ONCE for all the
     * questions of the turn start.
     *
     * @param string[] $assumeLoadedSkillNames skills treated as loaded, so their sub-skills join the offer
     * @param AgentRun|null $agentRun the run that asks (its per-run reads), null outside a run
     * @throws Throwable a failed read — the callers fail open
     */
    public function buildSkillPreloadTurnInputs(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings, array $assumeLoadedSkillNames = [], ?AgentRun $agentRun = null): ?AgentSkillPreloadTurnInputs
    {
        // ONE helper resolves the site for this conversation (the env veto, the kill switch, the
        // conversation's row); the override source stays what it was — the row applied when it is set and the env
        // did not veto the site.
        $siteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_SKILL_PRELOAD, $conversation);
        $rowSwitch = isset($conversation->runSettings) ? ($conversation->runSettings->decisionModelSkillPreload ?? null) : null;
        $overrideSource = $decisionModelSettings->siteMode(AgentDecisionModelSettings::SITE_SKILL_PRELOAD) !== AgentDecisionModelSettings::MODE_OFF
            && $rowSwitch !== null && $rowSwitch !== RunSettings::DECISION_MODEL_SKILL_PRELOAD_INHERIT
            ? AIConversationTurnEvent::OVERRIDE_SOURCE_CONVERSATION
            : AIConversationTurnEvent::OVERRIDE_SOURCE_NONE;
        if ($siteMode === AgentDecisionModelSettings::MODE_OFF || $conversation->isDelegation() || !isset($conversation->id)) {
            return null;
        }
        if ($conversation->findPurpose()?->usesSynthesizedOwnerText === true) {
            return null;
        }
        $initiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex();
        if ($initiatorMessageIndex === null) {
            return null;
        }
        $initiatorMessage = null;
        foreach ($conversation->messages->getElements() as $message) {
            if (($message->indexInConversation ?? null) === $initiatorMessageIndex) {
                $initiatorMessage = $message;
                break;
            }
        }
        if (!AIConversationMessages::initiatorCarriesOwnerWords($initiatorMessage)) {
            return null; // a signal-started turn, a widget click, a delegation seed — not the owner's words (a submitted form is)
        }
        $offeredSkillCatalog = $conversation->buildOfferedSkillCatalog(null, $assumeLoadedSkillNames, $agentRun);
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = DDDService::instance()->getService(AISkillsService::class);
        $entitlement = $conversation->getEntitlement();
        $loadedSkills = new AgentSkillPreloadLoadedSkills();
        foreach (array_values(array_unique(array_merge($offeredSkillCatalog->alreadyLoadedSkillNames, $assumeLoadedSkillNames))) as $loadedName) {
            $aiSkill = $aiSkillsService->getAISkillByName($loadedName);
            $loadedSkills->addSkill(AgentSkillPreloadLoadedSkill::forSkill(
                $loadedName,
                $aiSkill?->getDecisionCatalogEntry($offeredSkillCatalog->skills) ?? $loadedName,
                $aiSkill !== null ? $aiSkill->getToolSummaries($entitlement) : [],
                $aiSkill !== null ? $aiSkill->getToolNames($entitlement) : []
            ));
        }
        // The decision model reads the PLAN the chat model reads (the tail-hint render), the loaded
        // root's own description (its act order) and the root's sub-skills already resident — a sub-skill pass on
        // "Kunden kommen zu uns" can only know that the next act begins when it sees the plan.
        $planRender = '';
        try {
            $planRender = (string)($conversation->getTaskPlan()?->toDecisionPlanText() ?? '');
        } catch (Throwable) {
            $planRender = '';
        }
        // The last assistant TEXT BEFORE the initiator — the answer that may have asked the question this message answers.
        $assistantTextBefore = '';
        foreach ($conversation->messages->getElements() as $message) {
            if (($message->indexInConversation ?? PHP_INT_MAX) >= $initiatorMessageIndex) {
                continue;
            }
            if ($message->isAssistantAuthoredText()
                && trim((string)($message->message ?? '')) !== ''
            ) {
                $assistantTextBefore = (string)$message->message;
            }
        }
        $openWorkItems = $conversation->getTaskPlan()?->getOpenItemPathTexts() ?? [];
        // the application's facts about the space (e.g. whether a result exists), by name
        $facts = [];
        try {
            /** @var AgentDecisionFactsService $agentDecisionFactsService */
            $agentDecisionFactsService = DDDService::instance()->getService(AgentDecisionFactsService::class);
            $facts = $agentDecisionFactsService->collectSkillPreloadFacts($conversation)->valuesByFactName;
        } catch (Throwable) {
            // an unresolvable fact is simply absent — the question tolerates it
        }
        $skillPreloadTurnInputs = new AgentSkillPreloadTurnInputs();
        $skillPreloadTurnInputs->siteMode = $siteMode;
        $skillPreloadTurnInputs->overrideSource = $overrideSource;
        $skillPreloadTurnInputs->arm = $decisionModelSettings->siteMode(AgentDecisionModelSettings::SITE_SKILL_PRELOAD) === AgentDecisionModelSettings::MODE_COHORT
            ? AgentDecisionModelSettings::resolveArmForAccountId($conversation->accountId ?? null)
            : null;
        $skillPreloadTurnInputs->assumeLoadedSkillNames = $assumeLoadedSkillNames;
        $skillPreloadTurnInputs->initiatorMessageIndex = $initiatorMessageIndex;
        $skillPreloadTurnInputs->initiatorMessage = $initiatorMessage;
        $skillPreloadTurnInputs->offeredSkillCatalog = $offeredSkillCatalog;
        $skillPreloadTurnInputs->entitlement = $entitlement;
        $skillPreloadTurnInputs->loadedSkills = $loadedSkills;
        // the thread, not one line: the one recent-turn formatter, one line per turn
        $skillPreloadTurnInputs->recentTurns = $conversation->messages->buildOwnerTurns()->buildRecentTurnTextsBefore($initiatorMessageIndex, collapseWhitespace: true)->recentTurns;
        $skillPreloadTurnInputs->agentSpaceFacts = implode("\n", $conversation->buildStoredFactLinesForDecisions());
        $skillPreloadTurnInputs->planRender = $planRender;
        $skillPreloadTurnInputs->metaTools = $this->buildMetaToolSummariesForDecisions();
        $skillPreloadTurnInputs->assistantTextBefore = $assistantTextBefore;
        $skillPreloadTurnInputs->openWorkItem = (string)($openWorkItems[0] ?? '');
        $skillPreloadTurnInputs->facts = $facts;
        $skillPreloadTurnInputs->criteriaVersion = $aiSkillsService->getDecisionCriteriaVersion();
        return $skillPreloadTurnInputs;
    }

    /**
     * What the chat model can do WITHOUT any skill loaded (the meta tools of the loop), as `name => what it does` — so
     * the decision model knows they exist and never routes a request to a skill for work these do.
     *
     * @return array<string, string>
     */
    protected function buildMetaToolSummariesForDecisions(): array
    {
        return [
            'load_skills' => 'Load one or more skills from the catalog — their working rules and, where they have them, their tools',
            'load_tool' => 'Load one still-unloaded tool of an already loaded skill',
            'delegate' => 'Spawn a background sub-agent for a bounded task with named skills',
            'check_delegations' => 'Read the status and results of running delegations',
            'update_task_plan' => 'Create or restructure the task plan of a multi-part request',
            'set_task_status' => 'Tick a task-plan item done / blocked / not applicable',
        ];
    }

    /**
     * Site B's consequence: type the answers into an {@see AgentSkillPreloadDecision}, resolve
     * the chain in PHP, evaluate the FULL admission predicate (`wouldApply` — in shadow as a dry run, so the shadow
     * arm's sample is exchangeable with the on-arm's), and in mode `on` ADMIT the pick through the offer policy inside
     * ONE transaction that LOCKS the conversation row (`SELECT … FOR UPDATE`), compares the claim token, writes the
     * decision event and the anchor's capability column, and commits — a stolen claim rolls back, a duplicate event
     * under a held lock is a replay (nothing re-admitted). Every path records the decision on the working state (the
     * decision record) and as a turn event (the durable log).
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function applySkillPreloadDecision(
        AIConversation $conversation,
        AgentSkillPreloadDecisionQuestion $skillPreloadQuestion,
        ?ArgusAgentTurnStartDecision $argusDecision,
        ?string $requestDecidedBy,
        AgentDecisionModelSettings $decisionModelSettings,
        ?string $runToken,
        string $requestId,
        ?int $elapsedMs,
        ?string $transportFailureReason,
        ?AgentRun $agentRun = null
    ): void {
        $initiatorMessageIndex = $skillPreloadQuestion->initiatorMessageIndex;
        $catalogTokens = $skillPreloadQuestion->catalogTokens;
        $applies = $skillPreloadQuestion->mode === AgentDecisionModelSettings::MODE_ON;
        $decision = new AgentSkillPreloadDecision();
        $decision->ownerMessageIndex = $initiatorMessageIndex;
        $decision->decidedAt = (new DateTime())->format('Y-m-d H:i:s');
        $decision->arm = $skillPreloadQuestion->arm;
        $decision->pass = $skillPreloadQuestion->pass;
        $decision->unlockedByRootSkillName = $skillPreloadQuestion->unlockedByRootSkillName;
        $decision->criteriaVersion = $skillPreloadQuestion->criteriaVersion;
        $decision->catalogTokens = $catalogTokens;
        $decision->modelVersion = $argusDecision?->modelVersion;
        $assumeLoadedSkillNames = $skillPreloadQuestion->assumeLoadedSkillNames;
        if ($requestDecidedBy !== null) {
            $decision->decidedBy = $requestDecidedBy; // a FALLBACK_* — the request never answered
            $decision->skipReason = $requestDecidedBy;
        } elseif ($argusDecision === null || !$argusDecision->preloadAnswersUsable()) {
            $decision->decidedBy = AgentSkillPreloadDecision::FALLBACK_UNPARSABLE;
            $decision->skipReason = AgentSkillPreloadDecision::FALLBACK_UNPARSABLE;
        } else {
            $decision->pickedToken = $argusDecision->preloadPickedToken;
            $decision->pickConfidence = $argusDecision->preloadPickConfidence;
            $validated = AgentSkillPreloadDecision::validateReportedProbabilities($argusDecision->preloadProbabilities, array_keys($catalogTokens));
            $decision->probabilities = $validated?->toArray() ?? [];
            // ONE general rule for every conversation kind and both passes: the validated
            // distribution alone decides ({@see AgentSkillPreloadDecision::resolvePreloadFromDistribution()}); the model's
            // own pickedToken/pickConfidence are recorded for the event but no longer gate the pick, and the dependent
            // RERANK request is gone (calibrated on 14 407 stored decisions: 96 % of the roots picks kept, no second call).
            $distributionPick = AgentSkillPreloadDecision::resolvePreloadFromDistribution($validated);
            $decision->wouldPreload = $distributionPick->isPicked;
            $decision->skipReason = $distributionPick->skipReason;
            $decision->pickMargin = $distributionPick->marginOverSecond;
            if ($decision->wouldPreload) {
                $decision->pickedToken = $distributionPick->topToken;
            }
            $decision->pickedSkillName = $decision->pickedToken !== null ? ($catalogTokens[$decision->pickedToken] ?? null) : null;
            $decision->decidedBy = $applies ? AgentSkillPreloadDecision::DECIDED_BY_DECISION_MODEL : AgentSkillPreloadDecision::DECIDED_BY_SHADOW;
        }

        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        $catalogSnapshotHash = $aIConversationTurnEventsService->storeCatalogSnapshot($skillPreloadQuestion->snapshot);
        $anchorMessage = $conversation->messages->last();

        if ($decision->wouldPreload && $decision->pickedSkillName !== null && $anchorMessage !== null) {
            // The FULL admission predicate — in shadow a DRY RUN (the offer policy, nothing promoted), in on the real
            // admission inside ONE claim-fenced write: the message-index lock first, then the
            // conversation row locked with the claim check; the decision event, the capability writes and the event's
            // outcome update commit together — a nested persist joins this write, it never commits on its own.
            try {
                if ($applies) {
                    $writeScope = $this->messagesService()->openClaimFencedWrite($conversation, $runToken);
                    try {
                        $decisionEvent = $this->buildDecisionTurnEvent($conversation, $decision, $skillPreloadQuestion, $catalogSnapshotHash, $requestId, $elapsedMs, true);
                        $inserted = $aIConversationTurnEventsService->recordEvent($decisionEvent, $runToken);
                        if (!$inserted) {
                            // A duplicate under the held lock = an earlier attempt already committed this decision:
                            // REPLAY — the pick is in the newest capability set (carry-forward), nothing is re-admitted.
                            $decision->skipReason = 'replayed';
                            $decision->admittedSkillNames = [];
                        } else {
                            // a rolled-back admission leaves the anchor's set as it was — in memory as in the row
                            $this->messagesService()->undoOnRollback(LoadedCapabilitiesSnapshotUndo::ofMessage($anchorMessage));
                            $admission = $this->promoteSkillsFromSource($conversation, [$decision->pickedSkillName], LoadedCapability::SOURCE_DECISION_MODEL, $anchorMessage, assumeLoadedSkillNames: $assumeLoadedSkillNames, agentRun: $agentRun);
                            $decision->admittedSkillNames = $admission->admittedSkillNames;
                            $decision->wouldApply = $admission->admittedSkillNames !== [];
                            if (!$decision->wouldApply) {
                                $decision->skipReason = $admission->getRejectionReason($decision->pickedSkillName) ?? AgentSkillPreloadDecision::SKIP_NOT_OFFERED;
                            }
                            // The event above was inserted BEFORE the admission (its insert is the replay guard); it now
                            // carries the admission's real outcome — `applied`, the admitted names, the skip reason.
                            $aIConversationTurnEventsService->updateEventPayload(
                                $this->buildDecisionTurnEvent($conversation, $decision, $skillPreloadQuestion, $catalogSnapshotHash, $requestId, $elapsedMs, $decision->wouldApply)
                            );
                        }
                    } catch (Throwable $writeError) {
                        $this->messagesService()->rollBackClaimFencedWrite($writeScope, $writeError);
                    }
                    $this->messagesService()->commitClaimFencedWrite($writeScope);
                } else {
                    $admission = $this->promoteSkillsFromSource($conversation, [$decision->pickedSkillName], LoadedCapability::SOURCE_DECISION_MODEL, $anchorMessage, dryRun: true, assumeLoadedSkillNames: $assumeLoadedSkillNames, agentRun: $agentRun);
                    $decision->wouldApply = $admission->admittedSkillNames !== [];
                    if (!$decision->wouldApply) {
                        $decision->skipReason = $admission->getRejectionReason($decision->pickedSkillName) ?? AgentSkillPreloadDecision::SKIP_NOT_OFFERED;
                    }
                }
            } catch (AgentRunClaimLostException $claimLost) {
                throw $claimLost; // the loop's ONE claim-lost exit path — the new owner drives
            } catch (Throwable $admissionError) {
                // the claim-fenced write rolled back whatever it had written
                $decision->skipReason = AgentSkillPreloadDecision::SKIP_ADMISSION_ERROR;
                $decision->wouldApply = false;
                $decision->admittedSkillNames = [];
                DDDService::instance()->getLogger()->warning('[AgentDecisionModel] skill-preload admission failed', ['reason' => $admissionError::class . ': ' . mb_substr($admissionError->getMessage(), 0, 200)]);
            }
        }
        $applied = $applies && $decision->admittedSkillNames !== [];
        if (!$applies || !$decision->wouldPreload || $decision->pickedSkillName === null || $anchorMessage === null || $decision->skipReason === AgentSkillPreloadDecision::SKIP_ADMISSION_ERROR) {
            // No transaction wrote the event above (shadow, a skip, or a failure) — write it now, fenced, idempotent.
            $aIConversationTurnEventsService->recordEvent($this->buildDecisionTurnEvent($conversation, $decision, $skillPreloadQuestion, $catalogSnapshotHash, $requestId, $elapsedMs, $applied), $runToken);
        }

        $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
        $agentWorkingState->skillPreloadDecision = $decision;
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'skillPreloadDecision');
        $this->decisionCallsService()->recordDecision($conversation, $decision->buildRecord(
            AgentDecisionModelSettings::SITE_SKILL_PRELOAD,
            $applied,
            $elapsedMs,
            $transportFailureReason,
            $argusDecision?->stateForSite(AgentDecisionModelSettings::SITE_SKILL_PRELOAD),
            $argusDecision?->answersForSite(AgentDecisionModelSettings::SITE_SKILL_PRELOAD),
            [
                'applied' => $applied,
                'mode' => $skillPreloadQuestion->mode,
                'arm' => $decision->arm,
                'pass' => $decision->pass,
                'unlockedByRootSkillName' => $decision->unlockedByRootSkillName,
                'overrideSource' => $skillPreloadQuestion->overrideSource,
                'pick' => $decision->pickedSkillName,
                'pickedByRerank' => $decision->pickedByRerank,
                'rerankFits' => $decision->rerankFits,
                'pickConfidence' => $decision->pickConfidence,
                'pickMargin' => $decision->pickMargin,
                'wouldPreload' => $decision->wouldPreload,
                'wouldApply' => $decision->wouldApply,
                'skipReason' => $decision->skipReason,
                'admittedSkillNames' => $decision->admittedSkillNames,
                'criteriaVersion' => $decision->criteriaVersion,
            ],
            $argusDecision?->questionsForSite(AgentDecisionModelSettings::SITE_SKILL_PRELOAD)
        ));
        $this->aiTrace('turn.skillPreload.decided', [
            'conversationId' => $conversation->id ?? null,
            'initiatorMessageIndex' => $initiatorMessageIndex,
            'mode' => $skillPreloadQuestion->mode,
            'arm' => $decision->arm,
            'pick' => $decision->pickedSkillName,
            'wouldPreload' => $decision->wouldPreload,
            'applied' => $applied,
            'skipReason' => $decision->skipReason,
            'decidedBy' => $decision->decidedBy,
            'pass' => $decision->pass,
            'elapsedMs' => $elapsedMs,
        ]);
        $this->decideSkillPreloadFollowUp($conversation, $skillPreloadQuestion, $decision, $decisionModelSettings, $runToken, $agentRun);
    }

    /**
     * The sub-skill inputs of the skills that are ALREADY loaded at the turn start: a skill loaded in an earlier turn
     * still lists sub-skills that are not loaded, and the roots pass never answers with a sub-skill — without this the
     * chat model loads such a sub-skill with a call of its own in every later turn. One input per loaded skill that
     * has sub-skills to offer, keyed by its name. A purposed run is included: its acts are
     * the conductor's sub-skills and the plan-item preload alone misses them. Same gates as the roots pass (site mode,
     * owner-text initiator, never a delegation), and never twice for one initiator.
     *
     * @param AgentRun|null $agentRun the run that asks (its per-run reads), null outside a run
     * @param AgentSkillPreloadTurnInputs|null $skillPreloadTurnInputs the turn start's inputs, read by every question; null = built per question
     * @return AgentTurnStartSubSkillQuestions one sub-skill question per loaded skill that has sub-skills to offer
     */
    public function buildLoadedSkillSubSkillPreloadRequestInputs(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings, ?AgentRun $agentRun = null, ?AgentSkillPreloadTurnInputs $skillPreloadTurnInputs = null): AgentTurnStartSubSkillQuestions
    {
        try {
            // A PURPOSED run is not excluded — its acts
            // are sub-skills of the resident conductor, the plan-item preload fires only when the marked item names
            // skills (rarely), so this pass is the one place the decision model sees the acts (`nextPlanItem` is in
            // the state).
            if ($conversation->isDelegation() || !isset($conversation->id)) {
                return new AgentTurnStartSubSkillQuestions();
            }
            if ($decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_SKILL_PRELOAD, $conversation) === AgentDecisionModelSettings::MODE_OFF) {
                return new AgentTurnStartSubSkillQuestions();
            }
            $initiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex();
            if ($initiatorMessageIndex === null) {
                return new AgentTurnStartSubSkillQuestions();
            }
            /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
            $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
            if ($aIConversationTurnEventsService->hasEventFor((int)$conversation->id, $initiatorMessageIndex, AIConversationTurnEvent::KIND_DECISION, AgentDecisionModelSettings::SITE_SKILL_PRELOAD)) {
                return new AgentTurnStartSubSkillQuestions(); // already decided for this initiator (a chunk resume)
            }
            $offeredSkillCatalog = $skillPreloadTurnInputs !== null && $skillPreloadTurnInputs->isBuiltForAssumedLoadedSkills([])
                ? $skillPreloadTurnInputs->offeredSkillCatalog
                : $conversation->buildOfferedSkillCatalog(null, [], $agentRun);
            $loadedSkillNames = $offeredSkillCatalog->getLoadedSkillNamesWithPreloadableSubSkills();
            $subSkillQuestions = new AgentTurnStartSubSkillQuestions();
            foreach (array_slice($loadedSkillNames, -self::SKILL_PRELOAD_MAX_LOADED_SKILLS_ASKED) as $loadedSkillName) {
                $subSkillQuestion = $this->buildSkillPreloadRequestInput($conversation, $decisionModelSettings, [], $loadedSkillName, 2, self::SKILL_PRELOAD_SUBJECT_LOADED_SKILL, $agentRun, $skillPreloadTurnInputs);
                if ($subSkillQuestion !== null) {
                    $subSkillQuestions->addQuestionOfLoadedSkill($loadedSkillName, $subSkillQuestion);
                }
            }
            return $subSkillQuestions;
        } catch (Throwable $inputError) {
            $this->aiTrace('turn.skillPreload.loadedSkillInputFailed', ['conversationId' => $conversation->id ?? null, 'message' => $inputError->getMessage()]);
            return new AgentTurnStartSubSkillQuestions();
        }
    }

    /**
     * The request of a SUB-SKILL pass: the loaded skill by name and its sub-skills as the answer set — the state the
     * sub-skill question reads, nothing else.
     */
    public function buildSubSkillPreloadDecisionRequest(AIConversation $conversation, AgentSkillPreloadDecisionQuestion $subSkillQuestion, AgentDecisionModelSettings $decisionModelSettings): ArgusAgentTurnStartDecision
    {
        $argusDecision = new ArgusAgentTurnStartDecision();
        $argusDecision->preloadLoadedRootSkillName = (string)$subSkillQuestion->unlockedByRootSkillName;
        $argusDecision->preloadLoadedRootSkillTools = $subSkillQuestion->unlockedByRootToolSummaries;
        $argusDecision->preloadCatalog = $subSkillQuestion->catalogCriteria;
        $argusDecision->preloadOwnerMessage = $subSkillQuestion->ownerMessage;
        $argusDecision->preloadAssistantTextBefore = $subSkillQuestion->assistantTextBefore;
        $argusDecision->preloadOpenWorkItem = $subSkillQuestion->openWorkItem;
        $argusDecision->preloadRecentTurns = $subSkillQuestion->recentTurns;
        $argusDecision->preloadAgentSpaceFacts = $subSkillQuestion->agentSpaceFacts;
        $argusDecision->preloadPlan = $subSkillQuestion->planRender;
        $argusDecision->preloadActsAhead = array_values(array_map('strval', $subSkillQuestion->actsAhead));
        $argusDecision->preloadRootSkillDescription = $subSkillQuestion->rootSkillDescription;
        $argusDecision->preloadLoadedSubSkills = $subSkillQuestion->loadedSubSkills;
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $argusDecision->billingEntity = $agentSpacesService->findLoadedBillingEntityOfConversation($conversation);
        $argusDecision->budgetAccount = isset($conversation->account) ? $conversation->account : null;
        $argusDecision->setTimeoutSeconds($decisionModelSettings->timeoutSeconds);
        return $argusDecision;
    }

    /**
     * The FOLLOW-UP pass of the skill-preload site: a preload is a `load_skills` step, and a
     * step that loads a NOT-YET-LOADED root WITH sub-skills unlocks those sub-skills for the next step — so the
     * decision runs again over exactly them (never a second root), on-arm after the real admission, in shadow with the
     * root ASSUMED loaded (a dry run all the way down). Bounded: only when the pass before admitted (or would admit) a
     * root that has sub-skill edges, at most {@see AgentSkillPreloadDecision::MAX_PASSES} passes per turn, and each pass is
     * its own request, event (`subject = pass<n>:<root>`) and record. A replayed or failed pass never continues.
     *
     * @param AgentSkillPreloadDecisionQuestion $skillPreloadQuestion The pass before's question.
     */
    protected function decideSkillPreloadFollowUp(AIConversation $conversation, AgentSkillPreloadDecisionQuestion $skillPreloadQuestion, AgentSkillPreloadDecision $decision, AgentDecisionModelSettings $decisionModelSettings, ?string $runToken, ?AgentRun $agentRun = null): void
    {
        try {
            $previousPass = $skillPreloadQuestion->pass;
            if ($previousPass >= AgentSkillPreloadDecision::MAX_PASSES
                || !$decision->wouldPreload || !$decision->wouldApply || $decision->pickedSkillName === null
                || $decision->skipReason !== null
            ) {
                return;
            }
            $applies = $skillPreloadQuestion->mode === AgentDecisionModelSettings::MODE_ON;
            if ($applies && $decision->admittedSkillNames === []) {
                return; // on-arm: nothing was actually loaded (replay / rejection) — nothing got unlocked
            }
            /** @var AISkillsService $aiSkillsService */
            $aiSkillsService = AISkill::getService();
            $pickedSkill = $aiSkillsService->getAISkillByName($decision->pickedSkillName);
            if ($pickedSkill === null || $pickedSkill->subSkillNames === []) {
                return; // the pick unlocks nothing — the step is complete
            }
            $assumeLoadedSkillNames = array_values(array_unique(array_merge($skillPreloadQuestion->assumeLoadedSkillNames, [$decision->pickedSkillName])));
            $followUpQuestion = $this->buildSkillPreloadRequestInput($conversation, $decisionModelSettings, $assumeLoadedSkillNames, $decision->pickedSkillName, $previousPass + 1, null, $agentRun);
            if ($followUpQuestion === null) {
                return; // the root unlocked nothing offerable (all loaded / vision-locked / filtered)
            }
            $decidedBy = null;
            $transportFailureReason = null;
            $argusDecision = $this->buildSubSkillPreloadDecisionRequest($conversation, $followUpQuestion, $decisionModelSettings);
            $decisionBatch = ArgusAgentDecisions::forChild($argusDecision, 'turnStart:pass' . ($previousPass + 1));
            $decisionCallOutcome = $this->decisionCallsService()->runDecisionBatch(
                $decisionBatch,
                $argusDecision,
                $decisionModelSettings->timeoutSeconds,
                $decisionModelSettings->breakerSeconds,
                'skill-preload follow-up pass'
            );
            $transportFailureReason = $decisionCallOutcome->failureReason;
            if ($decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_REFUSED) {
                // our own gate — stamped `fallback:refused` like every site
                $this->aiTrace('turn.skillPreload.followUpSkipped', ['conversationId' => $conversation->id ?? null, 'pass' => $previousPass + 1, 'reason' => $transportFailureReason]);
            }
            $decidedBy = $decisionCallOutcome->resolveFallbackStamp();
            if ($decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN) {
                $argusDecision = null;
                $decisionBatch = null;
            }
            $this->decisionCallsService()->closeCircuitBreakerAfter($decisionCallOutcome, $argusDecision?->decided === true);
            $elapsedMs = $decisionCallOutcome->elapsedMs;
            $requestId = bin2hex(random_bytes(8));
            $this->applySkillPreloadDecision($conversation, $followUpQuestion, $argusDecision, $decidedBy, $decisionModelSettings, $runToken, $requestId, $elapsedMs, $transportFailureReason, $agentRun);
            $this->turnEventsService()->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_REQUEST, 'turnStart', $followUpQuestion->initiatorMessageIndex, null, $requestId, [
                'sites' => ['skillPreload'],
                'batchId' => $decisionBatch?->batchId,
                'pass' => $followUpQuestion->pass,
                'unlockedByRootSkillName' => $followUpQuestion->unlockedByRootSkillName,
                'decidedBy' => $decidedBy ?? 'answered',
                'transportFailureReason' => $transportFailureReason,
                'modelVersion' => $argusDecision?->modelVersion,
            ], $runToken, $elapsedMs, $followUpQuestion->arm, $requestId);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost;
        } catch (Throwable $followUpError) {
            $this->aiTrace('turn.skillPreload.followUpFailed', ['conversationId' => $conversation->id ?? null, 'message' => $followUpError->getMessage()]);
        }
    }

    /**
     * The sub-skill pass after a `load_skills` call of the CHAT MODEL: the roots pass did not preload the skill (its
     * confidence was short, or it named another skill), the chat model loaded it itself, and the skill lists sub-skills.
     * The decision model is asked about exactly those sub-skills now — one request per loaded skill, in parallel — and
     * an admitted sub-skill is loaded in the same step, so the chat model's next call already has it. Only for a call that loaded at most
     * {@see self::SKILL_PRELOAD_MAX_LOADED_SKILLS_ASKED} skills (a call that loads half the catalog is no request for
     * one domain). Never throws: every failure leaves the load as the chat model made it. Returns the
     * sentence for the tool result, '' when nothing was added.
     *
     * @param string[] $loadedSkillNames the names the call loaded
     * @param AgentRun|null $agentRun the run that asks (its per-run reads), null outside a run
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function preloadSubSkillsOfSkillsLoadedByTheChatModel(AIConversation $conversation, array $loadedSkillNames, ?AgentRun $agentRun = null): string
    {
        try {
            if ($loadedSkillNames === [] || count($loadedSkillNames) > self::SKILL_PRELOAD_MAX_LOADED_SKILLS_ASKED
                || $conversation->isDelegation() || !isset($conversation->id)
            ) {
                return ''; // a purposed run included (see buildLoadedSkillSubSkillPreloadRequestInputs)
            }
            $decisionModelSettings = AgentDecisionModelSettings::current();
            if ($decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_SKILL_PRELOAD, $conversation) === AgentDecisionModelSettings::MODE_OFF) {
                return '';
            }
            $subSkillQuestions = [];
            foreach ($loadedSkillNames as $loadedSkillName) {
                $subSkillQuestion = $this->buildSkillPreloadRequestInput($conversation, $decisionModelSettings, [], $loadedSkillName, 2, self::SKILL_PRELOAD_SUBJECT_MODEL_LOAD, $agentRun);
                if ($subSkillQuestion !== null) {
                    $subSkillQuestions[$loadedSkillName] = $subSkillQuestion;
                }
            }
            if ($subSkillQuestions === []) {
                return '';
            }
            $runToken = isset($conversation->runToken) ? $conversation->runToken : null;
            if ($this->decisionCallsService()->isCircuitBreakerOpen()) {
                return ''; // no call, no request built
            }
            $decisionBatch = new ArgusAgentDecisions();
            $argusSubSkillDecisions = [];
            foreach ($subSkillQuestions as $loadedSkillName => $subSkillQuestion) {
                $argusSubSkillDecisions[$loadedSkillName] = $this->buildSubSkillPreloadDecisionRequest($conversation, $subSkillQuestion, $decisionModelSettings);
                $decisionBatch->addChild($argusSubSkillDecisions[$loadedSkillName], self::SKILL_PRELOAD_SUBJECT_MODEL_LOAD . ':' . $loadedSkillName);
            }
            $decisionCallOutcome = $this->decisionCallsService()->runDecisionBatchWithoutBreakerCheck(
                $decisionBatch,
                null, // every sub-skill reads its own answer; a failed one trips the breaker for the batch
                $decisionModelSettings->timeoutSeconds,
                $decisionModelSettings->breakerSeconds,
                'sub-skill preload after load_skills'
            );
            $requestRefused = $decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_REFUSED;
            if ($requestRefused) {
                // our own gate — every child is stamped `fallback:refused` like every site
                $this->aiTrace('turn.skillPreload.modelLoadSkipped', ['conversationId' => $conversation->id, 'reason' => $decisionCallOutcome->failureReason]);
            } elseif ($decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_ERROR) {
                $this->aiTrace('turn.skillPreload.modelLoadFailed', ['conversationId' => $conversation->id ?? null, 'message' => $decisionCallOutcome->failureReason]);
                return '';
            } elseif (!$decisionCallOutcome->childrenSettled) {
                return ''; // the whole batch failed at the transport level (the runner tripped the breaker)
            }
            // only a parsable answer closes the breaker — any one child's will do
            $subSkillAnswerParsable = false;
            foreach ($argusSubSkillDecisions as $argusSubSkillDecision) {
                if ($argusSubSkillDecision->decided) {
                    $subSkillAnswerParsable = true;
                }
            }
            $this->decisionCallsService()->closeCircuitBreakerAfter($decisionCallOutcome, $subSkillAnswerParsable);
            $elapsedMs = $decisionCallOutcome->elapsedMs;
            $sentences = [];
            foreach ($subSkillQuestions as $loadedSkillName => $subSkillQuestion) {
                $argusSubSkillDecision = $argusSubSkillDecisions[$loadedSkillName];
                $subSkillDecidedBy = match (true) {
                    $requestRefused => AgentSkillPreloadDecision::FALLBACK_REFUSED,
                    $argusSubSkillDecision->decided => null,
                    $argusSubSkillDecision->transportFailed => AgentSkillPreloadDecision::FALLBACK_TRANSPORT,
                    default => null,
                };
                $subSkillFailureReason = $requestRefused ? $decisionCallOutcome->failureReason : $argusSubSkillDecision->transportFailureReason;
                $subSkillRequestId = bin2hex(random_bytes(8));
                $this->applySkillPreloadDecision($conversation, $subSkillQuestion, $argusSubSkillDecision, $subSkillDecidedBy, $decisionModelSettings, $runToken, $subSkillRequestId, $elapsedMs, $subSkillFailureReason, $agentRun);
                $this->turnEventsService()->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_REQUEST, 'turnStart', $subSkillQuestion->initiatorMessageIndex, null, $subSkillRequestId, [
                    'sites' => ['skillPreload'],
                    'batchId' => $decisionBatch->batchId,
                    'pass' => $subSkillQuestion->pass,
                    'unlockedByRootSkillName' => $loadedSkillName,
                    'subSkillPassSubject' => self::SKILL_PRELOAD_SUBJECT_MODEL_LOAD,
                    'decidedBy' => $subSkillDecidedBy ?? 'answered',
                    'transportFailureReason' => $subSkillFailureReason,
                    'modelVersion' => $argusSubSkillDecision->modelVersion,
                ], $runToken, $elapsedMs, $subSkillQuestion->arm, $subSkillRequestId);
                $appliedDecision = $conversation->getAgentWorkingState()?->skillPreloadDecision;
                if ($appliedDecision !== null && $appliedDecision->unlockedByRootSkillName === $loadedSkillName && $appliedDecision->admittedSkillNames !== []) {
                    $sentences[] = implode(', ', $appliedDecision->admittedSkillNames) . ' (sub-skill of ' . $loadedSkillName . ')';
                }
            }
            return $sentences === []
                ? ''
                : ' Also loaded, because the request falls into it: ' . implode('; ', $sentences) . '. Its rules and tools are available now — do not load it again.';
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $subSkillError) {
            $this->aiTrace('turn.skillPreload.modelLoadFailed', ['conversationId' => $conversation->id ?? null, 'message' => $subSkillError->getMessage()]);
            return '';
        }
    }

    /** The `decision` turn event of site B — every gate outcome, also a skip. */
    protected function buildDecisionTurnEvent(AIConversation $conversation, AgentSkillPreloadDecision $decision, AgentSkillPreloadDecisionQuestion $skillPreloadQuestion, string $catalogSnapshotHash, string $requestId, ?int $elapsedMs, bool $applied): AIConversationTurnEvent
    {
        $event = AIConversationTurnEvent::newInstance();
        $event->aiConversationId = (int)$conversation->id;
        $event->initiatorMessageIndex = $skillPreloadQuestion->initiatorMessageIndex;
        $event->evaluationMessageIndex = $conversation->messages->getNewestMessageIndex();
        $event->kind = AIConversationTurnEvent::KIND_DECISION;
        $event->site = AgentDecisionModelSettings::SITE_SKILL_PRELOAD;
        $event->subject = $skillPreloadQuestion->subject; // null = the roots pass; `pass2:<root>` = a follow-up pass under that root
        $event->eventKey = AIConversationTurnEvent::buildEventKey((int)$conversation->id, $event->initiatorMessageIndex, null, $event->kind, $event->site, $event->subject); // B's identity is per initiator (and pass) — retry-position-independent
        $event->requestId = $requestId;
        $event->arm = $decision->arm;
        $event->evalRun = isset($conversation->runSettings) ? ($conversation->runSettings->evalRun ?? null) : null;
        $event->overrideSource = $skillPreloadQuestion->overrideSource;
        $event->catalogSnapshotHash = $catalogSnapshotHash;
        $event->elapsedMs = $elapsedMs;
        $payload = $decision->mapToRepository();
        $payload['mode'] = $skillPreloadQuestion->mode;
        $payload['applied'] = $applied;
        $payload['pickOwnership'] = $decision->pickedSkillName !== null ? ($skillPreloadQuestion->snapshot['offered'][$decision->pickedToken ?? ''] ?? null) : null;
        $payload['loadedOwnership'] = $skillPreloadQuestion->snapshot['loaded'];
        $event->setPayloadArray($payload);
        return $event;
    }

    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    protected function decisionCallsService(): AgentDecisionCallsService
    {
        /** @var AgentDecisionCallsService $agentDecisionCallsService */
        $agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
        return $agentDecisionCallsService;
    }

    protected function turnEventsService(): AIConversationTurnEventsService
    {
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        return $aIConversationTurnEventsService;
    }

    /**
     * {@see admitAndPromoteSkillsOverCatalog()} over the offered catalog at the anchor
     * (built only when there is something to admit), under this process's claim of the conversation.
     *
     * @param string[] $skillNames
     * @param string[] $assumeLoadedSkillNames
     * @param AgentRun|null $agentRun the run that asks (its per-run reads), null outside a run
     * @throws AgentRunClaimLostException the conversation is claimed by another worker
     */
    protected function promoteSkillsFromSource(AIConversation $conversation, array $skillNames, string $source, ?AIConversationMessage $anchorMessage = null, bool $dryRun = false, array $assumeLoadedSkillNames = [], ?AgentRun $agentRun = null): AdmittedAISkills
    {
        $anchorMessage ??= $conversation->messages->last();
        if ($anchorMessage === null || $skillNames === []) {
            return new AdmittedAISkills();
        }
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $this->admitAndPromoteSkillsOverCatalog(
            $conversation,
            $conversation->buildOfferedSkillCatalog($anchorMessage, $assumeLoadedSkillNames, $agentRun),
            $skillNames,
            $source,
            $anchorMessage,
            $dryRun,
            $aIConversationRunClaimsService->getRunTokenClaimedInThisProcess($conversation)
        );
    }

    /**
     * Preload skills from a NON-model source under the OFFER policy ({@see OfferedSkillCatalog::buildAdmissionFor()}):
     * the names are resolved here, judged against the turn's offered catalog, and the admitted ones promoted onto the
     * anchor. A DECISION-MODEL preload is admitted even when it pushes another resident out:
     * refusing it never spared that resident — the model loaded the same skill itself in its first step, with the same
     * eviction and one model call more. The sweep still takes an unused speculative preload first. `$dryRun` judges the
     * full predicate and promotes nothing (shadow's `wouldApply`); otherwise the admitted names are the ones promoted.
     *
     * @param string[] $skillNames
     * @param string|null $runToken the claim this process holds on the conversation
     * @throws AgentRunClaimLostException the conversation is claimed by another worker
     */
    public function admitAndPromoteSkillsOverCatalog(
        AIConversation $conversation,
        OfferedSkillCatalog $offeredSkillCatalog,
        array $skillNames,
        string $source,
        AIConversationMessage $anchorMessage,
        bool $dryRun,
        ?string $runToken
    ): AdmittedAISkills {
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = AISkill::getService();
        return $this->admitAndPromoteRequestedSkills($conversation, $offeredSkillCatalog, $aiSkillsService->resolveRequestedSkills($skillNames, []), $source, $anchorMessage, $dryRun, $runToken);
    }

    /**
     * Site A: the plan's `← next` item names the skills it needs — preload them through the offer
     * policy before the model call, deterministically (no decision model). `planItem:on` promotes; `planItem:shadow`
     * only records what it WOULD have promoted; `off` = nothing. An event is written only when the candidate set is
     * non-empty (an evaluation with nothing to load is not a row); names already loaded are not candidates.
     *
     * @param string|null $runToken the turn's token (its decision event)
     * @param string|null $claimedRunToken the claim this process holds on the conversation (the promotion's write)
     * @param AgentRun|null $agentRun the run that asks (its per-run reads), null outside a run
     * @throws AgentRunClaimLostException the conversation is claimed by another worker
     */
    public function admitSkillsForNextPlanItem(AIConversation $conversation, ?string $runToken, ?string $claimedRunToken, ?AgentRun $agentRun = null): void
    {
        try {
            $decisionModelSettings = AgentDecisionModelSettings::current();
            $siteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_PLAN_ITEM, $conversation); // the kill switch reaches the deterministic site too
            if ($siteMode === AgentDecisionModelSettings::MODE_OFF || ($conversation->purposeName ?? null) === null) {
                return;
            }
            $taskPlan = $conversation->getTaskPlan();
            // The UNION of the skills the next three owed leaves name or inherit.
            // The first-leaf-only lookup fired on the current act alone: while the draft leaf stayed open, the
            // act one leaf behind it was loaded by the model's own `load_skills` step at every act switch.
            $loadedSkillNames = $conversation->messages->getNewestLoadedCapabilities()?->getLoadedSkillNames() ?? [];
            // … plus the FIRST act ahead of that window (read-ahead by one act): the act skill the
            // owner's answer leads into is resident before the answer — the decision model's plan walk showed the
            // switch itself is deterministic, the classifier was not ({@see TaskPlanItems::collectSkillNamesDueNow()}).
            // A `load_skills` of the model is never refused (the anchoring relieves, it does not gate).
            $nextItemSkillNames = $taskPlan?->items?->collectSkillNamesDueNow(TaskPlanItems::PLAN_ITEM_PRELOAD_LEAF_LOOKAHEAD, $loadedSkillNames) ?? [];
            if ($nextItemSkillNames === []) {
                return;
            }
            $anchorMessage = $conversation->messages->last();
            if ($anchorMessage === null) {
                return;
            }
            $candidateNames = array_values(array_diff($nextItemSkillNames, $loadedSkillNames));
            if ($candidateNames === []) {
                return;
            }
            $applies = $siteMode === AgentDecisionModelSettings::MODE_ON;
            /** @var AISkillsService $aiSkillsService */
            $aiSkillsService = AISkill::getService();
            $requestedAISkills = $aiSkillsService->resolveRequestedSkills($candidateNames, []);
            // The step runs again while an admitted root unlocks sub-skills the pass before refused as not offered
            //: they are retried under the now-loaded root (in shadow with the root ASSUMED
            // loaded). Bounded by the pass cap; a pass that unlocks nothing ends it.
            $admission = new AdmittedAISkills();
            $admission->passCount = 0;
            $remainingSkillNames = $candidateNames;
            do {
                $passAdmission = $this->admitAndPromoteRequestedSkills(
                    $conversation,
                    $conversation->buildOfferedSkillCatalog($anchorMessage, $admission->admittedSkillNames, $agentRun),
                    $requestedAISkills->withRequestedSkillNames($remainingSkillNames),
                    LoadedCapability::SOURCE_PLAN_ITEM,
                    $anchorMessage,
                    !$applies,
                    $claimedRunToken
                );
                $admission->mergePass($passAdmission);
                $remainingSkillNames = $passAdmission->getSkillNamesToRetryUnderAdmittedRoots($requestedAISkills);
            } while ($remainingSkillNames !== [] && $admission->passCount < AgentSkillPreloadDecision::MAX_PASSES);
            /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
            $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
            $aIConversationTurnEventsService->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_DECISION, AgentDecisionModelSettings::SITE_PLAN_ITEM, $conversation->messages->getNewestInitiatorMessageIndex(), mb_substr(implode(', ', $nextItemSkillNames), 0, 120), null, [
                'mode' => $siteMode,
                'applied' => $applies && $admission->admittedSkillNames !== [],
                'planItem' => implode(', ', $nextItemSkillNames),
                'candidates' => $candidateNames,
                'admitted' => $admission->admittedSkillNames,
                'rejected' => $admission->rejectionReasonsBySkillName,
                'passes' => $admission->passCount,
            ], $runToken);
            $this->aiTrace('turn.planItemPreload', ['conversationId' => $conversation->id ?? null, 'mode' => $siteMode, 'candidates' => $candidateNames, 'admitted' => $admission->admittedSkillNames, 'rejected' => $admission->rejectionReasonsBySkillName, 'passes' => $admission->passCount]);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost;
        } catch (Throwable $preloadError) {
            $this->aiTrace('turn.planItemPreload.failed', ['conversationId' => $conversation->id ?? null, 'message' => $preloadError->getMessage()]);
        }
    }

    /**
     * The admission of a resolved request under `$offeredSkillCatalog` and — unless `$dryRun` — the promotion of the
     * admitted names; the admitted names are then the ones actually promoted.
     *
     * @throws AgentRunClaimLostException the conversation is claimed by another worker
     */
    protected function admitAndPromoteRequestedSkills(
        AIConversation $conversation,
        OfferedSkillCatalog $offeredSkillCatalog,
        RequestedAISkills $requestedAISkills,
        string $source,
        AIConversationMessage $anchorMessage,
        bool $dryRun,
        ?string $runToken
    ): AdmittedAISkills {
        $admittedAISkills = $offeredSkillCatalog->buildAdmissionFor($requestedAISkills, $source);
        if ($dryRun || $admittedAISkills->admittedSkillNames === []) {
            return $admittedAISkills;
        }
        $admittedAISkills->admittedSkillNames = $this->promoteAdmittedSkillNames($conversation, $admittedAISkills->admittedSkillNames, $source, $anchorMessage, $runToken);
        return $admittedAISkills;
    }

    /**
     * The shared PROMOTION TAIL of every non-model preload: the names have passed their policy; here the not-yet-loaded
     * ones are promoted onto the anchor (the newest message's cumulative set extended, provenance stamped with
     * $source) and the anchor's capability column persisted. Returns the names actually promoted.
     *
     * @param string[] $admittedSkillNames
     * @param string|null $runToken the claim this process holds on the conversation
     * @return string[]
     * @throws AgentRunClaimLostException the conversation is claimed by another worker
     */
    public function promoteAdmittedSkillNames(AIConversation $conversation, array $admittedSkillNames, string $source, AIConversationMessage $anchorMessage, ?string $runToken): array
    {
        $newSkillNames = $conversation->promoteSkillNamesOntoAnchorMessage($admittedSkillNames, $source, $anchorMessage);
        if ($newSkillNames === []) {
            return [];
        }
        if (isset($conversation->id) && isset($anchorMessage->id)) {
            // the capability column ONLY (other processes write columns of the same row — the screening
            // worker's toolCall, a delegation child's status — a full-row upsert from a stale copy could clobber them);
            // a partial write joins a caller-owned transaction.
            $this->messagesService()->persistLoadedCapabilitiesUnderRunClaim($conversation, $anchorMessage, $runToken);
        }
        return $newSkillNames;
    }

    /**
     * Skills a CUSTOMER-composed widget in the pending inbound message declares as its handlers
     * ({@see AISkill::$preloadOnWidgetTypes}) become loaded capabilities for the coming turn — the routing is the
     * message's own structure, not a model decision (a weak model skipped the load_skills hop on a message that was
     * nothing but a marked-element block, or answered the JSON literally). Root dialogs only: a delegation child's
     * seed is model-authored task text. The promotion lands on the NEWEST message — the anchor the loop reads its
     * cumulative set from (a signal appended after the customer message would otherwise shadow it) — through the
     * entity's own {@see AIConversationMessage::loadSkills()}, so retention mode, token baseline, turn index and
     * feature flags resolve exactly as on the load_skills path. Gated like that path: purpose binding + rail,
     * entitlement, vision, no sub-skills. Idempotent — already-loaded skills are skipped. A persisted anchor is written
     * back with update(0), the same way the purpose seed writes the kickoff signal.
     */
    public function preloadSkillsTriggeredByWidgetFences(AIConversation $conversation): void
    {
        if ($conversation->isDelegation()) {
            return;
        }
        $pendingUserMessage = ($conversation->messages ?? null)?->getNewestUnansweredOwnerRow();
        if ($pendingUserMessage === null) {
            return;
        }
        $widgetFenceTypes = $pendingUserMessage->getWidgetTypes();
        if ($widgetFenceTypes === []) {
            return;
        }
        $anchorMessage = $conversation->messages->last();
        if ($anchorMessage === null) {
            return;
        }
        $purposeName = $conversation->purposeName ?? null;
        $triggeredSkills = AISkill::getService()->getCatalog()
            ->filterByPurposeName($purposeName)
            ->filterByInitiatorType($conversation->initiatorType ?? null)
            ->filterByPreloadWidgetTypes($widgetFenceTypes);
        $this->promoteStructurallyTriggeredSkills($conversation, $triggeredSkills, $anchorMessage);
    }

    /**
     * Skills BOUND to this conversation's initiator type ({@see AISkill::$onlyOnInitiatorTypes}) become loaded
     * capabilities for the coming turn — what a call needs, on a call conversation. Same promotion,
     * same gates as the widget-triggered preload; root dialogs only; idempotent.
     */
    public function preloadSkillsBoundToInitiatorType(AIConversation $conversation): void
    {
        if ($conversation->isDelegation()) {
            return;
        }
        $initiatorType = $conversation->initiatorType ?? null;
        if ($initiatorType === null) {
            return;
        }
        $anchorMessage = $conversation->messages->last();
        if ($anchorMessage === null) {
            return;
        }
        $boundSkills = AISkill::getService()->getCatalog()
            ->filterByPurposeName($conversation->purposeName ?? null)
            ->filterBoundToInitiatorType($initiatorType);
        $this->promoteStructurallyTriggeredSkills($conversation, $boundSkills, $anchorMessage);
    }

    /**
     * The shared promotion tail of the structural preloads (widget-triggered, initiator-bound): roots only,
     * entitlement, vision, the purpose rail, then the newest message's cumulative set extended and persisted.
     */
    protected function promoteStructurallyTriggeredSkills(
        AIConversation $conversation,
        AISkills $triggeredSkills,
        AIConversationMessage $anchorMessage
    ): void {
        $triggeredSkillNames = [];
        foreach ($triggeredSkills->getElements() as $triggeredSkill) {
            if (!$triggeredSkill->isSubSkill) { // a sub-skill is visible only under its loaded root — never a preload
                $triggeredSkillNames[] = $triggeredSkill->name;
            }
        }
        if ($triggeredSkillNames === []) {
            return;
        }
        $triggeredSkillNames = $conversation->findPermittedSkillNames($triggeredSkillNames);
        $triggeredSkillNames = $conversation->findVisionCapableSkillNames($triggeredSkillNames);
        $triggeredSkillNames = $conversation->findRailedSkillNames($triggeredSkillNames);
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        $this->promoteAdmittedSkillNames($conversation, $triggeredSkillNames, LoadedCapability::SOURCE_STRUCTURAL, $anchorMessage, $aIConversationRunClaimsService->getRunTokenClaimedInThisProcess($conversation));
    }
}
