<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionSummary;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIConversationCompaction;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The summarizer's result value (formerly `array{text, model, tokens}`): the factory carries the three
 * fields as given, and `isEmpty()` is the round's stop condition. Pure; no container.
 */
class AIConversationCompactionSummaryTest extends UnitTestCase
{
    public function testFactoryCarriesTextModelAndOutputTokens(): void
    {
        $compactionSummary = AIConversationCompactionSummary::fromSummarizerOutput('The owner confirmed the hours.', 'openai/gpt-6-luna', 412);

        self::assertSame('The owner confirmed the hours.', $compactionSummary->summaryText);
        self::assertSame('openai/gpt-6-luna', $compactionSummary->aiModelName);
        self::assertSame(412, $compactionSummary->outputTokens);
        self::assertFalse($compactionSummary->isEmpty());
    }

    public function testFactoryKeepsUnknownProvenanceAsNull(): void
    {
        $compactionSummary = AIConversationCompactionSummary::fromSummarizerOutput('gist', null, null);

        self::assertNull($compactionSummary->aiModelName);
        self::assertNull($compactionSummary->outputTokens);
    }

    public function testEmptyAndWhitespaceOnlyTextIsEmpty(): void
    {
        self::assertTrue(AIConversationCompactionSummary::fromSummarizerOutput('', 'model', 0)->isEmpty());
        self::assertTrue(AIConversationCompactionSummary::fromSummarizerOutput(" \n\t ", 'model', 3)->isEmpty());
        self::assertTrue((new AIConversationCompactionSummary())->isEmpty());
    }

    public function testTheSummarizerArgusRepoIsBasedOnTheSummary(): void
    {
        // the direct parent: ArgusTrait::uniqueKeyStatic() and toEntity() read it
        $argusParentClass = (new \ReflectionClass(ArgusAIConversationCompaction::class))->getParentClass();
        self::assertNotFalse($argusParentClass);
        self::assertSame(AIConversationCompactionSummary::class, $argusParentClass->getName());
    }
}
