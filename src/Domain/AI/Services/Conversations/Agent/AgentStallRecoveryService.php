<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Exceptions\AIModelCallFailedException;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStepToolChoice;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStepToolRestriction;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStallRecoveryOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStallClassification;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentLoopCorrectionState;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\WaitForSubAgentsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionCallOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentStallRecoveryDecision;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentStallRecoveryDecision;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\SubSkillToolDiscovery;

/**
 * The stall-recovery decision site (layer L2): at the second stall of one owner turn it
 * asks the decision model which move gets the model unstuck, over the moves PHP admits, and records the decision
 * (working-state slice, decision record, turn event). The loop acts on the returned move; what the moves mean is
 * {@see AgentStallRecoveryDecision}'s.
 */
class AgentStallRecoveryService extends Service
{
    /**
     * Max CONSECUTIVE stall-recovery signals the loop emits before giving up, for a stall = a turn that produced no
     * usable output (empty, OR prose right after `load_skills`) while work tools are available and none has run yet.
     * Recovery is SIGNAL-FIRST (an informative LOOP_NUDGE telling the model what it holds + what went wrong,
     * so it picks the right action); the counter resets to 0 on every productive turn, so a flaky model that
     * interleaves real progress with the occasional stall is NOT prematurely killed. As a LAST RESORT, on the final
     * allowed attempt (`$trailingNudges === MAX_STALL_SIGNALS - 1`), the loop falls back to ONE `tool_choice='required'`
     * re-drive — the lever for a model that keeps ignoring the signal (a pure-signal/disable approach stranded such
     * models, e.g. Gemini-3.5-Flash; OpenAI GPT-5.x typically recovers on the signal alone).
     */
    public const int MAX_STALL_SIGNALS = 3;

    use AITraceLogTrait;

