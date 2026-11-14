<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalArms;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The arms of an evaluation run become the run settings of every conversation of the run: the evaluation marker, one
 * row per chosen arm, nothing for an arm not chosen, and the tool block the caller passes for the case.
 */
class AgentEvalArmsTest extends UnitTestCase
{
    public function testNoArmGivesTheEvaluationMarkerAndNoRow(): void
    {
        $agentEvalArms = AgentEvalArms::newInstance();
        $runSettings = $agentEvalArms->buildEvalRunSettings([], [], false);

        self::assertTrue($runSettings->evalRun);
        self::assertSame([], $agentEvalArms->getRowValuesByRowName());
        foreach (AgentEvalArms::RUN_SETTINGS_ROW_NAMES as $rowName) {
            self::assertNull($runSettings->{$rowName}, $rowName);
        }
        self::assertNull($runSettings->requestedSkillLoadingStrategy);
        self::assertNull($runSettings->evalBlockedToolNames);
        self::assertNull($runSettings->evalEveryWriteToolBlocked);
    }

    public function testThePlanDynamicsArmsBecomeTheTwoSwitches(): void
    {
        $expectedSwitchesByArm = [
            AgentEvalArms::PLAN_DYNAMICS_ARM_OFF => [false, false],
            AgentEvalArms::PLAN_DYNAMICS_ARM_OBSERVE => [true, false],
            AgentEvalArms::PLAN_DYNAMICS_ARM_GUIDE => [true, true],
        ];
        foreach ($expectedSwitchesByArm as $planDynamicsArm => [$asksDecisionModel, $modelReceivesVerdict]) {
            $agentEvalArms = AgentEvalArms::newInstance();
            $agentEvalArms->planDynamicsArm = $planDynamicsArm;
            $runSettings = $agentEvalArms->buildEvalRunSettings([], [], false);
            self::assertSame($asksDecisionModel, $runSettings->planDynamicsAskDecisionModel, $planDynamicsArm);
            self::assertSame($modelReceivesVerdict, $runSettings->planDynamicsModelReceivesVerdict, $planDynamicsArm);
        }
    }

    public function testTheToolBlockOfTheCaseIsPassedIn(): void
    {
        $agentEvalArms = AgentEvalArms::newInstance();
        $agentEvalArms->skillLoadingStrategy = 'conversationAppend';
        $agentEvalArms->rebuildEpochOnSituationChange = true;
        $agentEvalArms->storeRequestSnapshot = true;

        $runSettings = $agentEvalArms->buildEvalRunSettings(['sendMessage'], ['sendMessage' => 'the channel is down'], true);

        self::assertSame(['sendMessage'], $runSettings->evalBlockedToolNames);
        self::assertSame(['sendMessage' => 'the channel is down'], $runSettings->evalBlockedToolSimulatedErrors);
        self::assertTrue($runSettings->evalEveryWriteToolBlocked);
        self::assertSame('conversationAppend', $runSettings->requestedSkillLoadingStrategy);
        self::assertTrue($runSettings->requestedRebuildEpochOnSituationChange);
        self::assertSame(['storeRequestSnapshot' => true], $agentEvalArms->getRowValuesByRowName(), 'the creation stamps and the tool block are no rows');
    }

    public function testEveryChosenRowIsListed(): void
    {
        $agentEvalArms = AgentEvalArms::newInstance();
        $agentEvalArms->decisionModelOff = true;
        $agentEvalArms->turnClosingRow = 'off';
        $agentEvalArms->agentWireApi = 'responses';
        $agentEvalArms->previousReasoningCapTokens = 1000;

        self::assertSame(
            ['decisionModelTurnClosing' => 'off', 'decisionModelOff' => true, 'agentWireApi' => 'responses', 'previousReasoningCapTokens' => 1000],
            $agentEvalArms->getRowValuesByRowName()
        );
    }
}
