<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The purpose context budget scales the TOKEN-denominated eviction knobs
 * ({@see ContextRetentionSettings::withTokenKnobsScaledToContextBudget()}): factor = budget / reference, only the knobs
 * still null, only when the budget exceeds the reference; turn knobs never move; the tool-result budget is never scaled
 * (a purpose runs on the global default unless it pins one). Container-less: pure logic.
 */
class ContextRetentionSettingsContextBudgetScalingTest extends UnitTestCase
{
    public function testDoubledBudgetDoublesEveryUnsetTokenKnob(): void
    {
        $scaled = (new ContextRetentionSettings())->withTokenKnobsScaledToContextBudget(100000, 50000);

        self::assertSame(LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TOKENS * 2, $scaled->unloadToolAfterUnusedTokens);
        self::assertSame(LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TOKENS * 2, $scaled->unloadSkillAfterUnusedTokens);
        self::assertSame(LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS * 2, $scaled->pressureBudgetTokens);
        self::assertSame(LoadedCapability::DEFAULT_HARD_CAP_TOKENS * 2, $scaled->hardCapTokens);
        self::assertNull($scaled->toolResultTokenBudget, 'the tool-result budget is never scaled — the global default applies');
        // the concrete numbers the Website.Build purpose ends up with (defaults 8000/5000/24000/40000; tool budget unscaled)
        self::assertSame([16000, 10000, 48000, 80000, null], [
            $scaled->unloadToolAfterUnusedTokens,
            $scaled->unloadSkillAfterUnusedTokens,
            $scaled->pressureBudgetTokens,
            $scaled->hardCapTokens,
            $scaled->toolResultTokenBudget,
        ]);
    }

    public function testTurnKnobsAndOtherFieldsAreUntouched(): void
    {
        $settings = new ContextRetentionSettings();
        $settings->unloadToolAfterUnusedTurns = 3;
        $settings->keepToolResultsRecentTurns = 1;
        $settings->minResidencyTurns = 4;
        $settings->referenceResidentTokens = 500;

        $scaled = $settings->withTokenKnobsScaledToContextBudget(100000, 50000);

        self::assertSame(3, $scaled->unloadToolAfterUnusedTurns);
        self::assertSame(1, $scaled->keepToolResultsRecentTurns);
        self::assertSame(4, $scaled->minResidencyTurns);
        self::assertSame(500, $scaled->referenceResidentTokens);
        self::assertNull($scaled->unloadSkillAfterUnusedTurns);
    }

    public function testExplicitTokenKnobsWinOverScaling(): void
    {
        $settings = new ContextRetentionSettings();
        $settings->unloadSkillAfterUnusedTokens = 30000;
        $settings->pressureBudgetTokens = 9000;

        $scaled = $settings->withTokenKnobsScaledToContextBudget(100000, 50000);

        self::assertSame(30000, $scaled->unloadSkillAfterUnusedTokens);
        self::assertSame(9000, $scaled->pressureBudgetTokens);
        self::assertSame(16000, $scaled->unloadToolAfterUnusedTokens);
    }

    public function testBudgetAtOrBelowTheReferenceChangesNothing(): void
    {
        $atReference = (new ContextRetentionSettings())->withTokenKnobsScaledToContextBudget(50000, 50000);
        $belowReference = (new ContextRetentionSettings())->withTokenKnobsScaledToContextBudget(30000, 50000);
        $invalid = (new ContextRetentionSettings())->withTokenKnobsScaledToContextBudget(0, 50000);

        foreach ([$atReference, $belowReference, $invalid] as $unchanged) {
            self::assertNull($unchanged->unloadToolAfterUnusedTokens);
            self::assertNull($unchanged->unloadSkillAfterUnusedTokens);
            self::assertNull($unchanged->pressureBudgetTokens);
            self::assertNull($unchanged->hardCapTokens);
            self::assertNull($unchanged->toolResultTokenBudget);
        }
    }

    public function testScalingReturnsACloneAndLeavesTheSourceUntouched(): void
    {
        $settings = new ContextRetentionSettings();
        $scaled = $settings->withTokenKnobsScaledToContextBudget(75000, 50000);

        self::assertNotSame($settings, $scaled);
        self::assertNull($settings->pressureBudgetTokens);
        self::assertSame(36000, $scaled->pressureBudgetTokens);
        self::assertSame(7500, $scaled->unloadSkillAfterUnusedTokens);
    }
}
