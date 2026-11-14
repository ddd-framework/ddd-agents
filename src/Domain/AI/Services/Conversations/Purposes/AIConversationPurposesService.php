<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Purposes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Loads {@see AIConversationPurpose}s from their `.md` config, mirroring
 * {@see AISkillsService::getAISkillByName()}: `getPurposeByName('Schedule.Run')` →
 * `Config::get('AI.Purposes.Schedule.Run')` → `config/app/AI/Purposes/Schedule/Run.md`
 * (YAML frontmatter → the fields, body → the directive). Reuses {@see AISkillsService::splitFrontmatter()}.
 *
 * GRACEFUL DEGRADATION: a purpose whose `.md` is gone (renamed/removed after deploy) resolves to
 * null with a logged warning at the call site — never a throw in the context build. A purpose class that blocks tools
 * by what they declare ({@see AIConversationPurpose::getToolNamesBlockedByDeclaration()}) reads the tool catalog,
 * which throws when a skill binds a class that does not exist.
 */
class AIConversationPurposesService extends Service
{
    /** @var string The config path prefix (mirrors AISkillsService::CONFIG_SKILLS_PREFIX). */
    public const string CONFIG_PURPOSES_PREFIX = 'AI.Purposes.';

    /**
     * @var string The frontmatter key that names the purpose's entity class — a fully qualified class
     * name extending {@see AIConversationPurpose}. Read from a file's OWN frontmatter only, never inherited.
     */
    public const string FRONTMATTER_KEY_PURPOSE_CLASS = 'purposeClass';

    /**
     * @var string Config path of the purpose REQUIREMENT OVERLAY (`config/app/AI/purposeRequirements.php`):
     *      purpose name => {requiredFeatureFlags?: string[]} — the gates an application puts on a purpose without editing
     *      the purpose file, united with the frontmatter's at load time (after the `inheritsFrom` merge).
     */
    public const string CONFIG_PURPOSE_REQUIREMENTS = 'AI.purposeRequirements';

    /** @var array<string, array<string, mixed>>|null the purpose requirement overlay, read once per process */
    protected static ?array $purposeRequirementsByPurposeName = null;

    public bool $throwErrors = false;

    /**
     * Returns an {@see AIConversationPurpose} by its dotted name, or null when the `.md` is absent/empty.
     *
     * @throws NotFoundException only when {@see $throwErrors} is set.
     */
    /**
     * The purpose of a PURPOSED conversation (its stamped `purposeName`), or null for a plain conversation —
     * the agent loop's generic entry to purpose-individual behaviour (e.g.
     * {@see AIConversationPurpose::applyConsequencesOfPersistedTaskPlan()}).
     */
    public function getPurposeOfConversation(\DDD\Domain\AI\Entities\Conversations\AIConversation $conversation): ?AIConversationPurpose
    {
        $purposeName = isset($conversation->purposeName) ? $conversation->purposeName : null;
        if (!$purposeName) {
            return null;
        }
        return $this->getPurposeByName($purposeName);
    }

    /**
     * @var array<int, string|null> root conversation id => its purpose name (null = none) for the reads OUTSIDE a run;
     *      a purpose is stamped once at creation, so one read per root per process is exact. A run keeps its own
     *      ({@see AgentRun::$purposeNameByRootConversationId}).
     */
    protected static array $purposeNameByRootConversationId = [];

    /**
     * The purpose that governs a conversation's TOOL SURFACE: its own purpose when it is a root, else the purpose of
     * the root of its delegation tree (a child carries no purpose of its own; the root's `blockedToolNames` bind the
     * whole tree — the same rule the executor's refusal applies). Null when neither carries one. Inside a run the root's
     * purpose is read once per run (`$agentRun`).
     */
    public function findPurposeOfConversationTree(\DDD\Domain\AI\Entities\Conversations\AIConversation $conversation, ?AgentRun $agentRun = null): ?AIConversationPurpose
    {
        $ownPurpose = $this->getPurposeOfConversation($conversation);
        if ($ownPurpose !== null) {
            return $ownPurpose;
        }
        $rootPurposeName = $this->findRootPurposeNameOfDelegationChild($conversation, $agentRun);
        return $rootPurposeName !== null ? $this->getPurposeByName($rootPurposeName) : null;
    }

