<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;

/**
 * The context-retention and compaction options through a command: declared under their names and modes, each read
 * into its field of the value objects, nothing passed = no override, a bad value refused naming the option.
 */
class ContextRetentionSettingsOptionsTraitTest extends UnitTestCase
{
    public function testTheOptionsAreDeclared(): void
    {
        $commandDefinition = (new ContextRetentionSettingsOptionsProbeCommand())->getDefinition();

        foreach (['eager', 'evict-soft', 'evict-hard', 'evict-ref', 'evict-tool-tokens', 'evict-tool-turns', 'evict-skill-tokens',
            'evict-skill-turns', 'evict-min-residency', 'unload-granularity', 'skill-loading-strategy',
            'rebuild-epoch-on-situation-change', 'state-change-delivery', 'compact-trigger-tokens', 'compact-keep-messages',
            'compact-segment-messages', 'compact-fan-in', 'compact-retention'] as $optionName) {
            self::assertTrue($commandDefinition->hasOption($optionName), $optionName);
        }
    }

    public function testNothingPassedMeansNoOverride(): void
    {
        $probeCommand = new ContextRetentionSettingsOptionsProbeCommand();

        self::assertNull($probeCommand->buildContextRetentionOverride([]));
        self::assertNull($probeCommand->buildCompactionOverride([]));
    }

