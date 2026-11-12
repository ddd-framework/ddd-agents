<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallFork;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;
use LogicException;
use ReflectionProperty;

/**
 * The module ships no console command: a tool call runs in a process of its own only when the application declared
 * its console script and its command in `config/app/AI/agentToolCallChildProcess.php`.
 */
class AgentToolCallForkJoinServiceChildProcessCommandTest extends UnitTestCase
{
    /** @var array<string, mixed>|null the config values read so far, restored after every test */
    protected ?array $configTreeBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $this->configTreeBefore = $configTree->isInitialized() ? $configTree->getValue() : null;
    }

    protected function tearDown(): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configTree->setValue(null, $this->configTreeBefore ?? []);
        parent::tearDown();
    }

    public function testThePackageDeclaresNoCommandAndForksNothing(): void
    {
        /** @var AgentToolCallForkJoinService $agentToolCallForkJoinService */
        $agentToolCallForkJoinService = self::instantiateWithoutConstructor(AgentToolCallForkJoinService::class);
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);

        self::assertNull($agentToolCallForkJoinService->getChildProcessConsoleScript());
        self::assertNull($agentToolCallForkJoinService->getChildProcessCommandName());
        self::assertFalse($agentToolCallForkJoinService->isChildProcessCommandDeclared());
        self::assertSame([], $agentToolCallForkJoinService->selectForkableToolCalls([new ToolCall(), new ToolCall()], $conversation));
    }

    public function testStartingChildrenWithoutADeclaredCommandIsRefused(): void
    {
        /** @var AgentToolCallForkJoinService $agentToolCallForkJoinService */
        $agentToolCallForkJoinService = self::instantiateWithoutConstructor(AgentToolCallForkJoinService::class);
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);

        $this->expectException(LogicException::class);
        $agentToolCallForkJoinService->startChildren(new AgentToolCallFork(), $conversation);
    }

    public function testTheCommandOfTheApplicationIsReadFromItsConfigFile(): void
    {
        $this->declareChildProcessCommandOfTheApplication(['consoleScript' => '/bin/console-of-the-shop', 'commandName' => ' shop:agent:execute-tool-call ']);
        /** @var AgentToolCallForkJoinService $agentToolCallForkJoinService */
        $agentToolCallForkJoinService = self::instantiateWithoutConstructor(AgentToolCallForkJoinService::class);

        self::assertSame('bin/console-of-the-shop', $agentToolCallForkJoinService->getChildProcessConsoleScript());
        self::assertSame('shop:agent:execute-tool-call', $agentToolCallForkJoinService->getChildProcessCommandName());
        self::assertTrue($agentToolCallForkJoinService->isChildProcessCommandDeclared());
    }

    public function testAnApplicationThatDeclaresOnlyOneOfTheTwoForksNothing(): void
    {
        $this->declareChildProcessCommandOfTheApplication(['consoleScript' => 'bin/console-of-the-shop', 'commandName' => null]);
        /** @var AgentToolCallForkJoinService $agentToolCallForkJoinService */
        $agentToolCallForkJoinService = self::instantiateWithoutConstructor(AgentToolCallForkJoinService::class);

        self::assertFalse($agentToolCallForkJoinService->isChildProcessCommandDeclared());
    }

    /**
     * Writes the application's declaration where {@see Config::get()} reads it first: the values it has already read.
     *
     * @param array<string, string|null> $childProcessCommandOfTheApplication
     */
    protected function declareChildProcessCommandOfTheApplication(array $childProcessCommandOfTheApplication): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? $configTree->getValue() : [];
        $configValues['AI']['agentToolCallChildProcess'] = $childProcessCommandOfTheApplication;
        $configTree->setValue(null, $configValues);
    }
}
