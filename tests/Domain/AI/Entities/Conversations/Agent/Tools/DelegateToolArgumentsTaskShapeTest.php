<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAgentTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateToolArguments;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The task of one `delegate` call: a call that names `toolName` is a tool run — a model that describes the same work
 * in `task` as well had been refused and then ran the tool itself; a `task` alone is an agent task; neither is refused.
 */
class DelegateToolArgumentsTaskShapeTest extends UnitTestCase
{
    public function testACallNamingAToolAndATaskTextIsTheToolRun(): void
    {
        $delegateToolArguments = DelegateToolArguments::fromToolCallArguments([
            'label' => 'Generate three practice logos',
            'task' => 'Generate three different logo candidates for the dental practice.',
            'skill_names' => ['Media.Images'],
            'toolName' => 'generateLogos',
            'toolArguments' => ['brandName' => 'Zahnarztpraxis Ellwangen', 'variants' => 3],
        ]);

        self::assertInstanceOf(AgentDelegationToolRunTask::class, $delegateToolArguments->task);
        self::assertSame('generateLogos', $delegateToolArguments->task->toolName);
        self::assertSame(['brandName' => 'Zahnarztpraxis Ellwangen', 'variants' => 3], $delegateToolArguments->task->toolArguments);
        self::assertTrue($delegateToolArguments->namesBothTaskShapes);
        self::assertNull($delegateToolArguments->buildTaskShapeCorrective());
        self::assertSame('Generate three practice logos', $delegateToolArguments->label);
    }

    public function testAToolNameAloneIsAToolRunAndATaskAloneAnAgentTask(): void
    {
        $toolRunArguments = DelegateToolArguments::fromToolCallArguments(['toolName' => 'getWebPageAsMarkdown', 'toolArguments' => '{"url": "https://example.org"}']);
        self::assertInstanceOf(AgentDelegationToolRunTask::class, $toolRunArguments->task);
        self::assertSame(['url' => 'https://example.org'], $toolRunArguments->task->toolArguments);
        self::assertFalse($toolRunArguments->namesBothTaskShapes);

        $agentTaskArguments = DelegateToolArguments::fromToolCallArguments(['task' => 'Read the site and profile it.', 'skill_names' => ['Web.Search']]);
        self::assertInstanceOf(AgentDelegationAgentTask::class, $agentTaskArguments->task);
        self::assertSame('Read the site and profile it.', $agentTaskArguments->task->taskText);
        self::assertSame(['Web.Search'], $agentTaskArguments->task->skillNames);
        self::assertFalse($agentTaskArguments->namesBothTaskShapes);
    }

    public function testACallWithNeitherShapeIsRefusedAndABlankToolNameIsNoToolRun(): void
    {
        $shapelessArguments = DelegateToolArguments::fromToolCallArguments(['label' => 'Weather']);
        self::assertFalse($shapelessArguments->hasTask());
        self::assertStringStartsWith('delegate REJECTED — the call names no task', (string)$shapelessArguments->buildTaskShapeCorrective());

        $blankToolNameArguments = DelegateToolArguments::fromToolCallArguments(['toolName' => '  ', 'task' => 'Read the site.']);
        self::assertInstanceOf(AgentDelegationAgentTask::class, $blankToolNameArguments->task);
        self::assertFalse($blankToolNameArguments->namesBothTaskShapes);
    }
}
