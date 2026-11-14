<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Commands;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The module's mark sweep, started through the console of the package kernel, counts with `--dry-run` and marks
 * without it — on MariaDB and on PostgreSQL.
 */
class AgentMarkFinalizedConversationsCommandDatabaseTest extends DatabaseTestCase
{
    public function testTheSweepCountsWithDryRunAndMarksWithoutIt(): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 100, 'runState' => AIConversationRunState::IDLE, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(7200)]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 101, 'runState' => AIConversationRunState::RUNNING, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(7200)]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 102, 'runState' => AIConversationRunState::IDLE, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(60)]);

        $dryRunTester = $this->runSweep(['--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $dryRunTester->getStatusCode());
        self::assertStringContainsString('dry-run: 1 conversation(s) would be finalized (idle ≥ 60 min)', $dryRunTester->getDisplay());
        self::assertNull($this->findStoredRow(DBAIConversationModel::getTableName(), 100)['finalizedAt'] ?? null);

        $markTester = $this->runSweep([]);
        self::assertSame(Command::SUCCESS, $markTester->getStatusCode());
        self::assertStringContainsString('finalized 1 conversation(s)', $markTester->getDisplay());
        self::assertNotNull($this->findStoredRow(DBAIConversationModel::getTableName(), 100)['finalizedAt'] ?? null);
        self::assertNull($this->findStoredRow(DBAIConversationModel::getTableName(), 101)['finalizedAt'] ?? null);
        self::assertNull($this->findStoredRow(DBAIConversationModel::getTableName(), 102)['finalizedAt'] ?? null);

        self::assertStringContainsString('finalized 0 conversation(s)', $this->runSweep([])->getDisplay(), 'a finalized conversation is not marked twice');
    }

    public function testTheIdleWindowOptionNarrowsTheSweep(): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 100, 'runState' => AIConversationRunState::IDLE, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(1800)]);

        self::assertStringContainsString('dry-run: 0 conversation(s)', $this->runSweep(['--dry-run' => true])->getDisplay());
        self::assertStringContainsString('dry-run: 1 conversation(s) would be finalized (idle ≥ 20 min)', $this->runSweep(['--dry-run' => true, '--idle-minutes' => '20'])->getDisplay());
    }

    /** @param array<string, string|bool> $commandInput */
    protected function runSweep(array $commandInput): CommandTester
    {
        $application = new Application(static::bootTestKernel());
        $commandTester = new CommandTester($application->find('app:ai-conversation:mark-finalized'));
        $commandTester->execute($commandInput);
        return $commandTester;
    }

    protected function formatMomentSecondsAgo(int $seconds): string
    {
        return (new DateTime())->modify('-' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }
}
