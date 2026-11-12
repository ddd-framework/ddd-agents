<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCoverToolTelemetry;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointBriefingHeader;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointBriefing;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointBriefingInputs;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointDecision;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointDecisions;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointHistory;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointTrigger;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorPolicy;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorSettings;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Entities\Models\Selection\ModelScope;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The supervisor's SHARED brain — the pure, repo-light logic used by BOTH the live async
 * supervisor worker AND the offline replay eval harness (the application's eval harness),
 * so live and replay produce the identical briefing and honour the identical checkpoint rules.
 *
 * It owns four concerns and NOTHING that touches customer-data repos:
 *  1. WHEN — the beats: {@see SupervisorCheckpointTrigger} (a step beat after a material tool batch, a settled beat
 *     after a completed owner interaction; the earlier cadence/milestone/event trigger is gone).
 *  2. WHAT it reads — {@see buildBriefing()} (with {@see AIConversation::buildTaskPlanAsOfMessageIndex()}) composes the 7-layer briefing
 *     as the supervisor turn's seed USER content, from artifacts already paid for.
 *  3. WHICH model — {@see resolveModelName()}: the per-conversation `orchestratorModelNameOverride` or the
 *     ORCHESTRATOR-scope default.
 *  4. WHAT came back — {@see buildDecisionsAfterMessageId()}: the terminal calls the supervised side materializes.
 *
 * The actuators (inject the signal, apply plan ops, the escalation ladder) live in the async worker/loop, not
 * here — this service produces decisions and text, it never mutates the supervised conversation.
 */
class SupervisorCheckpointService extends Service
{
    /**
     * The config key of the supervisor operating prompt (diagnose → steer → conservative verdict JSON):
     * `config/app/AI/Prompts/AI/Supervisor/Checkpoint.md` of the application, else the agent package's default.
     */
    public const string CONFIG_KEY_OPERATING_PROMPT = 'AI.Prompts.AI.Supervisor.Checkpoint';

    /**
     * The config key prefix of a purpose's audit criteria: `<prefix>.<purpose name>` resolves
     * `config/app/AI/Prompts/AI/Supervisor/Criteria/<Domain>/<Name>.md` (the purpose name's dots are folders, like the
     * purpose files under `AI/Purposes/`).
     */
    public const string CONFIG_KEY_PREFIX_AUDIT_CRITERIA = 'AI.Prompts.AI.Supervisor.Criteria';

    /** The default supervisor operating prompt — the base system prompt of every checkpoint turn (the eval harness may override it with a candidate file). */
    public function getDefaultOperatingPrompt(): string
    {
        $operatingPrompt = Config::get(self::CONFIG_KEY_OPERATING_PROMPT);
        return is_string($operatingPrompt) ? $operatingPrompt : '';
    }

    /**
     * The ORCHESTRATOR model for THIS supervised conversation: the admin's per-conversation override, else
     * the scope default. The default is TIER-INDEPENDENT and anchored in the VENDOR — `getModelForScope(ORCHESTRATOR)`
     * returns the pinned `SCOPE_PINNED_DEFAULT_MODELS[ORCHESTRATOR]` (MiniMax M3, the eval-gate winner), NOT a price-tier
     * pick. The admin picks any model.
     */
    public function resolveModelName(AIConversation $conversation): ?string
    {
        if (($conversation->orchestratorModelNameOverride ?? null) !== null && $conversation->orchestratorModelNameOverride !== '') {
            return $conversation->orchestratorModelNameOverride;
        }
        $configuredModelName = $this->findSupervisorSettings()->modelName;
        if ($configuredModelName !== null) {
            return $configuredModelName;
        }
        /** @var AIModelsService $aIModelsService */
        $aIModelsService = DDDService::instance()->getService(AIModelsService::class);
        return $aIModelsService->getModelForScope(ModelScope::ORCHESTRATOR)?->name;
    }

    /** The committed `config/app/AI/supervisor.php` (never throws — a missing file is an empty array). */
    public function configuredSupervisorSettings(): array
    {
        try {
            $configuredValue = Config::get(self::CONFIG_KEY);
            return is_array($configuredValue) ? $configuredValue : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The committed settings as one value (the switch, the model, the effort per beat, the policy without a purpose),
     * built per call — never cached on this shared service.
     */
    public function findSupervisorSettings(): SupervisorSettings
    {
        return SupervisorSettings::fromConfig($this->configuredSupervisorSettings());
    }

    /** The versioned baseline of the global switch (`config/app/AI/supervisor.php`). */
    public const string CONFIG_KEY = 'AI.supervisor';

    /**
     * The GLOBAL switch (default OFF): false = no checkpoint is ever dispatched for a
     * conversation that is not armed by a run-cap override above zero — no tandem lookup, no briefing, no verdict call,
     * no steer. Implemented as "run cap 0" ({@see runCap()}), the one contract every supervision path already honours
     * (the dispatch gate, the admin console). Read from the committed config only (no environment
     * variable switches supervision).
     */
    public function isSupervisionEnabledGlobally(): bool
    {
        return $this->findSupervisorSettings()->enabled;
    }

    /**
     * The run cap for a supervised conversation ({@see AIConversation::resolveSupervisorRunCap()}) under the global
     * switch ({@see isSupervisionEnabledGlobally()}).
     */
    public function runCap(AIConversation $conversation): int
    {
        return $conversation->resolveSupervisorRunCap($this->isSupervisionEnabledGlobally());
    }

    /** The marker every briefing-side cut carries ({@see SupervisorCheckpointBriefing::BRIEFING_ABRIDGED_MARKER}). */
    public const string BRIEFING_ABRIDGED_MARKER = SupervisorCheckpointBriefing::BRIEFING_ABRIDGED_MARKER;

    /** The newest agent text's cut ({@see SupervisorCheckpointBriefing::NEWEST_AGENT_TEXT_MAX_CHARS}). */
    public const int NEWEST_AGENT_TEXT_MAX_CHARS = SupervisorCheckpointBriefing::NEWEST_AGENT_TEXT_MAX_CHARS;

    /**
     * Cut a briefing line to $maxChars and SAY SO: a bare `mb_substr` leaves the supervisor unable to tell a
     * briefing cut from an agent message that really ended mid-sentence. Unchanged when it fits.
     */
    public static function abridgeForBriefing(string $text, int $maxChars): string
    {
        return SupervisorCheckpointBriefing::abridgeForBriefing($text, $maxChars);
    }

    /** The beat classes of a checkpoint, written on the briefing header. */
    public const string BEAT_STEP = SupervisorCheckpointBriefingHeader::BEAT_STEP;
    public const string BEAT_SETTLED = SupervisorCheckpointBriefingHeader::BEAT_SETTLED;

    /** Step beats per owner turn ({@see SupervisorCheckpointTrigger::MAX_STEP_BEATS_PER_TURN}). */
    public const int MAX_STEP_BEATS_PER_TURN = SupervisorCheckpointTrigger::MAX_STEP_BEATS_PER_TURN;

    /** The briefing's cuts ({@see SupervisorCheckpointBriefing}). */
    public const int LEDGER_ARGS_MAX_CHARS = SupervisorCheckpointBriefing::LEDGER_ARGS_MAX_CHARS;
    public const int LEDGER_RESULT_MAX_CHARS = SupervisorCheckpointBriefing::LEDGER_RESULT_MAX_CHARS;
    public const int OWNER_ROW_MAX_CHARS = SupervisorCheckpointBriefing::OWNER_ROW_MAX_CHARS;
    public const int WIDGET_MAX_CHARS = SupervisorCheckpointBriefing::WIDGET_MAX_CHARS;
    public const int EARLIER_AGENT_TEXT_MAX_CHARS = SupervisorCheckpointBriefing::EARLIER_AGENT_TEXT_MAX_CHARS;
    public const int TOOL_DIGEST_SUMMARY_MAX_CHARS = 150;

    /**
     * What the supervisor knows about the purpose of `$supervisedConversation`: the purpose's own
     * {@see SupervisorPolicy} ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::buildSupervisorPolicy()}).
     * A conversation without a purpose (a free dialog an admin armed; no supervised conversation at all) gets the
     * configured `policyClassForConversationsWithoutAPurpose`, else the AI base policy. Built per call.
     */
    public function findSupervisorPolicyFor(?AIConversation $supervisedConversation): SupervisorPolicy
    {
        $purpose = $supervisedConversation?->findPurpose();
        if ($purpose !== null) {
            return $purpose->buildSupervisorPolicy();
        }
        $policyClass = $this->findSupervisorSettings()->policyClassForConversationsWithoutAPurpose;
        if ($policyClass !== null && is_a($policyClass, SupervisorPolicy::class, true)) {
            return new $policyClass();
        }
        return new SupervisorPolicy();
    }

    /** The FACTS section of a briefing up to the index ({@see SupervisorPolicy::buildFactsSection()}; '' = none). */
    public function buildFactsSection(AIConversation $conversation, int $asOfMessageIndex): string
    {
        return $this->findSupervisorPolicyFor($conversation)->buildFactsSection($conversation, $asOfMessageIndex);
    }

    /** The audit criteria of a purpose ({@see CONFIG_KEY_PREFIX_AUDIT_CRITERIA}), empty when none exists. */
    public function getAuditCriteriaForPurpose(?string $purposeName): string
    {
        if ($purposeName === null || $purposeName === '' || preg_match('/^[A-Za-z0-9.]+$/', $purposeName) !== 1) {
            return '';
        }
        $auditCriteria = Config::get(self::CONFIG_KEY_PREFIX_AUDIT_CRITERIA . '.' . $purposeName);
        return is_string($auditCriteria) ? trim($auditCriteria) : '';
    }

    /**
     * The BOOKKEEPER briefing ({@see SupervisorCheckpointBriefing}) up to a message index — the seed USER content of a
     * checkpoint turn: a full briefing without `$sinceMessageIndex`, else the delta after it; `$beat` is written on the
     * header (`beat: step|settled`) — the executor refuses a `correct` on a step beat. `$agentSpaceStateText` is the live
     * state of the space of a settled beat, read by the caller (it needs the tool executor).
     */
    public function buildBriefing(AIConversation $conversation, int $asOfMessageIndex, string $checkpointReason, ?int $sinceMessageIndex = null, string $beat = self::BEAT_SETTLED, ?string $agentSpaceStateText = null): string
    {
        $supervisorCheckpointBriefingHeader = SupervisorCheckpointBriefingHeader::create($beat, $conversation->purposeName ?? null, $asOfMessageIndex, $sinceMessageIndex, $checkpointReason);
        return SupervisorCheckpointBriefing::buildFor(
            $supervisorCheckpointBriefingHeader,
            $conversation,
            $this->buildBriefingInputs($conversation, $supervisorCheckpointBriefingHeader, $agentSpaceStateText)
        )->render();
    }

    /**
     * What the briefing under `$supervisorCheckpointBriefingHeader` needs from the world, in the order the briefing
     * reads it: the purpose, the tool digest (a full briefing), the task plans, the audit renders and the criteria (a
     * settled beat), the FACTS, the compaction cover's counts. The purpose's lists, texts and FACTS come from its
     * {@see SupervisorPolicy}.
     */
    public function buildBriefingInputs(AIConversation $conversation, SupervisorCheckpointBriefingHeader $supervisorCheckpointBriefingHeader, ?string $agentSpaceStateText): SupervisorCheckpointBriefingInputs
    {
        $asOfMessageIndex = (int)$supervisorCheckpointBriefingHeader->asOfMessageIndex;
        $supervisorCheckpointBriefingInputs = new SupervisorCheckpointBriefingInputs();
        $supervisorCheckpointBriefingInputs->purpose = $conversation->findPurpose();
        $supervisorPolicy = $this->findSupervisorPolicyFor($conversation);
        if (!$supervisorCheckpointBriefingHeader->isDelta()) {
            $supervisorCheckpointBriefingInputs->toolDigestLines = $this->buildToolDigestLines($supervisorPolicy->getToolDigestSkillNames());
        }
        $supervisorCheckpointBriefingInputs->ownerWidgetTypesDigest = $supervisorPolicy->getOwnerWidgetTypesDigest();
        $supervisorCheckpointBriefingInputs->groundingReadToolNames = $supervisorPolicy->getGroundingReadToolNames();
        $this->addTaskPlansToBriefingInputs(
            $supervisorCheckpointBriefingInputs,
            $conversation,
            $asOfMessageIndex,
            $supervisorCheckpointBriefingHeader->isDelta() ? $supervisorCheckpointBriefingHeader->sinceMessageIndex : null
        );
        if (!$supervisorCheckpointBriefingHeader->isStepBeat()) {
            /** @var AIConversationAuditRenderService $auditRenderService */
            $auditRenderService = DDDService::instance()->getService(AIConversationAuditRenderService::class);
            $supervisorCheckpointBriefingInputs->widgetInventoryText = $auditRenderService->renderWidgetInventory($conversation);
            $supervisorCheckpointBriefingInputs->writeLedgerText = $auditRenderService->renderWriteLedger($conversation, 600);
            $supervisorCheckpointBriefingInputs->outwardActionsText = $auditRenderService->renderOutwardActions($conversation);
            $supervisorCheckpointBriefingInputs->taskPlanWithProvenanceText = $auditRenderService->renderTaskPlanWithProvenance($conversation);
            $supervisorCheckpointBriefingInputs->agentSpaceStateText = $agentSpaceStateText;
            $supervisorCheckpointBriefingInputs->agentSpaceStateHeadline = $supervisorPolicy->getAgentSpaceStateHeadline();
        }
        $supervisorCheckpointBriefingInputs->factsSection = $supervisorPolicy->buildFactsSection($conversation, $asOfMessageIndex);
        if (!$supervisorCheckpointBriefingHeader->isStepBeat()) {
            $supervisorCheckpointBriefingInputs->auditCriteria = $this->getAuditCriteriaForPurpose($conversation->purposeName ?? null);
        }
        // the briefing's rows stop at the index: the lowest loaded row counts only when it stands at or before it
        $lowestLoadedMessageIndex = $conversation->messages->getLowestMessageIndex();
        $supervisorCheckpointBriefingInputs->coverToolTelemetry = $this->buildCoverToolTelemetryBefore(
            $conversation,
            $lowestLoadedMessageIndex !== null && $lowestLoadedMessageIndex <= $asOfMessageIndex ? $lowestLoadedMessageIndex : PHP_INT_MAX,
            $asOfMessageIndex
        );
        return $supervisorCheckpointBriefingInputs;
    }

    /**
     * The task plans of the PLAN section ({@see SupervisorCheckpointBriefing::renderPlanSection()}): the stored plan
     * when the briefing stands at the newest row, else the replay as of the index; with a since-index and a replayed
     * plan, the replay as of the since-index the `*` marks compare against.
     */
    protected function addTaskPlansToBriefingInputs(SupervisorCheckpointBriefingInputs $supervisorCheckpointBriefingInputs, AIConversation $conversation, int $asOfMessageIndex, ?int $sinceMessageIndex): void
    {
        $storedTaskPlan = $conversation->buildTaskPlanCopyWhenAtNewestMessageIndex($asOfMessageIndex);
        $supervisorCheckpointBriefingInputs->taskPlan = $storedTaskPlan ?? $conversation->buildTaskPlanAsOfMessageIndex($asOfMessageIndex);
        $supervisorCheckpointBriefingInputs->taskPlanIsTheStoredPlan = $storedTaskPlan !== null;
        if ($sinceMessageIndex !== null && $storedTaskPlan === null && $supervisorCheckpointBriefingInputs->taskPlan?->items !== null) {
            $supervisorCheckpointBriefingInputs->previousTaskPlan = $conversation->buildTaskPlanAsOfMessageIndex($sinceMessageIndex);
        }
    }

    /** The PLAN section of a briefing as of the index ({@see SupervisorCheckpointBriefing::renderPlanSection()}). */
    protected function buildPlanSection(AIConversation $conversation, int $asOfMessageIndex, ?int $sinceMessageIndex): string
    {
        $supervisorCheckpointBriefingInputs = new SupervisorCheckpointBriefingInputs();
        $this->addTaskPlansToBriefingInputs($supervisorCheckpointBriefingInputs, $conversation, $asOfMessageIndex, $sinceMessageIndex);
        return SupervisorCheckpointBriefing::renderPlanSection(
            $supervisorCheckpointBriefingInputs->taskPlan,
            $supervisorCheckpointBriefingInputs->taskPlanIsTheStoredPlan,
            $supervisorCheckpointBriefingInputs->previousTaskPlan,
            $sinceMessageIndex
        );
    }

    /**
     * The tool digest of the purpose's acts (`$skillNames`, the policy's digest skills): one line per tool, name and
     * summary, grouped by skill. Platform tools without a package (askOwner, the plan tick, load_skills) get a reviewed
     * line each. No skills = no digest.
     *
     * @param string[] $skillNames
     * @return string[]
     */
    protected function buildToolDigestLines(array $skillNames): array
    {
        if ($skillNames === []) {
            return [];
        }
        $lines = [];
        $seen = [];
        foreach ($skillNames as $skillName) {
            try {
                $packageClasses = AISkill::getPackagesForSkill($skillName);
                if ($packageClasses === []) {
                    continue;
                }
                $definitions = ToolDefinitions::forPackages($packageClasses);
            } catch (Throwable) {
                continue;
            }
            $toolLines = [];
            foreach ($definitions->getElements() as $definition) {
                $toolName = (string)($definition->name ?? '');
                if ($toolName === '' || isset($seen[$toolName])) {
                    continue;
                }
                $seen[$toolName] = true;
                $summary = trim((string)($definition->summary ?? ''));
                if ($summary === '') {
                    $summary = trim((string)strtok(trim((string)($definition->description ?? '')), "\n"));
                }
                $toolLines[] = '- ' . $toolName . ($summary !== '' ? ' — ' . self::abridgeForBriefing(self::collapseWhitespace($summary), self::TOOL_DIGEST_SUMMARY_MAX_CHARS) : '');
            }
            if ($toolLines !== []) {
                $lines[] = "{$skillName}:";
                foreach ($toolLines as $toolLine) {
                    $lines[] = $toolLine;
                }
            }
        }
        $lines[] = 'platform:';
        $lines[] = '- askOwner — a closed question rendered as a widget; a catalogue key records the pick itself';
        $lines[] = '- set_task_status — a plan tick; the platform closes evidence leaves from the record and rejects a tick without its gate';
        $lines[] = '- load_skills — loads an act\'s skill (its tools become callable); never work';
        return $lines;
    }

    public static function collapseWhitespace(string $text): string
    {
        return SupervisorCheckpointBriefing::collapseWhitespace($text);
    }

    public static function abridgeArguments(ToolCall $toolCall): string
    {
        return SupervisorCheckpointBriefing::abridgeArguments($toolCall);
    }

    /**
     * The decisions of the tandem NEWER than the tandem row `$afterMessageId` that the supervised conversation may
     * materialize: the v2 terminal calls, each with its signal text ({@see SupervisorCheckpointDecision}; null = no
     * intervention). A tool-less ASSISTANT text (a stored v1 verdict, a prose answer) decides nothing — only the admin
     * console reads stored verdicts ({@see \DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorLegacyVerdict}).
     */
    public function buildDecisionsAfterMessageId(AIConversation $supervisorConversation, int $afterMessageId): SupervisorCheckpointDecisions
    {
        $supervisorCheckpointDecisions = new SupervisorCheckpointDecisions();
        foreach ($supervisorConversation->messages->buildSupervisorCheckpointHistory()->getDecisionMessagesAfterMessageId($afterMessageId) as $decisionMessage) {
            if (($decisionMessage->toolCall ?? null) === null) {
                continue;
            }
            $supervisorCheckpointDecision = SupervisorCheckpointDecision::fromTerminalCallMessage($decisionMessage);
            $supervisorCheckpointDecisions->add($supervisorCheckpointDecision);
        }
        return $supervisorCheckpointDecisions;
    }

    /** The in-memory stubs the model context carries for EARLIER checkpoints ({@see SupervisorCheckpointHistory}). */
    public const string EARLIER_BRIEFING_STUB = SupervisorCheckpointHistory::EARLIER_BRIEFING_STUB;
    public const string EARLIER_LOOKUP_STUB = SupervisorCheckpointHistory::EARLIER_LOOKUP_STUB;
    /** The line that opens the delta of every briefing — everything from it on is the per-checkpoint material. */
    public const string BRIEFING_DELTA_START = SupervisorCheckpointHistory::BRIEFING_DELTA_START;

    /** The prefix of a resolved-decision note: a signal row that consumes a stale correction and arms NO step. */
    public const string RESOLVED_DECISION_NOTE_PREFIX = AIConversationMessage::SUPERVISOR_RESOLVED_NOTE_PREFIX;

    /**
     * The summarised history's tool telemetry before the first loaded raw row
     * ({@see \DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionCover::buildToolTelemetryBefore()}):
     * the COMPLETED counts of the cover nodes whose range lies before `$firstLoadedMessageIndex` (PHP_INT_MAX = no raw
     * row) and at or before `$upToMessageIndex`. Reads the already-loaded {@see AIConversation::$compactionNodes} only —
     * never a lazy-load, never a query: without a loaded tree the counts are empty and complete.
     */
    protected function buildCoverToolTelemetryBefore(AIConversation $conversation, int $firstLoadedMessageIndex, int $upToMessageIndex): AIConversationCoverToolTelemetry
    {
        // isset — NOT `?->`: an unset #[LazyLoad] tree must stay unloaded here (a query on the checkpoint path).
        if (!isset($conversation->compactionNodes)) {
            return new AIConversationCoverToolTelemetry();
        }
        return $conversation->compactionNodes->buildCover()->buildToolTelemetryBefore($firstLoadedMessageIndex, $upToMessageIndex);
    }
}
