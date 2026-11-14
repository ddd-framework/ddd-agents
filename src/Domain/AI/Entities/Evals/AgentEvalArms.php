<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The ARMS of one evaluation run: one switch per decision site, the kill switch of the decision layer, the fixed
 * reasoning effort, the agent wire arms and the request-snapshot switch. Null (false for the two flags) = not chosen:
 * the conversation inherits the platform default, so a run without arms measures what real conversations get.
 *
 * Every conversation of the run carries the arms as rows of its {@see RunSettings} ({@see buildEvalRunSettings()}):
 * no environment switch between two arms of a comparison, and a worker that resumes the conversation reads the same
 * rows. The tool block of a case (blocked tools, their simulated errors, the read-only switch) differs from case to
 * case and is passed in by the caller.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalArms extends ValueObject
{
    /** @var string Plan-dynamics arm: the decision model is not asked, the chat model decides alone */
    public const string PLAN_DYNAMICS_ARM_OFF = 'off';

    /** @var string Plan-dynamics arm: the decision model is asked and its verdict recorded, the chat model does not receive it */
    public const string PLAN_DYNAMICS_ARM_OBSERVE = 'observe';

    /** @var string Plan-dynamics arm: the chat model receives the verdict of the decision model */
    public const string PLAN_DYNAMICS_ARM_GUIDE = 'guide';

    /** @var string[] The three plan-dynamics arms */
    public const array PLAN_DYNAMICS_ARMS = [self::PLAN_DYNAMICS_ARM_OFF, self::PLAN_DYNAMICS_ARM_OBSERVE, self::PLAN_DYNAMICS_ARM_GUIDE];

    /** @var string[] The run-settings rows an arm can set, in the order {@see getRowValuesByRowName()} lists them */
    public const array RUN_SETTINGS_ROW_NAMES = [
        'decisionModelSkillPreload',
        'decisionModelReplyLanguage',
        'decisionModelTurnClosing',
        'decisionModelReasoningEffort',
        'decisionModelStallRecovery',
        'planDynamicsAskDecisionModel',
        'planDynamicsModelReceivesVerdict',
        'decisionModelOff',
        'fixedReasoningEffort',
        'agentWireApi',
        'previousReasoningCarryOver',
        'previousReasoningCapTokens',
        'storeRequestSnapshot',
    ];

    /** @var bool The kill switch: true = no decision site runs in the run's conversations */
    public bool $decisionModelOff = false;

    /** @var string|null The reply-language row (`on` / `off`); null = inherit */
    public ?string $replyLanguageRow = null;

    /** @var string|null The skill-preload row (`on` / `off`); null = inherit */
    public ?string $skillPreloadRow = null;

    /** @var string|null The turn-closing row (`off` or a turn-closing mode); null = inherit */
    public ?string $turnClosingRow = null;

    /** @var string|null One of {@see PLAN_DYNAMICS_ARMS}; null = the platform's two switches */
    public ?string $planDynamicsArm = null;

    /** @var string|null The reasoning-effort row (`off` or a floor `low` / `medium` / `high`); null = inherit */
    public ?string $reasoningEffortRow = null;

    /** @var string|null The stall-recovery row (`on` / `off`); null = inherit */
    public ?string $stallRecoveryRow = null;

    /** @var string|null A fixed reasoning effort for every model call (`low` / `medium` / `high`); null = the platform resolves it */
    public ?string $fixedReasoningEffort = null;

    /** @var string|null One of {@see RunSettings::AGENT_WIRE_APIS}; null = the model catalog */
    public ?string $agentWireApi = null;

    /** @var string|null One of {@see RunSettings::PREVIOUS_REASONING_CARRY_OVER_VALUES}; null = the default of the wire API */
    public ?string $previousReasoningCarryOver = null;

    /** @var int|null The cap of the carry-over `capped` in reasoning tokens; null = the committed default */
    public ?int $previousReasoningCapTokens = null;

    /** @var string|null The skill-loading strategy stamped at each conversation's creation; null = the purpose's or the global default */
    public ?string $skillLoadingStrategy = null;

    /** @var bool|null The epoch rebuild on a situation change, stamped at each conversation's creation; null = the purpose's or the global default */
    public ?bool $rebuildEpochOnSituationChange = null;

    /** @var string|null The state-change delivery stamped at each conversation's creation; null = the purpose's or the global default */
    public ?string $stateChangeDelivery = null;

    /** @var bool true = every model call of the run stores its full sent payload and raw response */
    public bool $storeRequestSnapshot = false;

    /**
     * The run settings every conversation of the run starts with: the evaluation marker, every arm, and the tool block
     * of the case. The tool block is required: a conversation built without it would carry no stored block, and a
     * worker that resumes it would refuse nothing.
     *
     * @param string[] $blockedToolNames the tools the case blocks ([] = none)
     * @param array<string, string> $blockedToolSimulatedErrors the error text a blocked tool answers, by tool name
     * @param bool $everyWriteToolBlocked true = every tool that is not a declared read is blocked
     * @return RunSettings a new run settings object
     */
    public function buildEvalRunSettings(array $blockedToolNames, array $blockedToolSimulatedErrors, bool $everyWriteToolBlocked): RunSettings
    {
        $runSettings = RunSettings::newInstance();
        $runSettings->evalRun = true;
        $runSettings->decisionModelTurnClosing = $this->turnClosingRow;
        $runSettings->decisionModelReasoningEffort = $this->reasoningEffortRow;
        $runSettings->decisionModelStallRecovery = $this->stallRecoveryRow;
        $runSettings->decisionModelSkillPreload = $this->skillPreloadRow;
        $runSettings->decisionModelReplyLanguage = $this->replyLanguageRow;
        if ($this->planDynamicsArm !== null) {
            $runSettings->planDynamicsAskDecisionModel = $this->planDynamicsArm !== static::PLAN_DYNAMICS_ARM_OFF;
            $runSettings->planDynamicsModelReceivesVerdict = $this->planDynamicsArm === static::PLAN_DYNAMICS_ARM_GUIDE;
        }
        if ($this->decisionModelOff) {
            $runSettings->decisionModelOff = true;
        }
        $runSettings->fixedReasoningEffort = $this->fixedReasoningEffort;
        $runSettings->agentWireApi = $this->agentWireApi;
        $runSettings->previousReasoningCarryOver = $this->previousReasoningCarryOver;
        $runSettings->previousReasoningCapTokens = $this->previousReasoningCapTokens;
        $runSettings->requestedSkillLoadingStrategy = $this->skillLoadingStrategy;
        $runSettings->requestedRebuildEpochOnSituationChange = $this->rebuildEpochOnSituationChange;
        $runSettings->requestedStateChangeDelivery = $this->stateChangeDelivery;
        $runSettings->storeRequestSnapshot = $this->storeRequestSnapshot ? true : null;
        $runSettings->setEvalToolBlock($blockedToolNames, $blockedToolSimulatedErrors, $everyWriteToolBlocked);
        return $runSettings;
    }

    /**
     * The arms as the rows a forked conversation or a targeted write of a stored one needs: row name → value, only the
     * rows an arm sets (the creation stamps and the tool block are no such rows).
     *
     * @return array<string, string|bool|int>
     */
    public function getRowValuesByRowName(): array
    {
        $runSettings = $this->buildEvalRunSettings([], [], false);
        $rowValuesByRowName = [];
        foreach (static::RUN_SETTINGS_ROW_NAMES as $rowName) {
            if ($runSettings->{$rowName} !== null) {
                $rowValuesByRowName[$rowName] = $runSettings->{$rowName};
            }
        }
        return $rowValuesByRowName;
    }
}