    /**
     * The move to ACT on in mode `on` — null = today's ladder step (under `shadow`, on a fallback, on `none`, below the
     * confidence floor, for a delegation or an autonomous purpose, or when the decision failed in our code).
     *
     * @param string[] $residentWorkToolNames
     * @param string[] $loadableCapabilityNames
     * @param bool $conversationWorksAutonomously the purpose works on after its kickoff without an owner to unstick it
     * @return string|null
     * @throws AgentRunClaimLostException the run claim was lost while the decision was persisted
     */
    public function decideMove(
        AIConversation $conversation,
        string $stallShape,
        string $lastAssistantText,
        array $residentWorkToolNames,
        array $loadableCapabilityNames,
        int $stallCount,
        bool $conversationWorksAutonomously,
        ?string $runToken
    ): ?string {
        try {
            $decisionModelSettings = AgentDecisionModelSettings::current();
            $siteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_STALL_RECOVERY, $conversation);
            if ($siteMode === AgentDecisionModelSettings::MODE_OFF || $conversation->isDelegation() || $conversationWorksAutonomously) {
                return null;
            }
            $initiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex();
            $ownerMessage = (string)($conversation->messages->getNewestInitiatorMessage()?->message ?? '');
            $lastMessage = $conversation->messages->last();
            $loadedSkillNames = $lastMessage?->getLoadedCapabilities()?->getLoadedSkillNames() ?? [];
            $openWorkItems = $conversation->getTaskPlan()?->getOpenItemPathTexts() ?? [];
            $decision = AgentStallRecoveryDecision::build(
                $initiatorMessageIndex,
                $stallShape,
                $loadedSkillNames === [],
                $stallCount,
                $residentWorkToolNames !== [],
                $loadableCapabilityNames !== []
            );

            $argusStallRecoveryDecision = new ArgusAgentStallRecoveryDecision();
            $argusStallRecoveryDecision->stallShape = $stallShape;
            $argusStallRecoveryDecision->noSkillLoaded = $decision->noSkillLoaded;
            $argusStallRecoveryDecision->lastAssistantText = $lastAssistantText;
            $argusStallRecoveryDecision->ownerMessage = $ownerMessage;
            $argusStallRecoveryDecision->nextPlanItem = (string)($openWorkItems[0] ?? '');
            $argusStallRecoveryDecision->residentToolNames = $residentWorkToolNames;
            $argusStallRecoveryDecision->loadableCapabilityNames = $loadableCapabilityNames;
            $argusStallRecoveryDecision->offeredMoves = $decision->offeredMoves;
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            $argusStallRecoveryDecision->billingEntity = $agentSpacesService->findLoadedBillingEntityOfConversation($conversation);
            $argusStallRecoveryDecision->budgetAccount = isset($conversation->account) ? $conversation->account : null;
            $argusStallRecoveryDecision->setTimeoutSeconds($decisionModelSettings->timeoutSeconds);
            /** @var AgentDecisionCallsService $agentDecisionCallsService */
            $agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
            // its own boundary (mid-turn, needs the stalled output) — one child
            $decisionCallOutcome = $agentDecisionCallsService->runDecisionBatch(
                ArgusAgentDecisions::forChild($argusStallRecoveryDecision, 'stallRecovery'),
                $argusStallRecoveryDecision,
                $decisionModelSettings->timeoutSeconds,
                $decisionModelSettings->breakerSeconds,
                'stall-recovery'
            );
            $transportFailureReason = $decisionCallOutcome->failureReason;
            $decision->decidedBy = $decisionCallOutcome->resolveDecidedBy($argusStallRecoveryDecision->decided, $siteMode);
            $agentDecisionCallsService->closeCircuitBreakerAfter($decisionCallOutcome, $argusStallRecoveryDecision->decided);
            if ($decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN) {
                $argusStallRecoveryDecision = null;
            } elseif ($decision->isDecidedByTheModel()) {
                $decision->move = $argusStallRecoveryDecision->move;
                $decision->moveConfidence = $argusStallRecoveryDecision->moveConfidence;
                $decision->probabilities = $argusStallRecoveryDecision->probabilities;
                $decision->modelVersion = $argusStallRecoveryDecision->modelVersion;
            }
            $moveToApply = AgentStallRecoveryDecision::resolveMoveToApply($decision->move, $decision->moveConfidence, $decision->offeredMoves, $decision->probabilities);
            $decision->applied = $decision->isApplied() && $moveToApply !== null;

            $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
            $agentWorkingState->stallRecoveryDecision = $decision;
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'stallRecoveryDecision');
            $elapsedMs = $decisionCallOutcome->elapsedMs;
            $outcome = $decision->buildOutcome($siteMode, $moveToApply);
            $agentDecisionCallsService->recordDecision($conversation, $decision->buildRecord(
                AgentDecisionModelSettings::SITE_STALL_RECOVERY,
                $decision->applied,
                $elapsedMs,
                $transportFailureReason,
                $argusStallRecoveryDecision?->buildState(),
                $argusStallRecoveryDecision?->rawAnswers,
                $outcome,
                ArgusAgentStallRecoveryDecision::buildQuestions($decision->offeredMoves)
            ));
            /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
            $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
            $aIConversationTurnEventsService->recordEventOfConversation(
                $conversation,
                AIConversationTurnEvent::KIND_DECISION,
                AgentDecisionModelSettings::SITE_STALL_RECOVERY,
                $initiatorMessageIndex,
                null,
                null,
                $outcome + ['decidedBy' => $decision->decidedBy, 'transportFailureReason' => $transportFailureReason],
                $runToken,
                $elapsedMs
            );
            $this->aiTrace('turn.stallRecovery.decided', ['conversationId' => $conversation->id ?? null, 'decidedBy' => $decision->decidedBy] + $outcome);
            return $decision->applied ? $moveToApply : null;
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $decisionError) {
            $this->aiTrace('turn.stallRecovery.failed', ['conversationId' => $conversation->id ?? null, 'message' => $decisionError->getMessage()]);
            return null;
        }
    }

    /**
     * The informative stall-recovery SIGNAL text (LOOP_NUDGE): names the loaded skills + their
     * directly-callable tools + the load_tool path for the rest, states what went wrong (no output at all, OR prose
     * without using the just-loaded skill's tools), and asks the model to ACT — so it picks the RIGHT recovery (a
     * loaded tool / load_tool / a final answer) itself, instead of being mechanically forced. `$emittedNoText`
     * distinguishes the empty stall from the prose stall; `$stallCount` is the consecutive-stall ordinal for the
     * escalating tail line. Pure read over the last message's loaded capabilities.
     */
    public function buildStallSignalText(AgentRun $agentRun, AIConversation $conversation, bool $emittedNoText, int $stallCount, bool $waitForSubAgentsOffered = false): string
    {
        $lastMessage = $conversation->messages->last();
        $loadedCapabilities = $lastMessage?->getLoadedCapabilities();
        $loadedSkillNames = $loadedCapabilities?->getLoadedSkillNames() ?? [];
        // a tool the tree's purpose blocks is never named as callable
        $loadedToolNames = array_values(array_diff(
            $loadedCapabilities?->getLoadedToolNames() ?? [],
            $conversation->findBlockedToolNamesOfConversationTree($agentRun)
        ));

        $countTail = $stallCount > 1 ? ' (You have now produced no usable output ' . $stallCount . '× in a row.)' : '';

        // FOLD context (a settled delegation batch this turn): the verdicts are ALREADY in the `delegate` tool-result
        // above (re-attached at fold start). An empty turn here must be steered to ANSWER from them — NOT the generic
        // "call your loaded tools" below, which made a weak model re-run a sub-agent's tool. The data is here.
        // A turn sub-agent notifications opened — the results are in those notifications. Answer, continue
        // the work, or (offered while other sub-agents still run) end the turn without a message.
        if ($conversation->messages->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly()) {
            return 'Sub-agent notifications started this turn and their results are in the notifications above — you '
                . 'produced no usable output. Answer the owner from those results, or continue the work they unblock (never '
                . 're-run a tool a sub-agent already ran)'
                . ($waitForSubAgentsOffered
                    ? ', or — when nothing is new for the owner yet and other sub-agents still run — call wait_for_sub_agents to end the turn without a message.'
                    : '.')
                . $countTail;
        }
        if ($conversation->messages->buildCurrentTurn()->hasDelegationsSettledSignal()) {
            return 'Your delegated sub-agents have already finished and their verdicts are in the `delegate` tool-result '
                . 'above — you have everything you need. Write your final answer to the user NOW from those verdicts. Do '
                . 'NOT call any tool (the data is already here) and do NOT re-run anything a sub-agent already ran.' . $countTail;
        }

        // NO SKILL LOADED YET (e.g. a {preCallMessage} announcement emitted BEFORE any load_skills): the model already
        // knows what it wants — just tell it to ACT. The full tools inventory below would be wrong (it has none) and
        // is unneeded here.
        if ($loadedSkillNames === []) {
            return ($emittedNoText
                ? 'You produced no output. '
                : 'You announced what you would do but called no tool. ')
                . 'Call load_skills now to load the skill you need, then its tool.' . $countTail;
        }

        $skillsPhrase = implode(', ', $loadedSkillNames);
        $directToolsSentence = $loadedToolNames !== []
            ? 'You can call these tools directly right now: ' . implode(', ', $loadedToolNames) . '. '
            : '';
        $whatWentWrong = $emittedNoText
            ? 'But this turn you produced NO output at all — neither a tool call nor an answer.'
            : 'But this turn you loaded ' . $skillsPhrase . ' and then answered in prose WITHOUT using any of its tools.';

        // Name what load_tool can really load right now — the unloaded tools of the loaded skills and the tools of
        // their not-yet-loaded sub-skills; nothing is said about load_tool while it has nothing to offer.
        if (SubSkillToolDiscovery::listsSubSkillsUnderLoadedParent()) {
            // the hierarchy: what lies one level below the loaded skills and is not loaded
            $unloadedSubSkillNames = $conversation->buildOfferedSkillCatalog($lastMessage, [], $agentRun)->getUnloadedSubSkillNames($loadedSkillNames);
            $loadableToolsSentence = $unloadedSubSkillNames !== []
                ? 'Sub-skills of your loaded skills that are not loaded yet: '
                    . implode(', ', array_slice($unloadedSubSkillNames, 0, AIConversation::LOADABLE_TOOL_NAMES_BEFORE_SKILLS))
                    . ' — when the task needs a tool that is not among your tools, load the ONE whose purpose matches the'
                    . ' task with load_skills. '
                : '';
            // tools of the loaded skills that are not resident (lazy loading, unloading)
            $unloadedToolNames = array_values(array_diff(
                $conversation->buildLoadableToolNamesAtMessage($lastMessage, $agentRun),
                $conversation->findBlockedToolNamesOfConversationTree($agentRun)
            ));
            if ($unloadedToolNames !== []) {
                $loadableToolsSentence .= 'Tools of your loaded skills that are not loaded, one load_tool call away: '
                    . implode(', ', array_slice($unloadedToolNames, 0, 30)) . '. ';
            }
        } elseif (SubSkillToolDiscovery::offersToolsThroughLoadTool()) {
            $loadableToolNames = $conversation->buildLoadableToolNamesAtMessage($lastMessage, $agentRun);
            $loadableToolsSentence = $loadableToolNames !== []
                ? 'Not loaded yet, one load_tool call away: ' . implode(', ', array_slice($loadableToolNames, 0, 30)) . '. '
                : '';
        } else {
            $loadableToolsSentence = 'You can also load more of their tools with the load_tool tool. ';
        }

        return 'You have the skill(s) ' . $skillsPhrase . ' loaded. ' . $directToolsSentence
            . $loadableToolsSentence . $whatWentWrong
            . ' Call the tool you need to complete the task — or, ONLY if you already have enough information, give'
            . ' your final answer now. EXCEPTION: an IRREVERSIBLE action the owner has not agreed to in their own'
            . ' words — there, asking the owner that question in plain words IS the required output; ask it and wait,'
            . ' and never let this reminder push you into a destructive call without the owner\'s agreement.' . $countTail;
    }

    /**
     * What the model can still load at a stall ({@see AIConversation::buildLoadableCapabilityNames()}): the input of the
     * decision at the second stall and of the `load_required_capability` nudge.
     *
     * @param array<int, array<string, mixed>> $toolSchemas the step's offered tool schemas
     * @return string[]
     */
    public function buildLoadableCapabilityNames(AgentRun $agentRun, AIConversation $conversation, array $toolSchemas): array
    {
        return $conversation->buildLoadableCapabilityNames($agentRun, $toolSchemas);
    }

    /**
     * The nudge text of the `load_required_capability` move: names ONLY the loadable catalog — the
     * model's way out is a `load_tool` / `load_skills` call, nothing else.
     *
     * @param string[] $loadableCapabilityNames
     */
    public function buildStallSignalTextForCapabilityLoad(AIConversation $conversation, bool $emittedNoText, int $stallCount, array $loadableCapabilityNames): string
    {
        $countTail = $stallCount > 1 ? ' (You have now produced no usable output ' . $stallCount . '× in a row.)' : '';
        $catalogSentence = $loadableCapabilityNames !== []
            ? 'The capability you need is not loaded yet. You can load: ' . implode(', ', array_slice($loadableCapabilityNames, 0, 30)) . '. '
            : 'The capability you need is not loaded yet. ';
        return ($emittedNoText ? 'You produced no output. ' : 'You announced or described work but called no tool. ')
            . $catalogSentence
            . 'Call load_tool (for a tool of a loaded skill) or load_skills (for a skill) NOW, then use the loaded tool. '
            . 'Do not answer in prose until the work is done.' . $countTail;
    }

    /**
     * The nudge text of the DICTATED-BLOCK-MISSING stall ({@see AgentStallClassification}): the newest work result
     * dictates a block and the answer has none — the line and the block, nothing else; no tool call is asked for.
     */
    public function buildStallSignalTextForDictatedBlock(string $dictatingToolName): string
    {
        return 'The `' . $dictatingToolName . '` result dictates a block and your answer has none: one short line, then that block.';
    }

    /**
     * The nudge text of the DIRECT-WIDGET-MISSING stall ({@see AgentStallClassification}): the turn's decided direct
     * widget is missing from the answer — the line and the block, or the question to the owner when the answer needs
     * what only the owner can give; no other tool call is asked for.
     */
    public function buildStallSignalTextForDirectWidget(string $directWidgetType): string
    {
        return 'Your answer needs its `' . $directWidgetType . '` block: one short line, then the block — or, if you need something from the owner first, ask it with ' . AskOwnerTool::NAME . '.';
    }

    /**
     * The nudge text of the DIRECT-WIDGET-MISSING stall whose answer attempted the block but left it unclosed or
     * malformed ({@see AgentStallClassification::$directWidgetBlockIsUnclosedOrMalformed}): the block renders nothing.
     */
    public function buildStallSignalTextForUnclosedOrMalformedDirectWidget(string $directWidgetType): string
    {
        return 'Your `' . $directWidgetType . '` block is not closed or not valid JSON, so it shows nothing: send the answer again with one short line, then the complete block.';
    }

    /**
     * The nudge text of the CODE-OR-FORMULA-IN-PROSE stall ({@see AgentStallClassification}): a code fence or a LaTeX
     * formula in the prose — the kit's `Math` / `Code` node carries it.
     */
    public function buildStallSignalTextForCodeOrFormula(): string
    {
        return 'A formula or code sample is a Math / Code node in a ComposedView: send the answer again with it.';
    }

    /** At most this many finding lines go back with the WIDGET-TREE-FINDINGS signal; the rest are counted. */
    public const int MAX_WIDGET_TREE_FINDING_LINES = 8;

    /**
     * The text of the WIDGET-TREE-FINDINGS signal ({@see AgentStallClassification}): what the reader dropped from the
     * answer's `ComposedView` tree (path and message, capped at {@see MAX_WIDGET_TREE_FINDING_LINES}) and the request
     * for the corrected block in the next response.
     *
     * @param string[] $findingLines
     */
    public function buildWidgetTreeFindingsSignalText(array $findingLines): string
    {
        $shownFindingLines = array_slice($findingLines, 0, self::MAX_WIDGET_TREE_FINDING_LINES);
        $moreFindingsCount = count($findingLines) - count($shownFindingLines);
        return 'Your ComposedView block was not sent: the reader dropped these parts of the tree.' . "\n"
            . implode("\n", array_map(static fn (string $findingLine): string => '- ' . $findingLine, $shownFindingLines))
            . ($moreFindingsCount > 0 ? "\n- … and " . $moreFindingsCount . ' more' : '') . "\n"
            . 'Write the answer again in your next response with the corrected block: one short line, then the block. The same '
            . 'tree again is sent with these parts missing — change or remove each part listed.';
    }

    /**
     * The nudge text of the DICTATED-BLOCK-MISSING stall after a `delegate` call that started nothing
     * ({@see AgentStallClassification::$followsADelegateThatStartedNothing}): the widget is the model's own to write.
     */
    public function buildStallSignalTextForDelegateThatStartedNothing(): string
    {
        return 'The delegation did not start. Answer now: one short line, then the widget block.';
    }

    /**
     * The nudge text of the `end_the_turn_with_text` move: answer now from what is at hand, or ask the
     * owner for exactly the missing input or consent — no tool call is expected.
     */
    public function buildStallSignalTextForAnswerNow(bool $emittedNoText, int $stallCount): string
    {
        $countTail = $stallCount > 1 ? ' (You have now produced no usable output ' . $stallCount . '× in a row.)' : '';
        return ($emittedNoText ? 'You produced no output. ' : 'You announced work but called no tool. ')
            . 'No tool call is needed now. Write your answer to the owner NOW from what you already have — or, if something '
            . 'only the owner can give you (an input, a choice, a confirmation) is missing, ask for exactly that and nothing '
            . 'else. Do not announce work; either deliver or ask.' . $countTail;
    }

    /**
     * Records the FIRST stall of a run of stalls in the conversation's decision log (site `stallRecovery`, decided by
     * the code, applied false): the stall shape and the response text the nudge discards, raw (fenced blocks
     * included) and as visible prose. The second stall's decision-model call has its own record; without this one the
     * text of the first stall was nowhere. Best-effort like every decision record.
     *
     * @throws AgentRunClaimLostException the run claim was lost while the record was persisted
     */
    public function recordDiscardedTextOfFirstStall(AIConversation $conversation, string $stallShape, ModelResponse $modelResponse): void
    {
        $agentDecisionRecord = AgentStallRecoveryDecision::buildFirstStallRecord(
            $conversation->messages->getNewestInitiatorMessageIndex(),
            $stallShape,
            (string)($modelResponse->text ?? ''),
            $modelResponse->getVisibleProse(),
            $modelResponse->modelName ?? null
        );
        /** @var AgentDecisionCallsService $agentDecisionCallsService */
        $agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
        $agentDecisionCallsService->recordDecision($conversation, $agentDecisionRecord);
    }

    /**
     * The stall recovery of one loop step (signal-first, universal — moved from the agent
     * loop): at the SECOND consecutive stall of the turn the decision model picks one move; on the final
     * allowed attempt (or on the decision's work-tool move) ONE `tool_choice='required'` re-drive over the work tools runs;
     * while the nudge budget lasts the informative LOOP_NUDGE signal is appended with the step's usage. The answer tells the
     * loop which response the step goes on with and whether it continues with its next step. `$offeredToolsBeforeStepFilters`:
     * the step's whole offer before the loop's filters — what the re-drive keeps on the wire under append-only loading
     * ({@see AgentStepToolRestriction}); null = `$offeredTools`.
     *
     * @throws AIModelCallFailedException the forced re-drive returned a provider error
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function recoverStalledStep(
        AgentRun $agentRun,
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        AgentLoopCorrectionState $loopCorrectionState,
        AgentStallClassification $stallClassification,
        ModelResponse $modelResponse,
        AgentOfferedTools $offeredTools,
        ?string $modelNameOverride,
        string $baseSystemPrompt,
        ?string $runToken,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal,
        ?AgentOfferedTools $offeredToolsBeforeStepFilters = null
    ): AgentStallRecoveryOutcome {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        /** @var AIConversationModelCallsService $aIConversationModelCallsService */
        $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
        /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
        $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        // the loop's step facts, read as the loop read them right before its stall classification
        $modelName = $modelResponse->modelName;
        $toolCalls = $modelResponse->toolCalls;
        $noToolCall = $toolCalls === null || $toolCalls->isEmpty();
        $visibleProse = $modelResponse->getVisibleProse();
        $trailingNudges = $conversation->messages->countTrailingConsecutiveNudges();
        $workTools = $offeredTools->withoutTools(AgentMetaTools::NOT_WORK_FOR_THE_FORCED_STEP_NAMES);
        // at the SECOND consecutive stall of the turn (the first got the informative nudge,
        // which GPT-5.x usually recovers on) the decision model picks ONE move over the moves that apply.
        // Never on a turn that reads its sub-agents' results (its nudge names the way out: answer, continue,
        // or wait), once per owner turn. Mode `on` acts on it; `shadow` records it and the ladder runs as
        // today. Every non-verdict = today's ladder step. A turn sub-agent notifications
        // opened counts like the legacy DELEGATIONS_SETTLED turn.
        $isFoldStall = $conversation->messages->buildCurrentTurn()->readsSubAgentResults();
        $stallShape = (string)$stallClassification->getStallShape();
        // the answer misses only the dictated block: its own nudge, never the decision or a forced tool call
        $isDictatedBlockMissingStall = $stallShape === AgentStallRecoveryDecision::SHAPE_DICTATED_BLOCK_MISSING;
        // the answer misses only the turn's direct widget: the same — its own nudge, no decision, no forced call
        $isDirectWidgetMissingStall = $stallShape === AgentStallRecoveryDecision::SHAPE_DIRECT_WIDGET_MISSING;
        // the answer's tree lost parts: the findings go back as their own signal, no decision, no forced call
        $isWidgetTreeFindingsStall = $stallShape === AgentStallRecoveryDecision::SHAPE_WIDGET_TREE_FINDINGS;
        // a code fence or a formula in the prose: its own nudge, no decision, no forced call
        $isCodeOrFormulaInProseStall = $stallShape === AgentStallRecoveryDecision::SHAPE_CODE_OR_FORMULA_IN_PROSE;
        $isAnswerShapeStall = $isDictatedBlockMissingStall || $isDirectWidgetMissingStall || $isWidgetTreeFindingsStall || $isCodeOrFormulaInProseStall;
        if ($trailingNudges === 0) {
            // the response the nudge discards is otherwise gone: an EMPTY stall's widget block, a prose stall's text
            $this->recordDiscardedTextOfFirstStall($conversation, $stallShape, $modelResponse);
        }
        $stallRecoveryMove = null;
        if ($trailingNudges >= 1 && !$isFoldStall && !$isAnswerShapeStall && !$loopCorrectionState->stallRecoveryJudgedThisTurn) {
            $loopCorrectionState->stallRecoveryJudgedThisTurn = true;
            $stallRecoveryMove = $this->decideMove(
                $conversation,
                $stallShape,
                $visibleProse,
                $workTools->getToolNames(),
                $this->buildLoadableCapabilityNames($agentRun, $conversation, $offeredTools->toOpenAiToolSchemas()),
                $trailingNudges + 1,
                !$conversation->isDelegation() && $aIConversationPurposeRunsService->conversationPurposeWorksAutonomously($conversation), // a delegation never asks
                $runToken
            );
        }
        $decisionModelForcesWorkToolCall = $stallRecoveryMove === AgentStallRecoveryDecision::MOVE_CALL_A_WORK_TOOL && !$workTools->isEmpty();
        // LAST RESORT (only on the final allowed attempt, when the informative signals below have not worked,
        // and only when WORK tools exist to force over): ONE `tool_choice='required'` re-drive over the work
        // tools — the lever for a model that keeps ignoring the signal. A successful forced call is now
        // PRODUCTIVE → it falls through to appendTurn; only if it STILL stalled do we emit the final signal.
        // (A FOLD stall is NOT force-driven: forcing `none`/text was measured INEFFECTIVE — Gemini-3.5-Flash
        // returns empty even under tool_choice='none' — and forcing `required` would re-run a sub-agent's tool;
        // the fold-aware nudge in AgentStallRecoveryService::buildStallSignalText steers it to answer from the already-present verdicts.)
        // a decision-model-forced re-drive at the second stall REPLACES the ladder's last resort for the
        // rest of the turn (`$loopCorrectionState->stallLadderLastResortReplaced`) — one forced call per turn, as today.
        $ladderForcesWorkToolCall = $trailingNudges === self::MAX_STALL_SIGNALS - 1 && !$workTools->isEmpty()
            && !$isFoldStall && !$isAnswerShapeStall && !$loopCorrectionState->stallLadderLastResortReplaced;
        if ($decisionModelForcesWorkToolCall || $ladderForcesWorkToolCall) {
            $forcedByDecisionModel = $decisionModelForcesWorkToolCall;
            if ($forcedByDecisionModel) {
                $loopCorrectionState->stallLadderLastResortReplaced = true;
            }
            $forcedStart = hrtime(true);
            // the stalled call's own spend would be overwritten by the forced call's on the nudge row —
            // carry it on the forced-re-drive event so both calls are counted in the statistics
            $stalledCallInputTokens = $modelResponse->inputTokens ?? null;
            $stalledCallOutputTokens = $modelResponse->outputTokens ?? null;
            $stalledCallCostInUSD = $modelResponse->costInUSD ?? null;
            // the forced set never carries `load_tool` — a forced capability load is not work
            $forcedTools = $workTools->withoutTools(AgentMetaTools::NOT_IN_THE_FORCED_REDRIVE_NAMES);
            // under append-only loading the forced set stays off the wire — the whole offer is
            // sent, the dispatch refuses the tools the re-drive holds back (the system-prompt swap sends the forced set)
            $forcedStepToolRestriction = AgentStepToolRestriction::forStep(
                $agentRun->agentWireConfiguration?->usesConversationAppend() === true,
                $offeredToolsBeforeStepFilters ?? $offeredTools,
                $forcedTools->isEmpty() ? $workTools : $forcedTools,
                AgentStepToolChoice::required(AgentStepToolChoice::REASON_STALL_REDRIVE)->toWireValue()
            );
            $agentRun->stepToolRestriction = $forcedStepToolRestriction->restrictsTools() ? $forcedStepToolRestriction : null;
            $modelResponse = $aIConversationModelCallsService->performModelCallOfStep($conversation, $forcedStepToolRestriction->toolSchemasToSend, $forcedStepToolRestriction->toolChoiceToSend, $modelNameOverride, $baseSystemPrompt, $agentRun);
            $consoleStream?->writeTiming('model call (step ' . ($agentRun->invocationStep + 1) . ', forced tool — ' . ($forcedByDecisionModel ? 'stall decision' : 'last resort') . ')', (hrtime(true) - $forcedStart) / 1e9);
            $aIConversationOwnerReportDeliveryService->failFastOnProviderError($agentRun, $modelResponse, $conversation, $transientMessageIds, $modelResponse->modelName ?? $modelName, $consoleStream, $emitRealtimeSignal);
            $toolCalls = $modelResponse->toolCalls;
            $modelName = $modelResponse->modelName ?? $modelName;
            $noToolCall = $toolCalls === null || $toolCalls->isEmpty();
            // every forced attempt, successful or not — the nudge row keeps `LOOP_NUDGE`.
            $aIConversationTurnEventsService->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_FORCED_RE_DRIVE, $forcedByDecisionModel ? AgentDecisionModelSettings::SITE_STALL_RECOVERY : null, $conversation->messages->getNewestInitiatorMessageIndex(), 'step:' . ($agentRun->invocationStep + 1) . ':' . ($runToken ?? 'sync'), null, [
                'succeeded' => !$noToolCall,
                'forcedBy' => $forcedByDecisionModel ? 'stallDecision' : 'ladder',
                'modelName' => $modelName,
                'toolCallCount' => $toolCalls?->count() ?? 0,
                'inputTokens' => $modelResponse->inputTokens ?? null,
                'outputTokens' => $modelResponse->outputTokens ?? null,
                'stalledCallInputTokens' => $stalledCallInputTokens,
                'stalledCallOutputTokens' => $stalledCallOutputTokens,
                'stalledCallCostInUSD' => $stalledCallCostInUSD,
            ], $runToken);
        }

        // PRIMARY recovery — the informative SIGNAL (LOOP_NUDGE, the universal out-of-band channel):
        // tell the model exactly what it holds (loaded skills + their directly-available tools + the load_tool
        // catalog for the rest) and what went wrong, then let IT choose the right action. Bounded by the
        // consecutive trailing-nudge run; past the budget we fall through and the loop's give-up yields a
        // user-facing result. (A productive turn in between resets the run by construction.)
        if ($noToolCall && $trailingNudges < self::MAX_STALL_SIGNALS) {
            // an applied move IS the nudge of this step — the text names only the move's way out;
            // the row keeps `LOOP_NUDGE`, so the counters and the resume classifier are byte-identical.
            $stallSignalText = $isCodeOrFormulaInProseStall ? $this->buildStallSignalTextForCodeOrFormula() : ($isWidgetTreeFindingsStall ? $this->buildWidgetTreeFindingsSignalText($stallClassification->widgetTreeFindingLines) : ($isDirectWidgetMissingStall ? ($stallClassification->directWidgetBlockIsUnclosedOrMalformed
                ? $this->buildStallSignalTextForUnclosedOrMalformedDirectWidget((string)$stallClassification->directWidgetType)
                : $this->buildStallSignalTextForDirectWidget((string)$stallClassification->directWidgetType)) : ($isDictatedBlockMissingStall ? ($stallClassification->followsADelegateThatStartedNothing
                ? $this->buildStallSignalTextForDelegateThatStartedNothing()
                : $this->buildStallSignalTextForDictatedBlock((string)$stallClassification->dictatingToolName)) : match ($stallRecoveryMove) {
                AgentStallRecoveryDecision::MOVE_LOAD_REQUIRED_CAPABILITY => $this->buildStallSignalTextForCapabilityLoad($conversation, $stallClassification->emittedNoText, $trailingNudges + 1, $this->buildLoadableCapabilityNames($agentRun, $conversation, $offeredTools->toOpenAiToolSchemas())),
                AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT => $this->buildStallSignalTextForAnswerNow($stallClassification->emittedNoText, $trailingNudges + 1),
                default => $this->buildStallSignalText($agentRun, $conversation, $stallClassification->emittedNoText, $trailingNudges + 1, $offeredTools->offersTool(WaitForSubAgentsTool::NAME)),
            })));
            if ($stallRecoveryMove === AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT) {
                $loopCorrectionState->exemptProseStallOnce = true;
            }
            if ($isDictatedBlockMissingStall) {
                $loopCorrectionState->dictatedBlockNudgeSpentThisTurn = true;
            }
            if ($isDirectWidgetMissingStall) {
                $loopCorrectionState->directWidgetNudgeSpentThisTurn = true;
            }
            if ($isWidgetTreeFindingsStall) {
                $loopCorrectionState->widgetTreeFindingsSpentThisTurn = true;
            }
            if ($isCodeOrFormulaInProseStall) {
                $loopCorrectionState->codeOrFormulaNudgeSpentThisTurn = true;
            }
            // provenance LOOP_NUDGE — what the stall-recovery counters key on; the snapshot of the stalled call
            // stays inspectable (a stalled turn produces no assistant message). The tree findings are their own source:
            // they report the platform's reading of the block, not a stalled model.
            $aIConversationMessagesService->appendLoopSignalWithStepUsage(
                $agentRun,
                $conversation,
                $transientMessageIds,
                $stallSignalText,
                $isWidgetTreeFindingsStall ? AIConversationMessage::SIGNAL_SOURCE_WIDGET_TREE_FINDINGS : AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE,
                $modelName,
                $modelResponse,
                true,
                $consoleStream,
                $emitRealtimeSignal
            );
            return AgentStallRecoveryOutcome::afterNudge($modelResponse, $modelName);
        }
        return AgentStallRecoveryOutcome::withoutNudge($modelResponse, $modelName, $noToolCall);
    }
}
