<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Recorded-run comparison, the setup recording's gap (a): a tool answers "Goal captured. #57414" — a 5-digit id in free
 * text, below the text-wide id length. Behind the id marker `#` a number the run CREATED reads as its creation ordinal,
 * so two runs compare equal; an unmarked number and a marked number the run did not create stay raw.
 */
class AgentRecordedRunMarkedShortIdTest extends UnitTestCase
{
    protected function idNormalizationOfRun(int $firstGoalId): AgentRecordedRunIdNormalization
    {
        $idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks(['EntityGoals' => $firstGoalId - 1], []);
        $idNormalization->addMintedIds(['EntityGoals' => [$firstGoalId, $firstGoalId + 1]]);
        return $idNormalization;
    }

    public function testAMarkedIdTheRunCreatedReadsAsItsCreationOrdinalInBothRuns(): void
    {
        $recorded = $this->idNormalizationOfRun(57414)->normalizeText('Goal captured. #57414 — and #57415.');
        $replayed = $this->idNormalizationOfRun(60001)->normalizeText('Goal captured. #60001 — and #60002.');

        self::assertSame('Goal captured. #<EntityGoals#1> — and #<EntityGoals#2>.', $recorded);
        self::assertSame($recorded, $replayed);
    }

    public function testAnUnmarkedNumberAndAMarkedIdTheRunDidNotCreateStayRaw(): void
    {
        $idNormalization = $this->idNormalizationOfRun(57414);

        self::assertSame('57414 Besucher, Rang #3, Ticket #57000', $idNormalization->normalizeText('57414 Besucher, Rang #3, Ticket #57000'));
    }
}
