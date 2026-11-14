<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStepToolChoice;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The step's tool choice renders the exact `tool_choice` values the loop sent as bare values
 * before (null, 'none', 'required', the function object).
 */
class AgentStepToolChoiceTest extends UnitTestCase
{
    public function testEachModeRendersTheWireValueTheLoopSent(): void
    {
        self::assertNull(AgentStepToolChoice::auto()->toWireValue());
        self::assertSame('none', AgentStepToolChoice::none(AgentStepToolChoice::REASON_TOOL_LESS_ANSWER_STEP)->toWireValue());
        self::assertSame('required', AgentStepToolChoice::required(AgentStepToolChoice::REASON_STALL_REDRIVE)->toWireValue());
        self::assertSame(
            ['type' => 'function', 'function' => ['name' => 'writeSiteBrief']],
            AgentStepToolChoice::function('writeSiteBrief', AgentStepToolChoice::REASON_FORCED_FIRST_STEP)->toWireValue()
        );
    }

    public function testOnlyAToolLessStepKeepsTheModelFromCallingATool(): void
    {
        self::assertFalse(AgentStepToolChoice::none(AgentStepToolChoice::REASON_TOOL_LESS_ANSWER_STEP)->letsTheModelCallTools());
        self::assertTrue(AgentStepToolChoice::auto()->letsTheModelCallTools());
        self::assertTrue(AgentStepToolChoice::required(AgentStepToolChoice::REASON_STALL_REDRIVE)->letsTheModelCallTools());
        self::assertTrue(AgentStepToolChoice::function('writeSiteBrief', AgentStepToolChoice::REASON_FORCED_FIRST_STEP)->letsTheModelCallTools());
    }

    public function testAStepOffersWorkToolsOnlyWhenThereAreSomeAndTheModelMayCallThem(): void
    {
        $workTools = AgentOfferedTools::fromToolSchemas([['type' => 'function', 'function' => ['name' => 'compareListings', 'description' => 'Compares two listing services.', 'parameters' => ['type' => 'object', 'properties' => []]]]]);
        $noWorkTools = AgentOfferedTools::fromToolSchemas([]);

        self::assertTrue(AgentStepToolChoice::auto()->offersWorkToolsToCall($workTools));
        self::assertTrue(AgentStepToolChoice::required(AgentStepToolChoice::REASON_STALL_REDRIVE)->offersWorkToolsToCall($workTools));
        self::assertFalse(AgentStepToolChoice::none(AgentStepToolChoice::REASON_TOOL_LESS_ANSWER_STEP)->offersWorkToolsToCall($workTools), 'a step sent without tools');
        self::assertFalse(AgentStepToolChoice::auto()->offersWorkToolsToCall($noWorkTools), 'nothing to call');
    }
}
