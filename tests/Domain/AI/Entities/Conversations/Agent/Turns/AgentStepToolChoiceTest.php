<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Turns;

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
}
