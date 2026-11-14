<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;

/**
 * The arm options every evaluation command shares: an option that is not passed (or says `default` / `inherit`)
 * changes nothing, a passed one becomes a field of the arms object and a row of the run settings, a value outside the
 * option's choices is refused, and a child process gets the arms back as arguments.
 */
class AgentEvalArmOptionsTraitTest extends UnitTestCase
{
    public function testNothingPassedMeansThePlatformDefault(): void
    {
        $probeCommand = new AgentEvalArmOptionsProbeCommand();

        self::assertSame([], $probeCommand->resolveArms([]));
        self::assertSame([], $probeCommand->resolveArms(['--reasoning-effort' => 'default', '--decision-model-reasoning-effort' => 'inherit', '--decision-model-skill-preload' => '']));
        self::assertSame([], $probeCommand->buildArms([])->getRowValuesByRowName());
    }

    public function testAnArmPassedWithoutAValueIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('requires a value');
        (new AgentEvalArmOptionsProbeCommand())->resolveArms(['--decision-model' => null]);
    }

    public function testPassedArmsAreCanonicalAndReachTheArmsAndTheChildProcess(): void
    {
        $probeCommand = new AgentEvalArmOptionsProbeCommand();
        $arms = $probeCommand->resolveArms([
            '--decision-model' => 'OFF',
            '--decision-model-reply-language' => 'off',
            '--decision-model-skill-preload' => 'On',
            '--decision-model-turn-closing' => 'counterthendecisionmodel',
            '--decision-model-plan-dynamics' => 'observe',
            '--decision-model-reasoning-effort' => 'medium',
            '--decision-model-stall-recovery' => 'off',
            '--reasoning-effort' => 'LOW',
        ]);

        self::assertSame([
            'decision-model' => 'off',
            'decision-model-reply-language' => 'off',
            'decision-model-skill-preload' => 'on',
            'decision-model-turn-closing' => 'counterThenDecisionModel',
            'decision-model-plan-dynamics' => 'observe',
            'decision-model-reasoning-effort' => 'medium',
            'decision-model-stall-recovery' => 'off',
            'reasoning-effort' => 'low',
        ], $arms);
        self::assertSame([
            'decisionModelSkillPreload' => 'on',
            'decisionModelReplyLanguage' => 'off',
            'decisionModelTurnClosing' => 'counterThenDecisionModel',
            'decisionModelReasoningEffort' => 'medium',
            'decisionModelStallRecovery' => 'off',
            'planDynamicsAskDecisionModel' => true,
            'planDynamicsModelReceivesVerdict' => false,
            'decisionModelOff' => true,
            'fixedReasoningEffort' => 'low',
        ], $probeCommand->buildArms($arms)->getRowValuesByRowName());
        self::assertSame(
            ['--decision-model-skill-preload=on', '--reasoning-effort=low'],
            AgentEvalArmOptionsProbeCommand::buildEvalArmArguments(['decision-model-skill-preload' => 'on', 'reasoning-effort' => 'low'])
        );
    }

    public function testTheStoreSnapshotFlagReachesTheRowsAndTheChildAsTheBareFlag(): void
    {
        $probeCommand = new AgentEvalArmOptionsProbeCommand();
        $arms = $probeCommand->resolveArms(['--store-snapshot' => true]);

        self::assertSame(['store-snapshot' => 'on'], $arms);
        self::assertSame(['--store-snapshot'], AgentEvalArmOptionsProbeCommand::buildEvalArmArguments($arms));
        self::assertSame(['storeRequestSnapshot' => true], $probeCommand->buildArms($arms)->getRowValuesByRowName());
    }

    public function testThePreviousReasoningArmsReachTheRows(): void
    {
        $probeCommand = new AgentEvalArmOptionsProbeCommand();
        $arms = $probeCommand->resolveArms(['--previous-reasoning-carry-over' => 'Capped', '--previous-reasoning-cap-tokens' => ' 2500 ']);

        self::assertSame(['previous-reasoning-carry-over' => 'capped', 'previous-reasoning-cap-tokens' => '2500'], $arms);
        self::assertSame(['previousReasoningCarryOver' => 'capped', 'previousReasoningCapTokens' => 2500], $probeCommand->buildArms($arms)->getRowValuesByRowName());
        self::assertSame([], $probeCommand->resolveArms(['--previous-reasoning-cap-tokens' => 'default']));
        self::assertSame([], $probeCommand->resolveArms(['--previous-reasoning-cap-tokens' => 'Inherit']), 'every arm accepts inherit');
    }

    public function testTheCreationStampArmsReachTheRunSettings(): void
    {
        $probeCommand = new AgentEvalArmOptionsProbeCommand();
        $runSettings = $probeCommand->buildArms($probeCommand->resolveArms([
            '--agent-wire-api' => 'chatCompletions',
            '--skill-loading-strategy' => 'systemPromptSwap',
            '--state-change-delivery' => 'everyCall',
            '--rebuild-epoch-on-situation-change' => 'off',
            '--previous-reasoning-carry-over' => 'off',
        ]))->buildEvalRunSettings([], [], false);

        self::assertSame('chatCompletions', $runSettings->agentWireApi);
        self::assertSame('systemPromptSwap', $runSettings->requestedSkillLoadingStrategy);
        self::assertSame('everyCall', $runSettings->requestedStateChangeDelivery);
        self::assertFalse($runSettings->requestedRebuildEpochOnSituationChange);
        self::assertSame('off', $runSettings->previousReasoningCarryOver);
    }

    public function testANonPositiveCapIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('--previous-reasoning-cap-tokens must be a positive number of reasoning tokens');
        (new AgentEvalArmOptionsProbeCommand())->resolveArms(['--previous-reasoning-cap-tokens' => '0']);
    }

    public function testAValueOutsideTheChoicesIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('--reasoning-effort must be one of: low, medium, high');
        (new AgentEvalArmOptionsProbeCommand())->resolveArms(['--reasoning-effort' => 'fast']);
    }
}
