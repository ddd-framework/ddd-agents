<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Mandates;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Repo\DB\Mandates\DBAgentMandateModel;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The raw statements of {@see AgentMandatesService} against the database, on both engines: the expiry of a time window
 * and of a one-instruction mandate left standing for a day (the PHP clock as a bound parameter), the close-out of
 * the one-instruction mandates of earlier requests, and the three compare-and-set writes of a one-instruction mandate
 * (bind to the first tool, release the binding of a failed call, consume) whose answer comes from the affected-rows
 * count — the same answer on MariaDB (changed rows) and PostgreSQL (matched rows).
 */
class AgentMandatesServiceDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 11;

    protected const int OTHER_ACCOUNT_ID = 12;

    protected const int AGENT_SPACE_ID = 21;

    protected AgentMandatesService $agentMandatesService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        /** @var AgentMandatesService $agentMandatesService */
        $agentMandatesService = DDDService::instance()->getService(AgentMandatesService::class);
        $this->agentMandatesService = $agentMandatesService;
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'first@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::OTHER_ACCOUNT_ID, 'email' => 'second@example.test']);
        $this->insertAgentSpaceRow(['id' => self::AGENT_SPACE_ID, 'accountId' => self::ACCOUNT_ID]);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testATimeWindowThatPassedExpiresAndAOneInstructionMandateEndsADayAfterItsGrant(): void
    {
        $this->insertMandate(1, AgentMandate::SCOPE_TIME_WINDOW, ['validUntil' => $this->formatMomentSecondsAgo(60)]);
        $this->insertMandate(2, AgentMandate::SCOPE_TIME_WINDOW, ['validUntil' => $this->formatMomentSecondsAgo(-3600)]);
        $this->insertMandate(3, AgentMandate::SCOPE_ONE_INSTRUCTION, ['validFrom' => $this->formatMomentSecondsAgo(25 * 3600)]);
        $this->insertMandate(4, AgentMandate::LEGACY_SCOPE_ONE_SHOT, ['validFrom' => $this->formatMomentSecondsAgo(25 * 3600)]);
        $this->insertMandate(5, AgentMandate::SCOPE_ONE_INSTRUCTION, ['validFrom' => $this->formatMomentSecondsAgo(23 * 3600)]);
        $this->insertMandate(6, AgentMandate::SCOPE_TIME_WINDOW, ['validUntil' => $this->formatMomentSecondsAgo(60), 'accountId' => self::OTHER_ACCOUNT_ID]);
        $this->insertMandate(7, AgentMandate::SCOPE_UNLIMITED, ['validFrom' => $this->formatMomentSecondsAgo(400 * 86400)]);

        $this->agentMandatesService->markExpiredMandates(self::ACCOUNT_ID);

        self::assertSame(AgentMandate::STATUS_EXPIRED, $this->findStoredStatus(1));
        self::assertSame(AgentMandate::STATUS_ACTIVE, $this->findStoredStatus(2));
        self::assertSame(AgentMandate::STATUS_CONSUMED, $this->findStoredStatus(3));
        self::assertSame(AgentMandate::STATUS_CONSUMED, $this->findStoredStatus(4));
        self::assertSame(AgentMandate::STATUS_ACTIVE, $this->findStoredStatus(5));
        self::assertSame(AgentMandate::STATUS_ACTIVE, $this->findStoredStatus(6), 'another account is not touched');
        self::assertSame(AgentMandate::STATUS_ACTIVE, $this->findStoredStatus(7));

        $this->agentMandatesService->markExpiredMandates();
        self::assertSame(AgentMandate::STATUS_EXPIRED, $this->findStoredStatus(6), 'without an account every account');
    }

    public function testTheGateFindsTheNewestValidMandateAfterTheExpiryAndTheListShowsEveryStatus(): void
    {
        $this->insertMandate(1, AgentMandate::SCOPE_UNLIMITED, ['agentSpaceId' => null]);
        $this->insertMandate(2, AgentMandate::SCOPE_TIME_WINDOW, ['agentSpaceId' => self::AGENT_SPACE_ID, 'validUntil' => $this->formatMomentSecondsAgo(60)]);
        $this->insertMandate(3, AgentMandate::SCOPE_UNLIMITED, ['agentSpaceId' => self::AGENT_SPACE_ID]);

        $agentSpace = new AgentSpace();
        $agentSpace->id = self::AGENT_SPACE_ID;
        self::assertSame(3, $this->agentMandatesService->findActiveMandateFor(self::ACCOUNT_ID, $agentSpace, AgentMandate::ACTION_CLASSES[0])?->id);
        self::assertSame(1, $this->agentMandatesService->findActiveMandateFor(self::ACCOUNT_ID, null, AgentMandate::ACTION_CLASSES[0])?->id, 'at account level only an account-wide mandate');
        self::assertSame(AgentMandate::STATUS_EXPIRED, $this->findStoredStatus(2));
        self::assertSame([3, 2, 1], $this->getIds($this->agentMandatesService->findMandatesForAccount(self::ACCOUNT_ID)->getElements()));
    }

    public function testANewRequestClosesTheOneInstructionMandatesOfEarlierRequestsOfTheSameConversation(): void
    {
        $this->insertMandate(1, AgentMandate::SCOPE_ONE_INSTRUCTION, ['sourceConversationId' => 100, 'sourceUserMessageId' => 500]);
        $this->insertMandate(2, AgentMandate::SCOPE_ONE_INSTRUCTION, ['sourceConversationId' => 100, 'sourceUserMessageId' => 510]);
        $this->insertMandate(3, AgentMandate::SCOPE_ONE_INSTRUCTION, ['sourceConversationId' => 101, 'sourceUserMessageId' => 500]);
        $this->insertMandate(4, AgentMandate::SCOPE_UNLIMITED, ['sourceConversationId' => 100, 'sourceUserMessageId' => 500]);
        $this->insertMandate(5, AgentMandate::LEGACY_SCOPE_ONE_SHOT, ['sourceConversationId' => 100, 'sourceUserMessageId' => 490]);

        $this->agentMandatesService->closeOutOneInstructionMandatesFromEarlierRequests(self::ACCOUNT_ID, 100, 510);

        self::assertSame(AgentMandate::STATUS_CONSUMED, $this->findStoredStatus(1));
        self::assertSame(AgentMandate::STATUS_ACTIVE, $this->findStoredStatus(2), 'the mandate of the current request stays');
        self::assertSame(AgentMandate::STATUS_ACTIVE, $this->findStoredStatus(3), 'another conversation is not touched');
        self::assertSame(AgentMandate::STATUS_ACTIVE, $this->findStoredStatus(4), 'only one-instruction mandates close');
        self::assertSame(AgentMandate::STATUS_CONSUMED, $this->findStoredStatus(5));
    }

    public function testAOneInstructionMandateIsBoundToItsFirstToolReleasedByAFailedCallAndConsumedOnce(): void
    {
        $this->insertMandate(1, AgentMandate::SCOPE_ONE_INSTRUCTION);
        $agentMandate = $this->agentMandatesService->find(1, false);

        self::assertTrue($this->agentMandatesService->bindOneInstructionMandateToTool($agentMandate, 'deletePost'));
        self::assertSame('deletePost', $this->findStoredRow(DBAgentMandateModel::getTableName(), 1)['coveredToolName']);
        self::assertTrue($this->agentMandatesService->bindOneInstructionMandateToTool($agentMandate, 'deletePost'), 'the same tool again re-binds');
        self::assertFalse($this->agentMandatesService->bindOneInstructionMandateToTool($agentMandate, 'publishPost'), 'another tool is refused');
        self::assertSame('deletePost', $this->findStoredRow(DBAgentMandateModel::getTableName(), 1)['coveredToolName']);

        self::assertFalse($this->agentMandatesService->releaseOneInstructionMandateBinding($agentMandate, 'publishPost'), 'only the bound key is released');
        self::assertTrue($this->agentMandatesService->releaseOneInstructionMandateBinding($agentMandate, 'deletePost'));
        self::assertNull($this->findStoredRow(DBAgentMandateModel::getTableName(), 1)['coveredToolName']);
        self::assertNull($agentMandate->coveredToolName);
        self::assertFalse($this->agentMandatesService->releaseOneInstructionMandateBinding($agentMandate, 'deletePost'), 'a released binding is released once');
        self::assertTrue($this->agentMandatesService->bindOneInstructionMandateToTool($agentMandate, 'publishPost'), 'the next tool takes the binding');

        self::assertTrue($this->agentMandatesService->consumeOneInstructionMandate($agentMandate));
        self::assertSame(AgentMandate::STATUS_CONSUMED, $this->findStoredStatus(1));
        self::assertFalse($this->agentMandatesService->consumeOneInstructionMandate($this->agentMandatesService->find(1, false)), 'a consumed mandate is consumed once');
        self::assertFalse($this->agentMandatesService->releaseOneInstructionMandateBinding($agentMandate, 'publishPost'), 'a mandate that is no longer active keeps its binding');
        self::assertTrue($this->agentMandatesService->bindOneInstructionMandateToTool($agentMandate, 'publishPost'), 'the read after the write answers for the bound tool');
        self::assertFalse($this->agentMandatesService->bindOneInstructionMandateToTool($agentMandate, 'deletePost'));
    }

    /** @param array<string, mixed> $columnValues */
    protected function insertMandate(int $mandateId, string $scopeType, array $columnValues = []): void
    {
        $this->insertRow(DBAgentMandateModel::getTableName(), $columnValues + [
            'id' => $mandateId,
            'accountId' => self::ACCOUNT_ID,
            'actionClass' => AgentMandate::ACTION_CLASSES[0],
            'scopeType' => $scopeType,
            'status' => AgentMandate::STATUS_ACTIVE,
            'validFrom' => $this->formatMomentSecondsAgo(60),
        ]);
    }

    protected function findStoredStatus(int $mandateId): string
    {
        $storedMandate = $this->findStoredRow(DBAgentMandateModel::getTableName(), $mandateId);
        self::assertNotNull($storedMandate, 'mandate ' . $mandateId . ' is stored');
        return (string)$storedMandate['status'];
    }

    /** The moment `$seconds` before now (after now for a negative number) as the database stores it. */
    protected function formatMomentSecondsAgo(int $seconds): string
    {
        return (new DateTime())->modify((-$seconds) . ' seconds')->format(DateTime::SIMPLE);
    }

    /**
     * @param AgentMandate[] $agentMandates
     * @return int[]
     */
    protected function getIds(array $agentMandates): array
    {
        return array_map(static fn (AgentMandate $agentMandate): int => (int)$agentMandate->id, $agentMandates);
    }
}
