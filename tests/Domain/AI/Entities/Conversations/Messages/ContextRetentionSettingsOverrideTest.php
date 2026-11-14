<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettingsOverride;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The context-retention override parses the raw option values (blank = not passed), refuses a value outside what its
 * knob accepts, and writes only the passed knobs onto a conversation's settings.
 */
class ContextRetentionSettingsOverrideTest extends UnitTestCase
{
    public function testNothingPassedGivesNoOverride(): void
    {
        self::assertNull(ContextRetentionSettingsOverride::fromRawValues());
        self::assertNull(ContextRetentionSettingsOverride::fromRawValues(false, ' ', '', null, '', ['hardCapTokens' => null, 'minResidencyTurns' => '']));
    }

    public function testEveryKnobIsParsedToItsCanonicalValue(): void
    {
        $contextRetentionSettingsOverride = ContextRetentionSettingsOverride::fromRawValues(
            true,
            ' Skill ',
            'SYSTEMPROMPTSWAP',
            'ON',
            'everycall',
            ['hardCapTokens' => ' 9000 ', 'unloadSkillAfterUnusedTurns' => '0']
        );

        self::assertNotNull($contextRetentionSettingsOverride);
        self::assertTrue($contextRetentionSettingsOverride->loadToolsOnSkillLoad);
        self::assertSame(ContextRetentionSettings::UNLOAD_GRANULARITY_SKILL, $contextRetentionSettingsOverride->unloadGranularity);
        self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, $contextRetentionSettingsOverride->skillLoadingStrategy);
        self::assertTrue($contextRetentionSettingsOverride->rebuildEpochOnSituationChange);
        self::assertSame(ContextRetentionSettings::STATE_CHANGE_DELIVERY_EVERY_CALL, $contextRetentionSettingsOverride->stateChangeDelivery);
        self::assertSame(9000, $contextRetentionSettingsOverride->hardCapTokens);
        self::assertSame(0, $contextRetentionSettingsOverride->unloadSkillAfterUnusedTurns);
        self::assertNull($contextRetentionSettingsOverride->pressureBudgetTokens);
    }

    public function testApplyToMergesThePassedKnobsIntoACopy(): void
    {
        $storedSettings = ContextRetentionSettings::newInstance();
        $storedSettings->pressureBudgetTokens = 1234;
        $storedSettings->storeRequestSnapshot = true;
        $storedSettings->unloadGranularity = ContextRetentionSettings::UNLOAD_GRANULARITY_SKILL;
        $storedSettings->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP;

        $appliedSettings = ContextRetentionSettingsOverride::fromRawValues(
            true,
            'tool',
            'conversationAppend',
            'off',
            'onceWhenChanged',
            ['hardCapTokens' => '5000']
        )?->applyTo($storedSettings);

        self::assertNotNull($appliedSettings);
        self::assertNotSame($storedSettings, $appliedSettings, 'the result is a copy');
        self::assertSame(1234, $appliedSettings->pressureBudgetTokens);
        self::assertTrue($appliedSettings->storeRequestSnapshot);
        self::assertSame(ContextRetentionSettings::UNLOAD_GRANULARITY_TOOL, $appliedSettings->unloadGranularity);
        self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND, $appliedSettings->skillLoadingStrategy);
        self::assertSame(ContextRetentionSettings::STATE_CHANGE_DELIVERY_ONCE_WHEN_CHANGED, $appliedSettings->stateChangeDelivery);
        self::assertTrue($appliedSettings->loadToolsOnSkillLoad);
        self::assertFalse($appliedSettings->rebuildEpochOnSituationChange);
        self::assertSame(5000, $appliedSettings->hardCapTokens);
    }

    public function testApplyToNeverChangesTheSettingsPassedIn(): void
    {
        $storedSettings = ContextRetentionSettings::newInstance();
        $storedSettings->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP;
        $storedSettings->stateChangeDelivery = ContextRetentionSettings::STATE_CHANGE_DELIVERY_EVERY_CALL;

        ContextRetentionSettingsOverride::fromRawValues(true, 'tool', 'conversationAppend', 'on', 'onceWhenChanged', ['minResidencyTurns' => '1'])
            ?->applyTo($storedSettings);

        // a creation-stamp check compares the stored settings with the result: they must still differ
        self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, $storedSettings->skillLoadingStrategy);
        self::assertSame(ContextRetentionSettings::STATE_CHANGE_DELIVERY_EVERY_CALL, $storedSettings->stateChangeDelivery);
        self::assertNull($storedSettings->loadToolsOnSkillLoad);
        self::assertNull($storedSettings->unloadGranularity);
        self::assertNull($storedSettings->rebuildEpochOnSituationChange);
        self::assertNull($storedSettings->minResidencyTurns);
    }

    public function testApplyToWithoutSettingsBuildsSettingsWithThePassedKnobsOnly(): void
    {
        $appliedSettings = ContextRetentionSettingsOverride::fromRawValues(true)?->applyTo(null);

        self::assertInstanceOf(ContextRetentionSettings::class, $appliedSettings);
        self::assertTrue($appliedSettings->loadToolsOnSkillLoad);
        self::assertNull($appliedSettings->pressureBudgetTokens);
        self::assertNull($appliedSettings->unloadGranularity);
    }

    public function testAThresholdTooLargeForAnIntegerIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage("minResidencyTurns is too large, got '99999999999999999999999'");
        ContextRetentionSettingsOverride::fromRawValues(rawCountsByField: ['minResidencyTurns' => '99999999999999999999999']);
    }

    public function testANegativeThresholdIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage("minResidencyTurns must be a whole number of at least 0, got '-1'");
        ContextRetentionSettingsOverride::fromRawValues(false, null, null, null, null, ['minResidencyTurns' => '-1']);
    }

    public function testAnUnknownStateChangeDeliveryIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('stateChangeDelivery must be one of everyCall, onceWhenChanged');
        ContextRetentionSettingsOverride::fromRawValues(false, null, null, null, 'sometimes');
    }

    public function testASwitchOtherThanOnOrOffIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('rebuildEpochOnSituationChange must be on or off');
        ContextRetentionSettingsOverride::fromRawValues(false, null, null, 'yes');
    }

    public function testAnUnknownThresholdFieldIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('enabled is no threshold of the context retention');
        ContextRetentionSettingsOverride::fromRawValues(false, null, null, null, null, ['enabled' => '1']);
    }
}