    /**
     * The purpose name of the delegation tree's ROOT of a child conversation; null for a root, for a root without a
     * purpose and while the root cannot be read. A purpose is stamped once at creation, so a read answer is kept per
     * root — for the run inside a run (`$agentRun`), else for the process; a failed read is not kept (a transient
     * error must not pin "no purpose").
     */
    public function findRootPurposeNameOfDelegationChild(\DDD\Domain\AI\Entities\Conversations\AIConversation $conversation, ?AgentRun $agentRun = null): ?string
    {
        $rootConversationId = $conversation->rootConversationId ?? null;
        if ($rootConversationId === null || (int)$rootConversationId === (int)($conversation->id ?? 0)) {
            return null;
        }
        $rootConversationId = (int)$rootConversationId;
        $purposeNameByRootConversationId = $agentRun !== null ? $agentRun->purposeNameByRootConversationId : self::$purposeNameByRootConversationId;
        if (array_key_exists($rootConversationId, $purposeNameByRootConversationId)) {
            return $purposeNameByRootConversationId[$rootConversationId];
        }
        try {
            $rootConversation = \DDD\Domain\AI\Entities\Conversations\AIConversation::getService()->find($rootConversationId);
            $rootPurposeName = isset($rootConversation->purposeName) ? $rootConversation->purposeName : null;
        } catch (\Throwable) {
            return null;
        }
        $rootPurposeName = $rootPurposeName ?: null;
        if ($agentRun !== null) {
            $agentRun->purposeNameByRootConversationId[$rootConversationId] = $rootPurposeName;
        } else {
            self::$purposeNameByRootConversationId[$rootConversationId] = $rootPurposeName;
        }
        return $rootPurposeName;
    }

