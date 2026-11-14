<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentMarkFinalizedConversationsCommand;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\LazyCommand;

/**
 * The package kernel registers the module's command from its class alone (the module compiler pass and the
 * autoconfiguration of `#[AsCommand]`), and the abstract base of the commands is no command.
 */
class PackageCommandRegistrationTest extends IntegrationTestCase
{
    public function testThePackageKernelRegistersTheModuleCommand(): void
    {
        $application = new Application(static::bootTestKernel());
        $command = $application->find('app:ai-conversation:mark-finalized');
        // the console loads a command lazily: the wrapper hands out the command it stands for
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }

        self::assertInstanceOf(AgentMarkFinalizedConversationsCommand::class, $command);
    }

    public function testTheBaseOfTheCommandsIsNoCommand(): void
    {
        $application = new Application(static::bootTestKernel());

        foreach ($application->all() as $command) {
            self::assertNotSame(AgentConsoleCommand::class, ($command instanceof LazyCommand ? $command->getCommand() : $command)::class);
        }
        self::assertFalse(static::getContainer()->has(AgentConsoleCommand::class));
    }
}
