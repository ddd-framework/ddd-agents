<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRun;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRuns;
use DDD\Domain\AI\Entities\Conversations\Recovery\AIConversationStuckRun;
use DDD\Domain\AI\Entities\Conversations\Recovery\AIConversationStuckRuns;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The two operator lists of runs show the id of the space. An application that knows the space under its own term
 * derives the row and the set: its row carries its own id and returns it as the cell text, its set names the column.
 * The package's factories create the row through `newInstance()`, so a derived row class stays the class of the row.
 */
class RunListRowsDerivedByAnApplicationShowItsOwnIdOfTheSpaceTest extends UnitTestCase
{
    public function testAPurposedRunOfADerivedClassShowsTheApplicationsIdBelowTheApplicationsColumnTitle(): void
    {
        $purposedRun = AIConversationPurposedRunWithShopId::fromRow(['id' => 31, 'agentSpaceId' => 7, 'purposeName' => 'Example.Purpose', 'runState' => 'IDLE']);
        self::assertInstanceOf(AIConversationPurposedRunWithShopId::class, $purposedRun);
        $purposedRun->shopId = 4711;
        $purposedRuns = new AIConversationPurposedRunsWithShopColumn();
        $purposedRuns->add($purposedRun);
        $purposedRuns->totalCount = 1;

        $markdown = $purposedRuns->toLlmMarkdown();

        self::assertSame(7, $purposedRun->agentSpaceId, 'the id of the space stays on the row');
        self::assertStringContainsString('| id | purpose | shop | created |', $markdown);
        self::assertStringContainsString('| 31 | Example.Purpose | 4711 |  | IDLE |', $markdown);
    }

    public function testAPurposedRunOfThePackageClassShowsTheIdOfTheSpace(): void
    {
        $purposedRun = AIConversationPurposedRun::fromRow(['id' => 32, 'agentSpaceId' => 7, 'purposeName' => 'Example.Purpose', 'runState' => 'IDLE']);
        $purposedRuns = new AIConversationPurposedRuns();
        $purposedRuns->add($purposedRun);
        $purposedRuns->totalCount = 1;

        $markdown = $purposedRuns->toLlmMarkdown();

        self::assertStringContainsString('| id | purpose | space | created |', $markdown);
        self::assertStringContainsString('| 32 | Example.Purpose | 7 |  | IDLE |', $markdown);
    }

    public function testAStuckRunOfADerivedClassShowsTheApplicationsIdBelowTheApplicationsColumnTitle(): void
    {
        $stuckRun = AIConversationStuckRunWithShopId::forConversation(41);
        self::assertInstanceOf(AIConversationStuckRunWithShopId::class, $stuckRun);
        $stuckRun->shopId = 4711;
        $stuckRuns = new AIConversationStuckRunsWithShopColumn();
        $stuckRuns->add($stuckRun);
        $stuckRuns->addReasonToConversations(AIConversationStuckRun::REASON_NO_HEARTBEAT, [41]);
        $stuckRuns->findOrAddStuckRun(41)->applyRow(['accountId' => '91', 'agentSpaceId' => '7', 'runState' => 'RUNNING'], new DateTime('2026-11-01 12:00:00'));

        $markdown = $stuckRuns->toLlmMarkdown();

        self::assertSame(1, $stuckRuns->count(), 'the reason lands on the derived row, no second row is added');
        self::assertSame(7, $stuckRun->agentSpaceId, 'the id of the space stays on the row');
        self::assertStringContainsString('| account | shop | root |', $markdown);
        self::assertStringContainsString('| 41 | NO_HEARTBEAT |  | RUNNING | 91 | 4711 |', $markdown);
    }
}

class AIConversationPurposedRunWithShopId extends AIConversationPurposedRun
{
    public ?int $shopId = null;

    protected function getAgentSpaceCellText(): string
    {
        return (string)($this->shopId ?? '');
    }
}

class AIConversationPurposedRunsWithShopColumn extends AIConversationPurposedRuns
{
    protected const string AGENT_SPACE_COLUMN_TITLE = 'shop';
}

class AIConversationStuckRunWithShopId extends AIConversationStuckRun
{
    public ?int $shopId = null;

    protected function getAgentSpaceCellText(): string
    {
        return (string)($this->shopId ?? '');
    }
}

class AIConversationStuckRunsWithShopColumn extends AIConversationStuckRuns
{
    protected const string AGENT_SPACE_COLUMN_TITLE = 'shop';
}