    public function getPurposeByName(string $purposeName): ?AIConversationPurpose
    {
        $raw = Config::get(self::CONFIG_PURPOSES_PREFIX . $purposeName);
        if (!is_string($raw) || $raw === '') {
            if ($this->throwErrors) {
                throw new NotFoundException("AIConversationPurpose not found: $purposeName");
            }
            return null;
        }

        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $purposeFrontmatterAndBody = AISkillsService::splitFrontmatter($aISkillsService->substitutePersonaPlaceholders($raw));
        $frontmatter = $purposeFrontmatterAndBody->frontmatter;
        $body = $purposeFrontmatterAndBody->body;
        // The purpose class is read from THIS file's own frontmatter, before the inheritance merge below: a child
        // never runs with its parent's class (`Intake.Call` names its own class, not the class of `Intake.Chat`).
        $purposeEntityClass = $this->resolvePurposeEntityClassFromOwnFrontmatter($purposeName, $frontmatter);
        if ($purposeEntityClass === null) {
            return null;
        }
        // INHERITANCE: `inheritsFrom: <purpose>` makes this config a thin OVERRIDE of another
        // purpose's config — every frontmatter key the child does not set comes from the parent (the whole seeded
        // plan, the rail, the supervision knobs …), the child's body replaces the parent's only when non-empty. The
        // intake by phone inherits the intake by chat this way, so the plan and its acts exist exactly once. One
        // level deep on purpose: a parent that inherits itself is not resolved further.
        $inheritsFromPurposeName = trim((string)($frontmatter['inheritsFrom'] ?? ''));
        $resolvedInheritsFromPurposeName = null;
        if ($inheritsFromPurposeName !== '' && $inheritsFromPurposeName !== $purposeName) {
            $parentRaw = Config::get(self::CONFIG_PURPOSES_PREFIX . $inheritsFromPurposeName);
            if (is_string($parentRaw) && $parentRaw !== '') {
                $parentFrontmatterAndBody = AISkillsService::splitFrontmatter($aISkillsService->substitutePersonaPlaceholders($parentRaw));
                $parentFrontmatter = $parentFrontmatterAndBody->frontmatter;
                $parentBody = $parentFrontmatterAndBody->body;
                $resolvedInheritsFromPurposeName = $inheritsFromPurposeName;
                unset($frontmatter['inheritsFrom'], $parentFrontmatter[self::FRONTMATTER_KEY_PURPOSE_CLASS]);
                $frontmatter = array_merge($parentFrontmatter, $frontmatter);
                if (trim($body) === '') {
                    $body = $parentBody;
                }
            }
        }

        $purposeRequirements = $this->getPurposeRequirementsByPurposeName()[$purposeName] ?? null;
        if (is_array($purposeRequirements) && $purposeRequirements !== []) {
            $frontmatter = AISkillsService::mergeRequirementOverlay($frontmatter, $purposeRequirements, ['requiredFeatureFlags'], []);
        }

        /** @var AIConversationPurpose $aIConversationPurpose */
        $aIConversationPurpose = new $purposeEntityClass();
        $aIConversationPurpose->name = $purposeName;
        $aIConversationPurpose->inheritsFromPurposeName = $resolvedInheritsFromPurposeName;
        $aIConversationPurpose->description = (string)($frontmatter['description'] ?? '');
        $aIConversationPurpose->directive = trim($body);
        $aIConversationPurpose->preloadSkillNames = array_values(array_map('strval', (array)($frontmatter['preloadSkillNames'] ?? [])));
        $aIConversationPurpose->pinPreloadedSkills = (bool)($frontmatter['pinPreloadedSkills'] ?? true);
        // HIERARCHICAL seed: items are plain strings OR maps with text/doneCriterion/subtasks — pass the parsed
        // YAML through verbatim; TaskPlanItems::fromPurposeSeedDefinition() builds and validates the tree.
        $aIConversationPurpose->seedTaskPlanItems = array_values((array)($frontmatter['seedTaskPlanItems'] ?? []));
        // Stepper declaration: ordered key+customerLabel maps — CustomerProgressSteps validates each entry.
        $aIConversationPurpose->customerProgressSteps = array_values((array)($frontmatter['customerProgressSteps'] ?? []));
        $aIConversationPurpose->commonPitfalls = array_values(array_map('strval', (array)($frontmatter['commonPitfalls'] ?? [])));
        $aIConversationPurpose->allowedLoadSkillNames = array_values(array_map('strval', (array)($frontmatter['allowedLoadSkillNames'] ?? [])));
        $kickoffSignalText = $frontmatter['kickoffSignalText'] ?? null;
        $aIConversationPurpose->kickoffSignalText = ($kickoffSignalText === null || $kickoffSignalText === '')
            ? null
            : (string)$kickoffSignalText;
        $aIConversationPurpose->requiredFeatureFlags = array_values(array_map('strval', (array)($frontmatter['requiredFeatureFlags'] ?? [])));
        $aIConversationPurpose->worksAutonomouslyAfterKickoff = (bool)($frontmatter['worksAutonomouslyAfterKickoff'] ?? false);
        $aIConversationPurpose->usesSynthesizedOwnerText = (bool)($frontmatter['usesSynthesizedOwnerText'] ?? false);
        $aIConversationPurpose->loadsEarlierSessionsItself = (bool)($frontmatter['loadsEarlierSessionsItself'] ?? false);
        $aIConversationPurpose->readsLocalSituation = (bool)($frontmatter['readsLocalSituation'] ?? true);
        $aIConversationPurpose->maxStepsPerTurn = isset($frontmatter['maxStepsPerTurn'])
            ? (int)$frontmatter['maxStepsPerTurn']
            : null;
        $aIConversationPurpose->blockedToolNames = array_map('strval', (array)($frontmatter['blockedToolNames'] ?? []));
        $blockedToolsRefusalText = trim((string)($frontmatter['blockedToolsRefusalText'] ?? ''));
        $aIConversationPurpose->blockedToolsRefusalText = $blockedToolsRefusalText !== '' ? $blockedToolsRefusalText : null;
        $aIConversationPurpose->maxTurnCostUsd = isset($frontmatter['maxTurnCostUsd'])
            ? (float)$frontmatter['maxTurnCostUsd']
            : null;
        // the purpose's own analysis prompt and model (null = the defaults of conversationAnalysis.php)
        $analysisPromptName = trim((string)($frontmatter['analysisPromptName'] ?? ''));
        $aIConversationPurpose->analysisPromptName = $analysisPromptName !== '' ? $analysisPromptName : null;
        $analysisModelName = trim((string)($frontmatter['analysisModelName'] ?? ''));
        $aIConversationPurpose->analysisModelName = $analysisModelName !== '' ? $analysisModelName : null;
        $preferredModelName = $frontmatter['preferredModelName'] ?? null;
        $aIConversationPurpose->preferredModelName = (is_string($preferredModelName) && $preferredModelName !== '')
            ? $preferredModelName
            : null;
        // the purpose-level effort floor — canonical `low|medium|high`, anything else = no minimum
        $minReasoningEffort = strtolower(trim((string)($frontmatter['minReasoningEffort'] ?? '')));
        $aIConversationPurpose->minReasoningEffort = in_array($minReasoningEffort, ['low', 'medium', 'high'], true) ? $minReasoningEffort : null;
        // the purpose-level BASE effort — what a call is sent with when nothing raises it
        $reasoningEffort = strtolower(trim((string)($frontmatter['reasoningEffort'] ?? '')));
        $aIConversationPurpose->reasoningEffort = in_array($reasoningEffort, ['low', 'medium', 'high'], true) ? $reasoningEffort : null;
        $aIConversationPurpose->doneCriteria = (string)($frontmatter['doneCriteria'] ?? '');
        $aIConversationPurpose->startableViaApi = (bool)($frontmatter['startableViaApi'] ?? true);
        $aIConversationPurpose->completedConversationStaysSingleton = (bool)($frontmatter['completedConversationStaysSingleton'] ?? false);
        $aIConversationPurpose->supervisorCadence = isset($frontmatter['supervisorCadence'])
            ? (int)$frontmatter['supervisorCadence']
            : null;
        $aIConversationPurpose->supervisorRunCap = (int)($frontmatter['supervisorRunCap'] ?? 8);
        $aIConversationPurpose->fastMode = (bool)($frontmatter['fastMode'] ?? false);
        // The purpose's WORKING-CONTEXT budget: a positive absolute token count, else null (= the
        // model/global compaction threshold). A zero/negative value is a config mistake and reads as "not set".
        $contextBudgetTokens = isset($frontmatter['contextBudgetTokens']) ? (int)$frontmatter['contextBudgetTokens'] : null;
        $aIConversationPurpose->contextBudgetTokens = ($contextBudgetTokens !== null && $contextBudgetTokens > 0) ? $contextBudgetTokens : null;

        // Optional per-purpose context-retention override (a `contextRetention:` map in the frontmatter). Only the
        // recognized scalar knobs are read; everything omitted stays null → the global eviction default applies.
        $contextRetention = $frontmatter['contextRetention'] ?? null;
        if (is_array($contextRetention)) {
            $contextRetentionSettings = new ContextRetentionSettings();
            // `rebuildEpochOnSituationChange` is a bool knob like these
            foreach (['enabled', 'loadToolsOnSkillLoad', 'hintRecentToolCallsToDiscourageRepeats', 'storeRequestSnapshot', 'rebuildEpochOnSituationChange'] as $boolKey) {
                if (array_key_exists($boolKey, $contextRetention)) {
                    $contextRetentionSettings->{$boolKey} = (bool)$contextRetention[$boolKey];
                }
            }
            foreach ([
                'keepToolResultsRecentTurns', 'toolResultTokenBudget', 'unloadToolAfterUnusedTokens',
                'unloadToolAfterUnusedTurns', 'unloadSkillAfterUnusedTokens', 'unloadSkillAfterUnusedTurns',
                'referenceResidentTokens', 'pressureBudgetTokens', 'hardCapTokens', 'minResidencyTurns',
                'maxToolCallImagesOnWire',
            ] as $intKey) {
                if (array_key_exists($intKey, $contextRetention)) {
                    $contextRetentionSettings->{$intKey} = (int)$contextRetention[$intKey];
                }
            }
            // the unloading unit (`skill` | `tool`) — the one string knob; an unknown value = inherit.
            if (array_key_exists('unloadGranularity', $contextRetention)) {
                $contextRetentionSettings->unloadGranularity = ContextRetentionSettings::canonicalUnloadGranularity(
                    is_string($contextRetention['unloadGranularity']) ? $contextRetention['unloadGranularity'] : null
                );
            }
            // the skill-loading strategy (`systemPromptSwap` | `conversationAppend`); unknown = inherit.
            if (array_key_exists('skillLoadingStrategy', $contextRetention)) {
                $contextRetentionSettings->skillLoadingStrategy = ContextRetentionSettings::canonicalSkillLoadingStrategy(
                    is_string($contextRetention['skillLoadingStrategy']) ? $contextRetention['skillLoadingStrategy'] : null
                );
            }
            // the state-change delivery (`everyCall` | `onceWhenChanged`); unknown = inherit.
            if (array_key_exists('stateChangeDelivery', $contextRetention)) {
                $contextRetentionSettings->stateChangeDelivery = ContextRetentionSettings::canonicalStateChangeDelivery(
                    is_string($contextRetention['stateChangeDelivery']) ? $contextRetention['stateChangeDelivery'] : null
                );
            }
            $aIConversationPurpose->contextRetentionSettings = $contextRetentionSettings;
        }
        // the run settings a NEW conversation of this purpose starts with (a `runSettings:` map)
        $runSettingsMap = $frontmatter['runSettings'] ?? null;
        $aIConversationPurpose->runSettings = is_array($runSettingsMap) ? $this->buildPurposeRunSettingsFromFrontmatter($runSettingsMap) : null;
        // asked last, so the purpose class reads a purpose whose fields are all set
        $aIConversationPurpose->blockedToolNames = $aIConversationPurpose->buildBlockedToolNamesWithTheDeclaredOnes();

        return $aIConversationPurpose;
    }

