<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Symfony\Commands\Agents\SchedulesServiceWithOwnSkipWordsDouble;

/**
 * The schedules service answers which skip an occurrence was with its own constants: the module's service with the
 * module's words, an application's service that redeclares the agent-space reasons with the application's words.
 */
class AIConversationSchedulesServiceSkipReasonQuestionsTest extends UnitTestCase
{
    public function testTheModulesServiceAnswersWithTheModulesWords(): void
    {
        /** @var AIConversationSchedulesService $aIConversationSchedulesService */
        $aIConversationSchedulesService = self::instantiateWithoutConstructor(AIConversationSchedulesService::class);

        self::assertTrue($aIConversationSchedulesService->isSkipReasonOfMissingAgentSpace('AGENT_SPACE_MISSING'));
        self::assertTrue($aIConversationSchedulesService->isSkipReasonOfIneligibleAgentSpace('AGENT_SPACE_NOT_ELIGIBLE'));
        self::assertTrue($aIConversationSchedulesService->isSkipReasonOfExhaustedBudgetPot('BUDGET_POT_EXHAUSTED'));
        self::assertTrue($aIConversationSchedulesService->isSkipReasonOfPreviousRunInProgress('PREVIOUS_RUN_IN_PROGRESS'));
        self::assertFalse($aIConversationSchedulesService->isSkipReasonOfMissingAgentSpace('AGENT_SPACE_NOT_ELIGIBLE'));
    }

    public function testAnApplicationsServiceAnswersWithItsOwnWords(): void
    {
        /** @var SchedulesServiceWithOwnSkipWordsDouble $schedulesServiceWithOwnSkipWords */
        $schedulesServiceWithOwnSkipWords = self::instantiateWithoutConstructor(SchedulesServiceWithOwnSkipWordsDouble::class);

        self::assertTrue($schedulesServiceWithOwnSkipWords->isSkipReasonOfMissingAgentSpace('SHOP_MISSING'));
        self::assertTrue($schedulesServiceWithOwnSkipWords->isSkipReasonOfIneligibleAgentSpace('SHOP_NOT_ELIGIBLE'));
        self::assertFalse($schedulesServiceWithOwnSkipWords->isSkipReasonOfMissingAgentSpace('AGENT_SPACE_MISSING'));
        self::assertFalse($schedulesServiceWithOwnSkipWords->isSkipReasonOfIneligibleAgentSpace('AGENT_SPACE_NOT_ELIGIBLE'));
        self::assertTrue($schedulesServiceWithOwnSkipWords->isSkipReasonOfExhaustedBudgetPot('BUDGET_POT_EXHAUSTED'));
    }
}
