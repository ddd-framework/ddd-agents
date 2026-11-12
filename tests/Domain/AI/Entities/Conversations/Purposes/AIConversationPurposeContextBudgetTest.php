<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The purpose's context-budget builders: {@see AIConversationPurpose::buildCompactionSettingsForContextBudget()}
 * (the trigger the purposed start stamps) and {@see AIConversationPurpose::buildContextRetentionSettingsForConversation()}
 * (the retention policy with the budget-scaled token knobs; a purpose without a budget returns its plain policy).
 * Container-less: pure logic over the entity.
 */
class AIConversationPurposeContextBudgetTest extends UnitTestCase
{
    protected function purpose(?int $contextBudgetTokens, ?ContextRetentionSettings $contextRetentionSettings = null): AIConversationPurpose
    {
        /** @var AIConversationPurpose $purpose */
        $purpose = self::instantiateWithoutConstructor(AIConversationPurpose::class);
        $purpose->name = 'Test.Purpose';
        $purpose->contextBudgetTokens = $contextBudgetTokens;
        $purpose->contextRetentionSettings = $contextRetentionSettings;
        return $purpose;
    }

    public function testCompactionSettingsCarryTheEffectiveBudgetAsTrigger(): void
    {
        $compactionSettings = $this->purpose(100000)->buildCompactionSettingsForContextBudget(96000);
        self::assertNotNull($compactionSettings);
        self::assertSame(96000, $compactionSettings->triggerTokens);
        self::assertNull($this->purpose(100000)->buildCompactionSettingsForContextBudget(0));
    }

    public function testNoBudgetReturnsThePlainRetentionPolicy(): void
    {
        self::assertNull($this->purpose(null)->buildContextRetentionSettingsForConversation());

        $explicit = new ContextRetentionSettings();
        $explicit->unloadSkillAfterUnusedTokens = 20000;
        $result = $this->purpose(null, $explicit)->buildContextRetentionSettingsForConversation();
        self::assertSame($explicit, $result);
    }

    public function testBudgetScalesTheRetentionPolicyEvenWhenThePurposeDeclaresNone(): void
    {
        $result = $this->purpose(100000)->buildContextRetentionSettingsForConversation();
        self::assertInstanceOf(ContextRetentionSettings::class, $result);
        self::assertSame(48000, $result->pressureBudgetTokens); // 24k × 100k/50k
        self::assertSame(80000, $result->hardCapTokens); // 40k × 2
        self::assertSame(10000, $result->unloadSkillAfterUnusedTokens);
    }

    public function testBudgetKeepsThePurposesExplicitKnobsAndScalesTheRest(): void
    {
        $explicit = new ContextRetentionSettings();
        $explicit->unloadSkillAfterUnusedTokens = 20000;
        $explicit->keepToolResultsRecentTurns = 3;

        $result = $this->purpose(100000, $explicit)->buildContextRetentionSettingsForConversation();
        self::assertNotSame($explicit, $result);
        self::assertSame(20000, $result->unloadSkillAfterUnusedTokens);
        self::assertSame(3, $result->keepToolResultsRecentTurns);
        self::assertSame(16000, $result->unloadToolAfterUnusedTokens);
        self::assertNull($result->toolResultTokenBudget, 'the tool-result budget is never scaled — the global default applies (owner 2026-10-21)');
    }
}
