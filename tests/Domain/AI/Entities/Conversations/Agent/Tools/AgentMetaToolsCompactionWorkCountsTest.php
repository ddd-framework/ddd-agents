<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionToolTelemetry;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * {@see AgentMetaTools::isExcludedFromCompactionWorkCounts()}
 * answers exactly what {@see AIConversationCompactionToolTelemetry::isMetaToolName()} answers today, for every meta
 * tool and the non-member cases of the compaction characterization.
 */
class AgentMetaToolsCompactionWorkCountsTest extends UnitTestCase
{
    /**
     * `Wait_for_sub_agents` is the eighth member (a turn end without a text is no work);
     * `message_sub_agent` the ninth (a message to a sub-agent, like `delegate`, is orchestration, not work);
     * `findWidgets` the tenth (a widget catalog search is no work).
     */
    public function testTheListHoldsTodaysTenMembers(): void
    {
        self::assertSame(
            ['load_skills', 'load_tool', 'delegate', 'check_delegations', 'askOwner', 'wait_for_sub_agents', 'message_sub_agent', 'findWidgets', 'update_task_plan', 'set_task_status'],
            AgentMetaTools::EXCLUDED_FROM_COMPACTION_WORK_COUNTS_NAMES
        );
    }

    /** @return array<string, array{0: string}> */
    public static function probeToolNames(): array
    {
        $probeToolNames = [];
        foreach (AgentMetaTools::TOOL_CLASSES as $metaToolClass) {
            $probeToolNames['meta tool ' . $metaToolClass::NAME] = [$metaToolClass::NAME];
        }
        foreach (['getProfileSummary', 'updateProfile', 'remember', 'Load_Skills', 'askowner', 'ask_owner', 'check_delegation', ''] as $otherToolName) {
            $probeToolNames['other name "' . $otherToolName . '"'] = [$otherToolName];
        }
        return $probeToolNames;
    }

    #[DataProvider('probeToolNames')]
    public function testTheAnswerEqualsTheTelemetrysMetaToolQuestion(string $toolName): void
    {
        self::assertSame(
            AIConversationCompactionToolTelemetry::isMetaToolName($toolName),
            AgentMetaTools::isExcludedFromCompactionWorkCounts($toolName)
        );
    }

    public function testTheTwoMetaToolsCountedAsWorkStayOutside(): void
    {
        self::assertFalse(AgentMetaTools::isExcludedFromCompactionWorkCounts('get_task_plans'));
        self::assertFalse(AgentMetaTools::isExcludedFromCompactionWorkCounts('send_owner_report'));
    }
}
