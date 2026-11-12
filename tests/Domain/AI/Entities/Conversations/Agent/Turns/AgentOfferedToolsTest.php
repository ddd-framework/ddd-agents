<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedTools;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Each of the loop's tool-set filters gives through `withoutTools` exactly the schema list the
 * bare `array_filter` gave before — same members, same order, nameless schemas kept.
 */
class AgentOfferedToolsTest extends UnitTestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildSchemas(): array
    {
        $names = ['load_skills', 'delegate', 'check_delegations', 'update_task_plan', 'set_task_status', 'askOwner',
            'get_task_plans', 'send_owner_report', 'getShop', 'updateBusinessAddress', 'load_tool'];
        $schemas = array_map(static fn (string $name): array => ['type' => 'function', 'function' => ['name' => $name]], $names);
        $schemas[] = ['type' => 'function', 'function' => []];
        return $schemas;
    }

    /**
     * The pre-7-5 filter: drop every schema whose name is in $names.
     *
     * @param array<int, array<string, mixed>> $schemas
     * @param string[] $names
     * @return array<int, array<string, mixed>>
     */
    protected function filterLikeBefore(array $schemas, array $names): array
    {
        return array_values(array_filter($schemas, static fn (array $schema): bool => !in_array($schema['function']['name'] ?? null, $names, true)));
    }

    public function testEachLoopFilterMatchesTheBareFilter(): void
    {
        $schemas = $this->buildSchemas();
        $offeredTools = AgentOfferedTools::fromToolSchemas($schemas);
        foreach ([
            [UpdateTaskPlanTool::NAME],
            [UpdateTaskPlanTool::NAME, DelegateTool::NAME],
            AgentMetaTools::FORCED_FIRST_STEP_LEFT_OUT_NAMES,
            [...AgentMetaTools::FORCED_FIRST_STEP_LEFT_OUT_NAMES, DelegateTool::NAME],
            TaskPlan::PLAN_TOOL_NAMES,
            AgentMetaTools::NOT_WORK_FOR_THE_FORCED_STEP_NAMES,
            AgentMetaTools::NOT_IN_THE_FORCED_REDRIVE_NAMES,
        ] as $names) {
            self::assertSame($this->filterLikeBefore($schemas, $names), $offeredTools->withoutTools($names)->toOpenAiToolSchemas());
        }
    }

    public function testTheSeededPurposeFilterMatchesItsFormerPredicate(): void
    {
        $schemas = $this->buildSchemas();
        foreach ([true, false] as $delegateAllowed) {
            $before = array_values(array_filter(
                $schemas,
                static fn (array $schema): bool => ($schema['function']['name'] ?? null) !== UpdateTaskPlanTool::NAME
                    && ($delegateAllowed || ($schema['function']['name'] ?? null) !== DelegateTool::NAME)
            ));
            $after = AgentOfferedTools::fromToolSchemas($schemas)
                ->withoutTools($delegateAllowed ? [UpdateTaskPlanTool::NAME] : [UpdateTaskPlanTool::NAME, DelegateTool::NAME]);
            self::assertSame($before, $after->toOpenAiToolSchemas());
        }
    }

    public function testNamesEmptinessAndMembership(): void
    {
        $offeredTools = AgentOfferedTools::fromToolSchemas($this->buildSchemas());
        self::assertSame(
            array_values(array_filter(array_map(static fn (array $schema): ?string => $schema['function']['name'] ?? null, $this->buildSchemas()))),
            $offeredTools->getToolNames()
        );
        self::assertTrue($offeredTools->offersTool('getShop'));
        self::assertFalse($offeredTools->offersTool('writeSiteBrief'));
        self::assertFalse($offeredTools->isEmpty());
        self::assertTrue(AgentOfferedTools::fromToolSchemas([])->isEmpty());
    }
}
