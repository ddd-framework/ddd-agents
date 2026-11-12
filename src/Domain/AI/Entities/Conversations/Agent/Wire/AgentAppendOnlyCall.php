<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\CheckDelegationsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\GetTaskPlansTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\MessageSubAgentTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\WaitForSubAgentsTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Entities\Skills\AISkills;

/**
 * One model call of an append-only conversation: which of the offered tools stay in the epoch's fixed
 * top-level `tools` (the meta tools and the tools of the epoch's base set), and what the history carries beyond the
 * fixed prefix — the items every earlier call of the epoch appended (read back from the request snapshots of their
 * first output rows, rendered at the same positions with the same variants) and the item THIS call appends: the skills
 * loaded since (their instruction blocks), the tools that joined (the loaded skills' tools and the conditional meta
 * tools: they join once and stay), and a `# Situation` entry (the situation-dependent sections that
 * apply now, {@see AgentAppendedSituation} — the system content carries none of them). A cold state epoch
 * carries the conditional meta tools the old epoch appended in its top-level tools. Nothing is
 * unloaded inside an epoch. An epoch that carries the purpose's whole reachable tool set top-level
 * ({@see AgentPrefixEpoch::offersReachableToolsTopLevel()}) keeps every offered tool there and appends no tool at all — a
 * skill load appends its instructions only. Under `rebuildEpochOnSituationChange` the system
 * content carries the situation sections, so no `# Situation` entry is rendered or appended, a skill appended inside the
 * epoch carries its sections for the epoch's situation, and the skill texts of the rows before a situation epoch's start
 * are not rendered (their tools stay at their positions). Pure over the resolved inputs; the service gathers them
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService::buildAppendOnlyCall()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentAppendOnlyCall
{
    /**
     * The meta tools whose offer depends on a condition that arises during a conversation: they join the history as
     * an `additional_tools` item where the condition first holds and stay, so the
     * top-level `tools` never change for them.
     */
    public const array CONDITIONAL_META_TOOL_NAMES = [
        CheckDelegationsTool::NAME,
        MessageSubAgentTool::NAME,
        WaitForSubAgentsTool::NAME,
        GetTaskPlansTool::NAME,
        SendOwnerReportTool::NAME,
    ];

    /** Leads the instructions of the skills a call appended. */
    public const string HEADER_SKILLS_LOADED = '# Skills loaded now — their instructions follow; their tools were added to your tools';

    /**
     * Leads the instructions of the skills a call appended when the epoch carries the whole reachable tool set top-level:
     * the tools are listed already and callable from now on.
     */
    public const string HEADER_SKILLS_LOADED_TOOLS_LISTED = '# Skills loaded now — their instructions follow; their tools in your tool list can be called from now on';

    /** @var array<int, array<string, mixed>> The tools of the fixed top-level `tools`. */
    public array $topLevelToolSchemas = [];

    public AgentAppendedHistoryItems $appendedHistoryItems;

    /** @var string[] The skills THIS call appended (recorded on its request snapshot). */
    public array $appendedSkillNamesOfThisCall = [];

    /** @var string[] The tools THIS call appended (recorded on its request snapshot). */
    public array $appendedToolNamesOfThisCall = [];

    /** Whether THIS call appended a `# Situation` entry (recorded on its request snapshot). */
    public bool $appendedSituationChangeOfThisCall = false;

    /** @var string|null The kind of THIS call's `# Situation` entry ({@see AgentAppendedSituation::ENTRY_KIND_FULL} / `added`); null = none. */
    public ?string $appendedSituationKindOfThisCall = null;

    /** @var string[] The skills (and {@see AgentAppendedSituation::PURPOSE_DIRECTIVE_NAME}) THIS call's entry carries the sections of. */
    public array $appendedSituationNamesOfThisCall = [];

    /**
     * @param array<int, array<string, mixed>> $offeredToolSchemas the Chat-form tools this call offers (after the loop's per-step filters)
     * @param array<string, array<string, mixed>> $toolSchemasByName every schema an item may need (the offer before the filters), by name;
     *        the static schemas of the conditional meta tools are added here ({@see buildConditionalMetaToolSchemasByName()})
     * @param AISkills $resolvedSkills the skills the items may render (the loaded ones and the ones earlier calls appended)
     * @param bool $appendsForThisCall false = a point-in-time rebuild: only the recorded items of earlier calls
     */
    public static function buildFor(
        AgentModelInput $agentModelInput,
        array $offeredToolSchemas,
        array $toolSchemasByName,
        AISkills $resolvedSkills,
        bool $appendsForThisCall
    ): static {
        $agentAppendOnlyCall = new static();
        $agentAppendOnlyCall->appendedHistoryItems = new AgentAppendedHistoryItems();
        // a recorded item renders every conditional meta tool it recorded, also once the tool's condition has ended and
        // the offer no longer carries it — the item keeps its bytes; the dispatch refuses a call
        // outside the condition with a next move
        $toolSchemasByName += self::buildConditionalMetaToolSchemasByName();
        $prefixEpoch = $agentModelInput->prefixEpoch ?? new AgentPrefixEpoch();
        // the skills the system content renders (option b: the situation epoch's base set)
        $baseSkillNames = $prefixEpoch->getSystemContentSkillNames();
        $skillTextStartIndex = $prefixEpoch->getSkillTextStartIndex();
        $rendersSituationEntries = !$prefixEpoch->rebuildsOnSituationChange;
        $baseToolNames = $prefixEpoch->getBaseToolNames();
        $cumulativeResidentToolNames = $agentModelInput->loadedCapabilities?->getLoadedToolNames() ?? [];
        // an epoch that carries the whole reachable tool set top-level keeps every offered tool there —
        // the offer is the same for the whole epoch, and a load appends its instructions only
        $offersReachableToolsTopLevel = $prefixEpoch->offersReachableToolsTopLevel();

        // the fixed top-level tools: the meta tools (but the conditional ones) and the base set's tools
        $topLevelToolNames = [];
        $toolNamesOutsideTheTopLevel = [];
        // a later state epoch carries the conditional meta tools the old epoch appended in its
        // TOP-LEVEL tools — rows of the history still call them, and a call must never come before its declaration; they
        // close the list in a fixed order with their static schemas, offered now or not, so the top-level tools stay the
        // same bytes for the whole epoch
        $conditionalMetaToolSchemasByName = self::buildConditionalMetaToolSchemasByName();
        $carriedConditionalMetaToolNames = array_values(array_intersect($prefixEpoch->carriedConditionalMetaToolNames, array_keys($conditionalMetaToolSchemasByName)));
        foreach ($offeredToolSchemas as $offeredToolSchema) {
            $offeredToolName = (string)($offeredToolSchema['function']['name'] ?? '');
            if (in_array($offeredToolName, $carriedConditionalMetaToolNames, true)) {
                continue;
            }
            $staysTopLevel = $offersReachableToolsTopLevel
                || in_array($offeredToolName, $baseToolNames, true)
                || (!in_array($offeredToolName, $cumulativeResidentToolNames, true) && !in_array($offeredToolName, self::CONDITIONAL_META_TOOL_NAMES, true));
            if ($staysTopLevel) {
                $agentAppendOnlyCall->topLevelToolSchemas[] = $offeredToolSchema;
                $topLevelToolNames[] = $offeredToolName;
            } else {
                $toolNamesOutsideTheTopLevel[] = $offeredToolName;
            }
        }
        foreach ($carriedConditionalMetaToolNames as $carriedToolName) {
            $agentAppendOnlyCall->topLevelToolSchemas[] = $conditionalMetaToolSchemasByName[$carriedToolName];
            $topLevelToolNames[] = $carriedToolName;
        }

        // the items of the earlier calls of the epoch, at their positions, in history order — diffed against the epoch's
        // base set and the top-level tools: a compaction edge between a load row and the row that recorded the load puts
        // the skill into the base set (rendered in the system content, its tools top-level), and an item must never send
        // a skill text or a tool a second time
        $appendedSkillNames = [];
        $appendedToolNames = [];
        $queryOptionsSyntaxShown = AgentModelInput::skillsExposeQueryOptions($prefixEpoch->getSystemContentAISkills());
        $sharedParamsShown = AgentModelInput::skillsExposeSharedParams($prefixEpoch->getSystemContentAISkills());
        // the epoch's `# Situation` entries, read in history order (the system content carries none)
        $agentAppendedSituation = new AgentAppendedSituation();
        foreach ($agentModelInput->messages as $message) {
            $requestSnapshot = self::findRequestSnapshotOfEpochRow($message, $prefixEpoch);
            if ($requestSnapshot === null) {
                continue;
            }
            $callVariants = is_array($requestSnapshot->variants) ? AIConversationVariants::fromSnapshotArray($requestSnapshot->variants) : null;
            // option b: a row before the situation epoch's start keeps its tools item only — its skill texts and its
            // `# Situation` entry are superseded by the system content of the situation epoch
            $rendersSkillTextOfRow = ($message->indexInConversation ?? -1) >= $skillTextStartIndex;
            $recordedSkillNames = $rendersSkillTextOfRow ? array_values(array_diff($requestSnapshot->appendedSkillNames ?? [], $baseSkillNames, $appendedSkillNames)) : [];
            $recordedToolNames = array_values(array_diff($requestSnapshot->appendedToolNames ?? [], $baseToolNames, $topLevelToolNames, $appendedToolNames));
            // a recorded `# Situation` entry renders with the recording call's variants and names; a full entry names the
            // sections the earlier entries carried that it withdraws (the walk's state); an entry recorded before
            // entries carried names is not rendered
            $recordedSituationText = $rendersSituationEntries && $rendersSkillTextOfRow
                && $requestSnapshot->appendedSituationNames !== null && $requestSnapshot->appendedSituationKind !== null
                ? $agentAppendedSituation->renderEntryAndRecordIt($agentModelInput, $resolvedSkills, $requestSnapshot->appendedSituationKind, $requestSnapshot->appendedSituationNames, $callVariants)
                : '';
            $includesQueryOptionsSyntax = !$queryOptionsSyntaxShown && self::namedSkillsExposeQueryOptions($resolvedSkills, $recordedSkillNames);
            $includesSharedParams = !$sharedParamsShown && self::namedSkillsExposeSharedParams($resolvedSkills, $recordedSkillNames);
            $queryOptionsSyntaxShown = $queryOptionsSyntaxShown || $includesQueryOptionsSyntax;
            $sharedParamsShown = $sharedParamsShown || $includesSharedParams;
            $agentAppendedHistoryInsertion = self::buildInsertion(
                $agentModelInput,
                $resolvedSkills,
                $recordedSkillNames,
                $recordedToolNames,
                $toolSchemasByName,
                $recordedSituationText,
                $includesQueryOptionsSyntax,
                $includesSharedParams
            );
            if (!$agentAppendedHistoryInsertion->isEmpty()) {
                $agentAppendOnlyCall->appendedHistoryItems->addInsertionBeforeMessageIndex(
                    self::findInsertionMessageIndex($agentModelInput->messages, $requestSnapshot->appendedAfterMessageIndex, (int)$message->indexInConversation),
                    $agentAppendedHistoryInsertion
                );
            }
            $appendedSkillNames = array_values(array_unique(array_merge($appendedSkillNames, $recordedSkillNames)));
            $appendedToolNames = array_values(array_unique(array_merge($appendedToolNames, $recordedToolNames)));
        }
        $toolNamesToAppendNow = array_values(array_diff($toolNamesOutsideTheTopLevel, $appendedToolNames));
        if (!$appendsForThisCall) {
            return $agentAppendOnlyCall;
        }

        // this call's item: the skills loaded since the epoch start that no earlier call appended, the tools that
        // joined, a `# Situation` entry when the epoch has none yet, the situation changed, or a cut skill loaded
        $loadedSkillNames = $agentModelInput->loadedCapabilities?->getLoadedSkillNames() ?? [];
        $skillNamesToAppendNow = array_values(array_diff($loadedSkillNames, $baseSkillNames, $appendedSkillNames));
        $cutNames = AgentAppendedSituation::findCutNames($agentModelInput, $resolvedSkills, $loadedSkillNames);
        $situationEntryKind = $rendersSituationEntries ? $agentAppendedSituation->decideEntryKindFor($agentModelInput->variants, $cutNames) : null;
        $situationEntryNames = $situationEntryKind !== null ? $agentAppendedSituation->selectEntryNames($situationEntryKind, $cutNames) : [];
        $situationText = $situationEntryKind !== null
            ? $agentAppendedSituation->renderEntryAndRecordIt($agentModelInput, $resolvedSkills, $situationEntryKind, $situationEntryNames, $agentModelInput->variants)
            : '';
        $agentAppendedHistoryInsertion = self::buildInsertion(
            $agentModelInput,
            $resolvedSkills,
            $skillNamesToAppendNow,
            $toolNamesToAppendNow,
            $toolSchemasByName,
            $situationText,
            !$queryOptionsSyntaxShown && self::namedSkillsExposeQueryOptions($resolvedSkills, $skillNamesToAppendNow),
            !$sharedParamsShown && self::namedSkillsExposeSharedParams($resolvedSkills, $skillNamesToAppendNow)
        );
        if (!$agentAppendedHistoryInsertion->isEmpty()) {
            $agentAppendOnlyCall->appendedHistoryItems->insertionOfThisCall = $agentAppendedHistoryInsertion;
            $agentAppendOnlyCall->appendedSkillNamesOfThisCall = $skillNamesToAppendNow;
            $agentAppendOnlyCall->appendedToolNamesOfThisCall = $toolNamesToAppendNow;
            $agentAppendOnlyCall->appendedSituationChangeOfThisCall = $situationEntryKind !== null;
            $agentAppendOnlyCall->appendedSituationKindOfThisCall = $situationEntryKind;
            $agentAppendOnlyCall->appendedSituationNamesOfThisCall = $situationEntryNames;
        }
        return $agentAppendOnlyCall;
    }

    /**
     * The static schemas of {@see CONDITIONAL_META_TOOL_NAMES}, by name — the same bytes the offer sends while their
     * condition holds ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedTools::buildFor()}).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function buildConditionalMetaToolSchemasByName(): array
    {
        return [
            CheckDelegationsTool::NAME => CheckDelegationsTool::create()->toOpenAiToolSchema(),
            MessageSubAgentTool::NAME => MessageSubAgentTool::create()->toOpenAiToolSchema(),
            WaitForSubAgentsTool::NAME => WaitForSubAgentsTool::create()->toOpenAiToolSchema(),
            GetTaskPlansTool::NAME => GetTaskPlansTool::create()->toOpenAiToolSchema(),
            SendOwnerReportTool::NAME => SendOwnerReportTool::create()->toOpenAiToolSchema(),
        ];
    }

    /**
     * Where a recorded item sits in this call's history: right before the first row above the
     * row the recording call's item followed (`$appendedAfterMessageIndex`, the newest row that call saw) — the call
     * sent it at the end of its stable history, so a row written in between (the step's nudge or bounce, an owner row
     * that landed while it ran) never moves it. Without an anchor (an older snapshot) the item sits before the recording
     * row itself.
     *
     * @param AIConversationMessage[] $contextMessages
     */
    public static function findInsertionMessageIndex(array $contextMessages, ?int $appendedAfterMessageIndex, int $recordingRowIndex): int
    {
        if ($appendedAfterMessageIndex === null) {
            return $recordingRowIndex;
        }
        foreach ($contextMessages as $contextMessage) {
            $contextMessageIndex = $contextMessage->indexInConversation ?? null;
            if ($contextMessageIndex !== null && $contextMessageIndex > $appendedAfterMessageIndex) {
                return min((int)$contextMessageIndex, $recordingRowIndex);
            }
        }
        return $recordingRowIndex;
    }

    /** The request snapshot of a model call's first output row inside the epoch; null for any other row. */
    protected static function findRequestSnapshotOfEpochRow(AIConversationMessage $message, AgentPrefixEpoch $prefixEpoch): ?\DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot
    {
        if (($message->indexInConversation ?? -1) < $prefixEpoch->startIndex || ($message->inputTokens ?? 0) <= 0) {
            return null;
        }
        return isset($message->requestSnapshot) ? $message->requestSnapshot : null;
    }

    /**
     * One insertion: the joined tools, the instruction blocks of the named skills without their situation-dependent
     * sections (with the QueryOptions grammar and the shared parameters the first time a skill needs them), then a
     * rendered `# Situation` entry ({@see AgentAppendedSituation}).
     *
     * @param string[] $skillNames
     * @param string[] $toolNames
     * @param array<string, array<string, mixed>> $toolSchemasByName
     */
    protected static function buildInsertion(
        AgentModelInput $agentModelInput,
        AISkills $resolvedSkills,
        array $skillNames,
        array $toolNames,
        array $toolSchemasByName,
        string $situationText,
        bool $includesQueryOptionsSyntax,
        bool $includesSharedParams
    ): AgentAppendedHistoryInsertion {
        $agentAppendedHistoryInsertion = new AgentAppendedHistoryInsertion();
        foreach ($toolNames as $toolName) {
            if (isset($toolSchemasByName[$toolName])) {
                $agentAppendedHistoryInsertion->chatCompletionsToolSchemas[] = $toolSchemasByName[$toolName];
            }
        }
        $developerTextParts = [];
        if ($skillNames !== []) {
            $appendedSkills = $resolvedSkills->filterToNames($skillNames);
            $skillsHeader = ($agentModelInput->prefixEpoch?->offersReachableToolsTopLevel() ?? false) ? self::HEADER_SKILLS_LOADED_TOOLS_LISTED : self::HEADER_SKILLS_LOADED;
            // option b: the epoch's situation is fixed (a change starts a new epoch), so the text carries its sections
            $prefixEpochOfCall = $agentModelInput->prefixEpoch;
            $skillsText = $skillsHeader . "\nNow loaded: " . implode(', ', $skillNames) . "\n\n"
                . ($prefixEpochOfCall !== null && $prefixEpochOfCall->rebuildsOnSituationChange
                    ? $appendedSkills->getInstructions($agentModelInput->featureFlags, $agentModelInput->accountRights, $agentModelInput->offeredSkillCatalog, $prefixEpochOfCall->variantsAtEpochStart ?? $agentModelInput->variants)
                    : $appendedSkills->getInstructionsWithoutConditionalSections($agentModelInput->featureFlags, $agentModelInput->accountRights, $agentModelInput->offeredSkillCatalog));
            if ($includesQueryOptionsSyntax) {
                $skillsText .= "\n\n" . AgentModelInput::buildQueryOptionsSyntaxText();
            }
            if ($includesSharedParams) {
                $skillsText .= "\n\n" . AgentModelInput::buildSharedParamsText();
            }
            $developerTextParts[] = $skillsText;
        }
        if ($situationText !== '') {
            $developerTextParts[] = $situationText;
        }
        $agentAppendedHistoryInsertion->developerText = implode("\n\n", $developerTextParts);
        return $agentAppendedHistoryInsertion;
    }

    /**
     * Whether a skill of `$skillNames` exposes a QueryOptions parameter (its grammar is owed once per epoch).
     *
     * @param string[] $skillNames
     */
    protected static function namedSkillsExposeQueryOptions(AISkills $resolvedSkills, array $skillNames): bool
    {
        return $skillNames !== [] && AgentModelInput::skillsExposeQueryOptions($resolvedSkills->filterToNames($skillNames));
    }

    /**
     * Whether a skill of `$skillNames` exposes a shared request parameter (its documentation is owed once per epoch).
     *
     * @param string[] $skillNames
     */
    protected static function namedSkillsExposeSharedParams(AISkills $resolvedSkills, array $skillNames): bool
    {
        return $skillNames !== [] && AgentModelInput::skillsExposeSharedParams($resolvedSkills->filterToNames($skillNames));
    }
}
