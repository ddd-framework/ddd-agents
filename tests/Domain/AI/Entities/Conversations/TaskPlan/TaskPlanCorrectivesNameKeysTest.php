<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentCheckedStopPolicy;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The correctives that tell the model to tick name each item by the KEY `set_task_status` resolves:
 * the checked-stop bounce lists the owed open items as `key 'text'` (the `"task":"<number>"` example beside it can be
 * filled from the list; an awaiting offer stays out of it, as before), and the resend that reopened a closed item names
 * the item's key and the call to re-tick it.
 */
class TaskPlanCorrectivesNameKeysTest extends UnitTestCase
{
    public function testTheCheckedStopBounceListsTheOwedOpenItemsByKey(): void
    {
        $taskPlan = TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'Answer the reviews', 'key' => '2', 'status' => TaskPlanItem::STATUS_OPEN, 'highestSubtaskKeyNumber' => 3, 'subtasks' => [
                ['text' => 'Read them', 'key' => '2.1', 'status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'read'],
                ['text' => 'Thank the 5-star reviewers', 'key' => '2.3', 'status' => TaskPlanItem::STATUS_OPEN],
                ['text' => 'Draft the replies', 'key' => '2.2', 'status' => TaskPlanItem::STATUS_OPEN],
            ]],
            // rendered and waiting for the owner's click: open, but no remaining work of the agent
            ['text' => 'Offer the connect widget', 'key' => '3', 'status' => TaskPlanItem::STATUS_OPEN, 'doneRequiresWidgetAnswered' => true, 'doneRequiresWidget' => 'ChannelConnections', 'offerRenderedAtMessageIndex' => 4],
        ], 'highestItemKeyNumber' => 3]);

        $bounceText = AgentCheckedStopPolicy::buildCheckedStopBounceText($taskPlan);

        self::assertStringContainsString("Items you tick: 2.3 'Thank the 5-star reviewers'; 2.2 'Draft the replies'. For each, use set_task_status", $bounceText);
        self::assertStringNotContainsString('close themselves', $bounceText, 'no item here closes itself');
        self::assertStringContainsString('{"updates":[{"task":"<number>","status":"done"', $bounceText);
        self::assertStringNotContainsString('Offer the connect widget', $bounceText);
        self::assertStringNotContainsString('Answer the reviews > ', $bounceText, 'no text path any more');
    }

}
