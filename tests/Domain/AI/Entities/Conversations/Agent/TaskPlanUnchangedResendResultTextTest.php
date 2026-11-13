<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The result of an `update_task_plan` call that sent the plan as it stands says that nothing failed and names both
 * ways on — the next open item's work, or the answer when the open items wait for the owner. It orders no work.
 */
class TaskPlanUnchangedResendResultTextTest extends UnitTestCase
{
    public function testTheResultNamesBothWaysOnAndOrdersNoWork(): void
    {
        $resultText = TaskPlan::buildUnchangedResendResultText();

        self::assertStringStartsWith('Task plan NOT updated: it is ALREADY in exactly this state', $resultText);
        self::assertStringContainsString('Nothing failed and nothing is unavailable', $resultText);
        self::assertStringContainsString('the work of the next open item — or your answer', $resultText);
        self::assertStringContainsString('asked to be asked first', $resultText);
        self::assertStringContainsString('load_tool', $resultText);
        self::assertStringContainsString('set_task_status', $resultText);
        self::assertStringNotContainsString('NOW', $resultText);
    }
}