    /**
     * The purpose requirement overlay (`AI.purposeRequirements`), read once per process — the application's file
     * replaces a module's file whole; no file = no overlay.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPurposeRequirementsByPurposeName(): array
    {
        if (self::$purposeRequirementsByPurposeName === null) {
            $configuredPurposeRequirements = Config::get(self::CONFIG_PURPOSE_REQUIREMENTS);
            self::$purposeRequirementsByPurposeName = is_array($configuredPurposeRequirements) ? $configuredPurposeRequirements : [];
        }
        return self::$purposeRequirementsByPurposeName;
    }

    /**
     * The run settings of a purpose's `runSettings:` frontmatter map: `agentWireApi`
     * (`chatCompletions` | `responses`), `previousReasoningCarryOver` (`all` | `currentTurn` | `capped` | `off`),
     * `previousReasoningCapTokens` (a positive count) and `fixedReasoningEffort` (`low` | `medium` | `high`). An unknown
     * key or value is ignored (= inherit); a map that names no valid value — `runSettings: {}`, the way an inheriting
     * purpose drops its parent's map — answers null.
     *
     * @param array<string, mixed> $runSettingsMap
     */
    public function buildPurposeRunSettingsFromFrontmatter(array $runSettingsMap): ?RunSettings
    {
        $purposeRunSettings = RunSettings::newInstance();
        $agentWireApi = is_string($runSettingsMap['agentWireApi'] ?? null) ? RunSettings::canonicalAgentWireApi($runSettingsMap['agentWireApi']) : null;
        // the server-state arm is a test arm, never a purpose default
        $purposeRunSettings->agentWireApi = in_array($agentWireApi, [AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, AIModel::AGENT_WIRE_API_RESPONSES], true) ? $agentWireApi : null;
        $previousReasoningCarryOver = is_string($runSettingsMap['previousReasoningCarryOver'] ?? null)
            ? RunSettings::canonicalPreviousReasoningCarryOver($runSettingsMap['previousReasoningCarryOver'])
            : null;
        $purposeRunSettings->previousReasoningCarryOver = $previousReasoningCarryOver !== RunSettings::PREVIOUS_REASONING_CARRY_OVER_INHERIT ? $previousReasoningCarryOver : null;
        $previousReasoningCapTokens = is_int($runSettingsMap['previousReasoningCapTokens'] ?? null) ? $runSettingsMap['previousReasoningCapTokens'] : null;
        $purposeRunSettings->previousReasoningCapTokens = $previousReasoningCapTokens !== null && $previousReasoningCapTokens > 0 ? $previousReasoningCapTokens : null;
        $fixedReasoningEffort = strtolower(trim((string)($runSettingsMap['fixedReasoningEffort'] ?? '')));
        $purposeRunSettings->fixedReasoningEffort = in_array($fixedReasoningEffort, [RunSettings::DECISION_MODEL_REASONING_EFFORT_LOW, RunSettings::DECISION_MODEL_REASONING_EFFORT_MEDIUM, RunSettings::DECISION_MODEL_REASONING_EFFORT_HIGH], true)
            ? $fixedReasoningEffort
            : null;
        if ($purposeRunSettings->agentWireApi === null && $purposeRunSettings->previousReasoningCarryOver === null
            && $purposeRunSettings->previousReasoningCapTokens === null && $purposeRunSettings->fixedReasoningEffort === null
        ) {
            return null;
        }
        return $purposeRunSettings;
    }