    public function testThePassedContextRetentionOptionsReachTheOverride(): void
    {
        $contextRetentionSettingsOverride = (new ContextRetentionSettingsOptionsProbeCommand())->buildContextRetentionOverride([
            '--eager' => true,
            '--evict-soft' => '3000',
            '--evict-min-residency' => '0',
            '--unload-granularity' => 'TOOL',
            '--skill-loading-strategy' => 'conversationappend',
            '--rebuild-epoch-on-situation-change' => 'off',
            '--state-change-delivery' => 'onceWhenChanged',
        ]);

        self::assertNotNull($contextRetentionSettingsOverride);
        self::assertTrue($contextRetentionSettingsOverride->loadToolsOnSkillLoad);
        self::assertSame(3000, $contextRetentionSettingsOverride->pressureBudgetTokens);
        self::assertSame(0, $contextRetentionSettingsOverride->minResidencyTurns);
        self::assertSame(ContextRetentionSettings::UNLOAD_GRANULARITY_TOOL, $contextRetentionSettingsOverride->unloadGranularity);
        self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND, $contextRetentionSettingsOverride->skillLoadingStrategy);
        self::assertFalse($contextRetentionSettingsOverride->rebuildEpochOnSituationChange);
        self::assertSame(ContextRetentionSettings::STATE_CHANGE_DELIVERY_ONCE_WHEN_CHANGED, $contextRetentionSettingsOverride->stateChangeDelivery);
        self::assertNull($contextRetentionSettingsOverride->hardCapTokens);
    }

    public function testTheCompactionOptionsWithThePrefixReachTheOverride(): void
    {
        $compactionSettingsOverride = (new ContextRetentionSettingsOptionsProbeCommand())->buildCompactionOverride([
            '--compact-trigger-tokens' => '200',
            '--compact-fan-in' => '2',
        ]);

        self::assertSame(200, $compactionSettingsOverride?->triggerTokens);
        self::assertSame(2, $compactionSettingsOverride?->tierFanIn);
        self::assertNull($compactionSettingsOverride?->keepRecentMessages);
    }

    public function testTheCompactionOptionsWithoutPrefixReachTheOverride(): void
    {
        $probeCommand = new ContextRetentionSettingsOptionsProbeCommand('');
        $compactionSettingsOverride = $probeCommand->buildCompactionOverride(['--keep-messages' => '2', '--segment-messages' => '3', '--retention' => '1']);

        self::assertTrue($probeCommand->getDefinition()->hasOption('trigger-tokens'));
        self::assertFalse($probeCommand->getDefinition()->hasOption('compact-trigger-tokens'));
        self::assertSame(2, $compactionSettingsOverride?->keepRecentMessages);
        self::assertSame(3, $compactionSettingsOverride?->segmentMessages);
        self::assertSame(1, $compactionSettingsOverride?->levelRetention);
    }

    public function testEveryEvictionOptionReachesItsField(): void
    {
        $commandInput = [];
        $expectedValuesByField = [];
        $value = 100;
        foreach (ContextRetentionSettingsOptionsProbeCommand::CONTEXT_RETENTION_COUNT_OPTION_NAMES_BY_FIELD as $fieldName => $optionName) {
            $commandInput['--' . $optionName] = (string)$value;
            $expectedValuesByField[$fieldName] = $value;
            $value++;
        }
        self::assertSame(
            ['evict-soft', 'evict-hard', 'evict-ref', 'evict-tool-tokens', 'evict-tool-turns', 'evict-skill-tokens', 'evict-skill-turns', 'evict-min-residency'],
            array_values(ContextRetentionSettingsOptionsProbeCommand::CONTEXT_RETENTION_COUNT_OPTION_NAMES_BY_FIELD)
        );

        $contextRetentionSettingsOverride = (new ContextRetentionSettingsOptionsProbeCommand())->buildContextRetentionOverride($commandInput);

        self::assertNotNull($contextRetentionSettingsOverride);
        foreach ($expectedValuesByField as $fieldName => $expectedValue) {
            self::assertSame($expectedValue, $contextRetentionSettingsOverride->{$fieldName}, $fieldName);
        }
    }

    public function testTheOptionModes(): void
    {
        $commandDefinition = (new ContextRetentionSettingsOptionsProbeCommand())->getDefinition();

        self::assertFalse($commandDefinition->getOption('eager')->acceptValue(), 'eager is a flag');
        foreach (['evict-soft', 'evict-min-residency', 'unload-granularity', 'skill-loading-strategy', 'rebuild-epoch-on-situation-change',
            'state-change-delivery', 'compact-trigger-tokens', 'compact-keep-messages', 'compact-segment-messages', 'compact-fan-in',
            'compact-retention'] as $optionName) {
            self::assertTrue($commandDefinition->getOption($optionName)->isValueRequired(), $optionName);
            self::assertNull($commandDefinition->getOption($optionName)->getDefault(), $optionName);
        }
    }

    public function testCompactionDefaultsOfTheCommandApplyWhenTheOptionIsNotPassed(): void
    {
        $probeCommand = new ContextRetentionSettingsOptionsProbeCommand('', ['segmentMessages' => '3', 'keepRecentMessages' => '3', 'tierFanIn' => '2', 'levelRetention' => '1', 'triggerTokens' => '200']);

        self::assertSame('3', $probeCommand->getDefinition()->getOption('segment-messages')->getDefault());
        $compactionSettingsOverride = $probeCommand->buildCompactionOverride(['--fan-in' => '4']);
        self::assertSame(200, $compactionSettingsOverride?->triggerTokens);
        self::assertSame(3, $compactionSettingsOverride?->keepRecentMessages);
        self::assertSame(3, $compactionSettingsOverride?->segmentMessages);
        self::assertSame(4, $compactionSettingsOverride?->tierFanIn);
        self::assertSame(1, $compactionSettingsOverride?->levelRetention);
    }

    public function testABlankValueCountsAsNotPassed(): void
    {
        self::assertNull((new ContextRetentionSettingsOptionsProbeCommand())->buildContextRetentionOverride(['--evict-soft' => ' ', '--unload-granularity' => '']));
    }

    public function testAThresholdThatIsNoWholeNumberIsRefusedNamingTheOption(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage("--evict-soft must be a whole number of at least 0, got 'abc'");
        (new ContextRetentionSettingsOptionsProbeCommand())->buildContextRetentionOverride(['--evict-soft' => 'abc']);
    }

    public function testAnUnknownUnloadingUnitIsRefusedNamingTheOption(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('--unload-granularity must be one of skill, tool');
        (new ContextRetentionSettingsOptionsProbeCommand())->buildContextRetentionOverride(['--unload-granularity' => 'tools']);
    }

    public function testAFanInBelowTwoIsRefusedNamingThePrefixedOption(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage("--compact-fan-in must be a whole number of at least 2, got '1'");
        (new ContextRetentionSettingsOptionsProbeCommand())->buildCompactionOverride(['--compact-fan-in' => '1']);
    }

    public function testAFanInBelowTwoIsRefusedNamingTheOptionWithoutPrefix(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage("--fan-in must be a whole number of at least 2, got '1'");
        (new ContextRetentionSettingsOptionsProbeCommand(''))->buildCompactionOverride(['--fan-in' => '1']);
    }
}
