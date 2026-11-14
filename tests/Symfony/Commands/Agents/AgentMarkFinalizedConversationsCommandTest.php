<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentMarkFinalizedConversationsCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The first command of the module keeps the name and options production cron rows call it with, and runs on the
 * shared base.
 */
class AgentMarkFinalizedConversationsCommandTest extends UnitTestCase
{
    public function testTheCommandKeepsItsNameAndOptions(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentMarkFinalizedConversationsCommand::class))->getAttributes(AsCommand::class);
        self::assertCount(1, $asCommandAttributes);
        self::assertSame('app:ai-conversation:mark-finalized', $asCommandAttributes[0]->newInstance()->name);

        $agentMarkFinalizedConversationsCommand = new AgentMarkFinalizedConversationsCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $agentMarkFinalizedConversationsCommand);
        self::assertSame('app:ai-conversation:mark-finalized', $agentMarkFinalizedConversationsCommand->getName());
        $commandDefinition = $agentMarkFinalizedConversationsCommand->getDefinition();
        self::assertSame((string)AgentMarkFinalizedConversationsCommand::DEFAULT_IDLE_MINUTES, $commandDefinition->getOption('idle-minutes')->getDefault());
        self::assertTrue($commandDefinition->hasOption('dry-run'));
        self::assertFalse($commandDefinition->hasOption(AgentConsoleCommand::OPTION_IGNORE_BUDGET), 'the sweep makes no model call');
    }

    public function testAnIdleWindowThatIsNoWholeNumberIsRefusedBeforeAnyRead(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage("--idle-minutes must be a positive whole number of minutes, got 'soon'");
        (new CommandTester(new AgentMarkFinalizedConversationsCommand()))->execute(['--idle-minutes' => 'soon']);
    }

    public function testANegativeIdleWindowIsRefusedBeforeAnyRead(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage("--idle-minutes must be a positive whole number of minutes, got '-5'");
        (new CommandTester(new AgentMarkFinalizedConversationsCommand()))->execute(['--idle-minutes' => '-5']);
    }

    public function testAZeroIdleWindowIsRefusedBeforeAnyRead(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage("got '0'");
        (new CommandTester(new AgentMarkFinalizedConversationsCommand()))->execute(['--idle-minutes' => '0']);
    }
}
