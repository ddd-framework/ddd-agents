<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Symfony\Commands\Agents\AgentToolResponseStatsCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The range of `app:ai-agent:tool-response-stats`: whole days from the first day's start to the last day's end, from
 * `--from` / `--to` or the last `--days` days; an unreadable day or an inverted range ends the command before any read.
 */
class AgentToolResponseStatsCommandRangeTest extends UnitTestCase
{
    public function testFromAndToGiveWholeDays(): void
    {
        $timeInterval = $this->resolveRange(['--from' => '2026-11-01', '--to' => '2026-11-03']);

        self::assertInstanceOf(TimeInterval::class, $timeInterval);
        self::assertSame('2026-11-01 00:00:00', $timeInterval->startDate?->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-03 23:59:59', $timeInterval->endDate?->format('Y-m-d H:i:s'));
    }

    public function testDaysCountBackFromTheEndOfToday(): void
    {
        $timeInterval = $this->resolveRange(['--days' => '7']);

        self::assertInstanceOf(TimeInterval::class, $timeInterval);
        self::assertSame(date('Y-m-d') . ' 23:59:59', $timeInterval->endDate?->format('Y-m-d H:i:s'));
        self::assertSame(date('Y-m-d', strtotime('-6 days')) . ' 00:00:00', $timeInterval->startDate?->format('Y-m-d H:i:s'));
    }

    public function testAnInvertedRangeEndsTheCommand(): void
    {
        $commandTester = new CommandTester(new AgentToolResponseStatsCommand());

        self::assertSame(1, $commandTester->execute(['--from' => '2026-11-05', '--to' => '2026-11-01']));
        self::assertStringContainsString('--from must not be after --to.', $commandTester->getDisplay());
    }

    public function testAnUnreadableDayEndsTheCommand(): void
    {
        $commandTester = new CommandTester(new AgentToolResponseStatsCommand());

        self::assertSame(1, $commandTester->execute(['--from' => 'yesterday-ish']));
        self::assertStringContainsString('--from/--to must be valid Y-m-d days.', $commandTester->getDisplay());
    }

    /**
     * @param array<string, string> $options
     */
    protected function resolveRange(array $options): ?TimeInterval
    {
        $agentToolResponseStatsCommand = new AgentToolResponseStatsCommand();
        $input = new ArrayInput($options, $agentToolResponseStatsCommand->getDefinition());
        $resolveRange = new ReflectionMethod(AgentToolResponseStatsCommand::class, 'resolveRange');
        return $resolveRange->invoke($agentToolResponseStatsCommand, $input, new BufferedOutput());
    }
}