    /**
     * The entity class a purpose file loads as, from the file's OWN frontmatter — the caller passes it
     * before any `inheritsFrom` merge, so a parent's `purposeClass` never reaches a child.
     *
     * `purposeClass` is REQUIRED: it must be a non-empty string naming an existing class that extends
     * {@see AIConversationPurpose}. A missing or invalid value is a config error: thrown when {@see $throwErrors} is
     * set, otherwise logged as an ERROR and answered with null — the purpose does not load, the same as a missing
     * file. There is no fallback to the base class: a purpose without its class would run
     * without its criteria, close logic and milestone.
     *
     * @param array<string, mixed> $ownFrontmatter
     * @return class-string<AIConversationPurpose>|null
     * @throws InternalErrorException only when {@see $throwErrors} is set
     */
    public function resolvePurposeEntityClassFromOwnFrontmatter(string $purposeName, array $ownFrontmatter): ?string
    {
        $declaredPurposeClass = $ownFrontmatter[self::FRONTMATTER_KEY_PURPOSE_CLASS] ?? null;
        $declaredPurposeClassName = is_string($declaredPurposeClass) ? trim($declaredPurposeClass) : '';
        if ($declaredPurposeClassName !== ''
            && class_exists($declaredPurposeClassName)
            && is_a($declaredPurposeClassName, AIConversationPurpose::class, true)
        ) {
            return $declaredPurposeClassName;
        }
        $invalidPurposeClassMessage = array_key_exists(self::FRONTMATTER_KEY_PURPOSE_CLASS, $ownFrontmatter)
            ? "AIConversationPurpose '$purposeName' does not load: its purposeClass '"
                . (is_scalar($declaredPurposeClass) ? (string)$declaredPurposeClass : get_debug_type($declaredPurposeClass))
                . "' is not an existing class extending " . AIConversationPurpose::class
            : "AIConversationPurpose '$purposeName' does not load: its file names no purposeClass (required)";
        if ($this->throwErrors) {
            throw new InternalErrorException($invalidPurposeClassMessage);
        }
        DDDService::instance()->getLogger()->error($invalidPurposeClassMessage);
        return null;
    }
}
