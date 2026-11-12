<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\Maintenance\AIConversationLoadedCapabilitiesRewriteOutcome;
use DDD\Domain\AI\Entities\Conversations\Messages\Maintenance\AIConversationLoadedCapabilitiesRewriteReport;
use DDD\Domain\AI\Entities\Conversations\Messages\Maintenance\AIConversationRequestSnapshotDebugPayloadRemovalReport;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * The two background maintenance runs of {@see AIConversationMessagesService} against the database, on both engines:
 * the removal of the debug payloads from old request snapshots and the rewrite of legacy capability rows into the
 * chain format. Each write is a short transaction with a 2-second wait for a row lock; a row another session holds is
 * skipped, never waited on, and the session's limits are back to the server's afterwards.
 */
class AIConversationMessagesBackgroundMaintenanceDatabaseTest extends DatabaseTestCase
{
    protected const int CONVERSATION_ID = 100;

    /** A second session on the same database: a live writer that holds a row */
    protected ?Connection $connectionOfALiveWriter = null;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_ID]);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        $connection = $this->getDatabaseConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        if ($this->connectionOfALiveWriter !== null) {
            while ($this->connectionOfALiveWriter->isTransactionActive()) {
                $this->connectionOfALiveWriter->rollBack();
            }
            $this->connectionOfALiveWriter->close();
            $this->connectionOfALiveWriter = null;
        }
    }

    public function testTheDebugPayloadsLeaveOldRequestSnapshotsAndTheRestOfTheSnapshotStays(): void
    {
        $messagesService = $this->getMessagesService();
        $debugPayload = str_repeat('a request payload ', 400);
        $this->insertSnapshotRow(901, 0, $this->buildDaysAgo(40), ['sentAt' => '2026-01-01 10:00:00', 'model' => 'a-model', 'sentPayloadJson' => $debugPayload, 'rawResponseJson' => '{"answer":"text"}']);
        $this->insertSnapshotRow(902, 1, $this->buildDaysAgo(39), ['sentAt' => '2026-01-01 10:00:01', 'model' => 'a-model']);
        $this->insertSnapshotRow(903, 2, $this->buildDaysAgo(38), ['sentAt' => '2026-01-01 10:00:02', 'note' => $debugPayload]);
        $this->insertSnapshotRow(904, 3, $this->buildDaysAgo(37), null);
        $this->insertSnapshotRow(905, 4, $this->buildDaysAgo(1), ['sentAt' => '2026-01-01 10:00:03', 'sentPayloadJson' => $debugPayload]);

        $dryRunReport = $this->removeDebugPayloads($messagesService, true);
        self::assertSame(904, $dryRunReport->cutoffMessageId, 'the newest row older than the cutoff');
        self::assertSame(1, $dryRunReport->rowsWithPayload, 'a long snapshot without a payload key is not one');
        self::assertSame(0, $dryRunReport->rowsStripped);
        self::assertArrayHasKey('sentPayloadJson', $this->findStoredSnapshot(901));

        $report = $this->removeDebugPayloads($messagesService, false);

        self::assertSame(1, $report->rowsStripped);
        self::assertSame(1, $report->writes);
        self::assertSame(0, $report->chunksSkippedOnLock);
        self::assertSame(AIConversationRequestSnapshotDebugPayloadRemovalReport::STOPPED_BECAUSE_REACHED_THE_CUTOFF, $report->stoppedBecause);
        self::assertSame(['cutoffMessageId', 'fromMessageId', 'lastScannedMessageId', 'windowsScanned', 'rowsWithPayload', 'rowsStripped', 'chunksSkippedOnLock', 'pausesForServerLoad', 'writes', 'averageWriteMs', 'slowestWriteMs', 'stoppedBecause'], array_keys($report->toReportFields()));
        self::assertEquals(['sentAt' => '2026-01-01 10:00:00', 'model' => 'a-model'], $this->findStoredSnapshot(901));
        self::assertArrayHasKey('note', $this->findStoredSnapshot(903));
        self::assertArrayHasKey('sentPayloadJson', $this->findStoredSnapshot(905), 'a snapshot younger than the cutoff keeps its payload');
        self::assertSame(0, $this->removeDebugPayloads($messagesService, false)->rowsWithPayload, 'a stripped snapshot is not found again');
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
        $this->assertSessionWaitsForRowLocksAsTheServerDoes();
    }

    public function testASnapshotRowALiveWriterHoldsIsSkippedAndStrippedByALaterRun(): void
    {
        $messagesService = $this->getMessagesService();
        $this->insertSnapshotRow(901, 0, $this->buildDaysAgo(40), ['sentAt' => '2026-01-01 10:00:00', 'sentPayloadJson' => str_repeat('a request payload ', 400)]);
        $this->holdRowAsALiveWriter(901);

        $reportWhileTheRowIsHeld = $this->removeDebugPayloads($messagesService, false);

        self::assertSame(1, $reportWhileTheRowIsHeld->rowsWithPayload);
        self::assertSame(1, $reportWhileTheRowIsHeld->chunksSkippedOnLock);
        self::assertSame(0, $reportWhileTheRowIsHeld->rowsStripped);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive(), 'the skipped write left no transaction open');

        $this->getConnectionOfALiveWriter()->rollBack();

        self::assertSame(1, $this->removeDebugPayloads($messagesService, false)->rowsStripped);
        self::assertArrayNotHasKey('sentPayloadJson', $this->findStoredSnapshot(901));
        $this->assertSessionWaitsForRowLocksAsTheServerDoes();
    }

    public function testLegacyCapabilityRowsAreRewrittenIntoChangeRowsAndResolveToTheSameSets(): void
    {
        $messagesService = $this->getMessagesService();
        $skillEntry = ['name' => 'Seo.Keywords', 'type' => LoadedCapability::TYPE_SKILL, 'residentTokens' => 500];
        $firstToolEntry = ['name' => 'getKeywords', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Seo.Keywords'], 'residentTokens' => 200];
        $secondToolEntry = ['name' => 'saveKeywords', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Seo.Keywords'], 'residentTokens' => 300];
        $this->insertCapabilityRow(601, 0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, null);
        $this->insertCapabilityRow(602, 1, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, [$skillEntry]);
        $this->insertCapabilityRow(603, 2, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, [$skillEntry, $firstToolEntry]);
        $this->insertCapabilityRow(604, 3, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, [$skillEntry, $firstToolEntry, $secondToolEntry]);
        $legacyCheckpoint = $this->findStoredLoadedCapabilities(602);

        $dryRunOutcome = $messagesService->compactLoadedCapabilitiesOfConversation(self::CONVERSATION_ID, 0, true);
        self::assertSame(2, $dryRunOutcome->rowsRewritten, 'the first row of the turn stays a checkpoint');
        self::assertFalse($this->buildStoredSet(603)->isChangeRecord(), 'a dry run writes nothing');

        $this->holdRowAsALiveWriter(603);
        self::assertTrue($messagesService->compactLoadedCapabilitiesOfConversation(self::CONVERSATION_ID, 0, false)->isConflict(), 'a row a live writer holds');
        self::assertFalse($this->buildStoredSet(603)->isChangeRecord());
        $this->getConnectionOfALiveWriter()->rollBack();

        $outcome = $messagesService->compactLoadedCapabilitiesOfConversation(self::CONVERSATION_ID, 0, false, true);

        self::assertSame(AIConversationLoadedCapabilitiesRewriteOutcome::OUTCOME_DONE, $outcome->outcome);
        self::assertSame(2, $outcome->rowsRewritten);
        self::assertLessThan($outcome->bytesBefore, $outcome->bytesAfter);
        self::assertSame(3, $outcome->verifiedRows);
        self::assertSame(0, $outcome->verifyMismatches, 'every row resolves to the set it stored before');
        self::assertSame($legacyCheckpoint, $this->findStoredLoadedCapabilities(602));
        self::assertTrue($this->buildStoredSet(603)->isChangeRecord());
        self::assertTrue($this->buildStoredSet(604)->isChangeRecord());
        self::assertSame(0, $messagesService->compactLoadedCapabilitiesOfConversation(self::CONVERSATION_ID, 0, false)->rowsRewritten, 'a change row is never rewritten');
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
        $this->assertSessionWaitsForRowLocksAsTheServerDoes();
    }

    public function testTheCapabilityRewriteWalksTheConversationsAndLeavesOneWithARecentMessageAlone(): void
    {
        $messagesService = $this->getMessagesService();
        $skillEntry = ['name' => 'Seo.Keywords', 'type' => LoadedCapability::TYPE_SKILL, 'residentTokens' => 500];
        $toolEntry = ['name' => 'getKeywords', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Seo.Keywords'], 'residentTokens' => 200];
        $this->insertCapabilityRow(602, 0, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, [$skillEntry]);
        $this->insertCapabilityRow(603, 1, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, [$skillEntry, $toolEntry]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 101]);
        $this->insertCapabilityRow(702, 0, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, [$skillEntry], 101, $this->buildDaysAgo(0));
        $this->insertCapabilityRow(703, 1, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, [$skillEntry, $toolEntry], 101, $this->buildDaysAgo(0));

        $report = $messagesService->compactLoadedCapabilities(10, 0, 1000, 30, 100000, 60, false, 0);

        self::assertSame(1, $report->conversationsCompacted);
        self::assertSame(1, $report->conversationsSkippedActive, 'a message of the last hour');
        self::assertSame(1, $report->rowsRewritten);
        self::assertSame(101, $report->lastConversationId);
        self::assertSame(AIConversationLoadedCapabilitiesRewriteReport::STOPPED_BECAUSE_NO_CONVERSATION_LEFT, $report->stoppedBecause);
        self::assertSame(1, $report->toReportFields()['conversationsCompacted']);
        self::assertTrue($this->buildStoredSet(603)->isChangeRecord());
        self::assertFalse($this->buildStoredSet(703)->isChangeRecord());
    }

    public function testTheBusyServerGuardReadsMariaDbsCountersAndAnswersNotBusyElsewhere(): void
    {
        $messagesService = $this->getMessagesService();

        self::assertFalse($messagesService->isDatabaseTooBusyForBackgroundWrites(100000, PHP_INT_MAX));
        self::assertSame(!$this->isPostgres(), $messagesService->isDatabaseTooBusyForBackgroundWrites(-1, PHP_INT_MAX), 'this very statement is running');
    }

    protected function getMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /**
     * One run over every row older than 30 days, from the first id on, without a pause.
     *
     * @return array<string, int|string|null>
     */
    protected function removeDebugPayloads(AIConversationMessagesService $messagesService, bool $dryRun): AIConversationRequestSnapshotDebugPayloadRemovalReport
    {
        return $messagesService->removeDebugPayloadsFromRequestSnapshots(30, 100, 2, 0, 1000, 30, 100000, PHP_INT_MAX, $dryRun, 0);
    }

    /** The moment `$days` days before now, as the `created` column stores it. */
    protected function buildDaysAgo(int $days): string
    {
        return date('Y-m-d H:i:s', time() - $days * 86400 - 60);
    }

    /** @param array<string, mixed>|null $requestSnapshot The document of the row's `requestSnapshot` column */
    protected function insertSnapshotRow(int $messageId, int $indexInConversation, string $created, ?array $requestSnapshot): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => self::CONVERSATION_ID,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => 'an answer',
            'created' => $created,
            'requestSnapshot' => $requestSnapshot === null ? null : json_encode($requestSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * A row that stores its complete capability set (the legacy format), or none.
     *
     * @param array<int, array<string, mixed>>|null $canonicalEntries The capabilities of the row's set
     */
    protected function insertCapabilityRow(
        int $messageId,
        int $indexInConversation,
        string $role,
        string $authorType,
        ?array $canonicalEntries,
        int $conversationId = self::CONVERSATION_ID,
        string $created = '2026-01-01 00:00:00'
    ): void {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'role' => $role,
            'authorType' => $authorType,
            'message' => 'text of row ' . $messageId,
            'created' => $created,
            'loadedCapabilities' => $canonicalEntries === null ? null : (string)LoadedCapabilities::fromCanonicalEntries($canonicalEntries)->mapToRepository(),
        ]);
    }

    /** @return array<string, mixed> The decoded `requestSnapshot` document of the stored message row */
    protected function findStoredSnapshot(int $messageId): array
    {
        $storedMessage = $this->findStoredRow(DBAIConversationMessageModel::getTableName(), $messageId);
        self::assertNotNull($storedMessage, 'message ' . $messageId . ' is stored');
        return json_decode((string)$storedMessage['requestSnapshot'], true, 512, JSON_THROW_ON_ERROR);
    }

    protected function findStoredLoadedCapabilities(int $messageId): string
    {
        $storedMessage = $this->findStoredRow(DBAIConversationMessageModel::getTableName(), $messageId);
        self::assertNotNull($storedMessage, 'message ' . $messageId . ' is stored');
        return (string)$storedMessage['loadedCapabilities'];
    }

    /** The capability set the stored row carries, as its column decodes: a complete set or a change record. */
    protected function buildStoredSet(int $messageId): LoadedCapabilities
    {
        $storedSet = new LoadedCapabilities();
        $storedSet->mapFromRepository($this->findStoredLoadedCapabilities($messageId));
        return $storedSet;
    }

    protected function getConnectionOfALiveWriter(): Connection
    {
        if ($this->connectionOfALiveWriter === null) {
            $connection = $this->getDatabaseConnection();
            $this->connectionOfALiveWriter = DriverManager::getConnection($connection->getParams(), $connection->getConfiguration());
        }
        return $this->connectionOfALiveWriter;
    }

    /** Another session locks the message row in a transaction it keeps open. */
    protected function holdRowAsALiveWriter(int $messageId): void
    {
        $connectionOfALiveWriter = $this->getConnectionOfALiveWriter();
        $connectionOfALiveWriter->beginTransaction();
        $connectionOfALiveWriter->fetchOne('SELECT `id` FROM `' . DBAIConversationMessageModel::getTableName() . '` WHERE `id` = ? FOR UPDATE', [$messageId]);
    }

    /** After a maintenance run the session waits for a row lock as long as the server's default says (MariaDB keeps the limit on the session). */
    protected function assertSessionWaitsForRowLocksAsTheServerDoes(): void
    {
        if ($this->isPostgres()) {
            self::assertSame('0', (string)$this->getDatabaseConnection()->fetchOne("SELECT current_setting('lock_timeout')"), 'no limit outlives its transaction');
            return;
        }
        self::assertSame(
            (int)$this->getDatabaseConnection()->fetchOne('SELECT @@GLOBAL.innodb_lock_wait_timeout'),
            (int)$this->getDatabaseConnection()->fetchOne('SELECT @@SESSION.innodb_lock_wait_timeout')
        );
    }
}
