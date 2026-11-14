<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Symfony\Commands\Agents\AgentModelInputEvictionSimCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The eviction simulator names the pass it simulates, refuses a scenario it does not have, and plays one scenario.
 */
class AgentModelInputEvictionSimCommandTest extends UnitTestCase
{
    public function testAScenarioTheCommandDoesNotHaveIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('--scenario must be one of: steady, switch, paste, flood, interleaved, revisit, longhaul, idle, all');
        (new CommandTester(new AgentModelInputEvictionSimCommand()))->execute(['--scenario' => 'stedy']);
    }

    public function testTheOutputNamesThePerToolPassAndPlaysTheScenario(): void
    {
        $commandTester = new CommandTester(new AgentModelInputEvictionSimCommand());

        self::assertSame(0, $commandTester->execute(['--scenario' => 'switch', '--soft' => '3000', '--hard' => '5000']));
        $display = $commandTester->getDisplay();
        self::assertStringContainsString('per-tool pass (unloadGranularity: tool)', $display);
        self::assertStringContainsString('══ scenario: switch ══', $display);
        self::assertStringNotContainsString('══ scenario: flood ══', $display);
    }
}
