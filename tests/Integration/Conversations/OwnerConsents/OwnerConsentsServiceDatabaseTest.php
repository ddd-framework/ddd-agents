<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\OwnerConsents;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentUse;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The guarded writes of {@see OwnerConsentsService} against the database, on both engines: the window and the refusal
 * floor of a tool read from the stored working state, a reservation that moves the window, a second reservation of
 * the same owner row and one below the floor refused, a refusal that raises the floor once (a repeated refusal changes
 * nothing and answers so on both engines), the conclusion of a reservation (used, released) guarded on the call that
 * reserved it, and the run-claim fence. The tool name and the record key reach every JSON path as bound values.
 */
class OwnerConsentsServiceDatabaseTest extends DatabaseTestCase
{
    protected const int CONVERSATION_ID = 100;

    protected const string TOOL_NAME = 'publishSite';

    protected const string OTHER_TOOL_NAME = 'deletePost';

    protected OwnerConsentsService $ownerConsentsService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess();
        /** @var OwnerConsentsService $ownerConsentsService */
        $ownerConsentsService = DDDService::instance()->getService(OwnerConsentsService::class);
        $this->ownerConsentsService = $ownerConsentsService;
    }

    protected function onTearDown(): void
    {
        AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess();
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testAReservationMovesTheWindowAndIsUsedOrReleasedOnlyByTheCallThatReservedIt(): void
    {
        $this->insertConversationWithWorkingState(null);
        $conversation = $this->buildConversation();

        self::assertSame(-1, $this->ownerConsentsService->findOwnerConsentWindowStart($conversation, self::TOOL_NAME), 'no working state at all');
        self::assertSame(-1, $this->ownerConsentsService->findOwnerConsentRefusedThrough($conversation, self::TOOL_NAME));

        $reservationOfRowFive = $this->buildUse(self::TOOL_NAME, 5, -1, 'call-1', OwnerConsentUse::STATE_RESERVED);
        self::assertTrue($this->ownerConsentsService->recordOwnerConsentReservation($conversation, $reservationOfRowFive, null));
        self::assertSame(5, $this->ownerConsentsService->findOwnerConsentWindowStart($conversation, self::TOOL_NAME));
        self::assertSame(-1, $this->ownerConsentsService->findOwnerConsentWindowStart($conversation, self::OTHER_TOOL_NAME), 'the window is per tool');
        $storedUse = $this->findStoredWorkingState()['ownerConsentUses'][self::TOOL_NAME . ':5'];
        self::assertSame(OwnerConsentUse::STATE_RESERVED, $storedUse['state']);
        self::assertSame('call-1', $storedUse['toolCallId']);

        self::assertFalse($this->ownerConsentsService->recordOwnerConsentReservation($conversation, $this->buildUse(self::TOOL_NAME, 5, -1, 'call-2', OwnerConsentUse::STATE_RESERVED), null), 'a stale window refuses');
        self::assertFalse($this->ownerConsentsService->recordOwnerConsentReservation($conversation, $this->buildUse(self::TOOL_NAME, 7, 4, 'call-2', OwnerConsentUse::STATE_RESERVED), null), 'a window the decision did not read refuses');

        self::assertFalse($this->ownerConsentsService->markOwnerConsentUseUsed($conversation, $this->buildUse(self::TOOL_NAME, 5, -1, 'call-of-another-turn', OwnerConsentUse::STATE_RESERVED), null));
        self::assertTrue($this->ownerConsentsService->markOwnerConsentUseUsed($conversation, $reservationOfRowFive, null));
        self::assertSame(OwnerConsentUse::STATE_USED, $this->findStoredWorkingState()['ownerConsentUses'][self::TOOL_NAME . ':5']['state']);
        self::assertFalse($this->ownerConsentsService->markOwnerConsentUseUsed($conversation, $reservationOfRowFive, null), 'a used record is no reservation any more');
        self::assertFalse($this->ownerConsentsService->releaseOwnerConsentUse($conversation, $reservationOfRowFive, null), 'a used record is never released');

        // a reservation of a later row is released: the window returns to the one the reservation stored
        $reservationOfRowNine = $this->buildUse(self::TOOL_NAME, 9, 5, 'call-3', OwnerConsentUse::STATE_RESERVED);
        self::assertTrue($this->ownerConsentsService->recordOwnerConsentReservation($conversation, $reservationOfRowNine, null));
        self::assertSame(9, $this->ownerConsentsService->findOwnerConsentWindowStart($conversation, self::TOOL_NAME));
        self::assertTrue($this->ownerConsentsService->releaseOwnerConsentUse($conversation, $reservationOfRowNine, null));
        self::assertSame(5, $this->ownerConsentsService->findOwnerConsentWindowStart($conversation, self::TOOL_NAME));
        $storedWorkingState = $this->findStoredWorkingState();
        self::assertArrayNotHasKey(self::TOOL_NAME . ':9', $storedWorkingState['ownerConsentUses']);
        self::assertArrayHasKey(self::TOOL_NAME . ':5', $storedWorkingState['ownerConsentUses']);
        self::assertFalse($this->ownerConsentsService->releaseOwnerConsentUse($conversation, $reservationOfRowNine, null), 'a released record is gone');

        // the same row reserved again after the release; a later record moves the window, the release leaves it there
        self::assertTrue($this->ownerConsentsService->recordOwnerConsentReservation($conversation, $reservationOfRowNine, null));
        $reservationOfRowTwelve = $this->buildUse(self::TOOL_NAME, 12, 9, 'call-4', OwnerConsentUse::STATE_RESERVED);
        self::assertTrue($this->ownerConsentsService->recordOwnerConsentReservation($conversation, $reservationOfRowTwelve, null));
        self::assertTrue($this->ownerConsentsService->releaseOwnerConsentUse($conversation, $reservationOfRowNine, null));
        self::assertSame(12, $this->ownerConsentsService->findOwnerConsentWindowStart($conversation, self::TOOL_NAME), 'a window a later record moved stays');
    }

    public function testARefusalRaisesTheFloorOnceAndNoReservationRecordsAtOrBelowIt(): void
    {
        $this->insertConversationWithWorkingState(['environment' => 'this environment']);
        $conversation = $this->buildConversation();

        $refusalOfRowSix = $this->buildUse(self::TOOL_NAME, 6, -1, 'call-1', OwnerConsentUse::STATE_REFUSED);
        self::assertTrue($this->ownerConsentsService->recordOwnerConsentRefusal($conversation, $refusalOfRowSix, null));
        self::assertSame(6, $this->ownerConsentsService->findOwnerConsentRefusedThrough($conversation, self::TOOL_NAME));
        self::assertSame(OwnerConsentUse::STATE_REFUSED, $this->findStoredWorkingState()['ownerConsentUses'][self::TOOL_NAME . ':6']['state']);
        self::assertFalse($this->ownerConsentsService->recordOwnerConsentRefusal($conversation, $refusalOfRowSix, null), 'the same refusal again changes nothing — on both engines');

        self::assertFalse($this->ownerConsentsService->recordOwnerConsentReservation($conversation, $this->buildUse(self::TOOL_NAME, 6, -1, 'call-2', OwnerConsentUse::STATE_RESERVED), null), 'a refused row never reserves');

        // a lower refusal on a free key adds its entry and leaves the floor where it is
        self::assertTrue($this->ownerConsentsService->recordOwnerConsentRefusal($conversation, $this->buildUse(self::TOOL_NAME, 3, -1, 'call-3', OwnerConsentUse::STATE_REFUSED), null));
        self::assertSame(6, $this->ownerConsentsService->findOwnerConsentRefusedThrough($conversation, self::TOOL_NAME), 'the floor never falls');

        // a reservation holds row 8, the refusal of the same row raises the floor and keeps the reservation's entry
        $reservationOfRowEight = $this->buildUse(self::TOOL_NAME, 8, -1, 'call-4', OwnerConsentUse::STATE_RESERVED);
        self::assertTrue($this->ownerConsentsService->recordOwnerConsentReservation($conversation, $reservationOfRowEight, null));
        self::assertTrue($this->ownerConsentsService->recordOwnerConsentRefusal($conversation, $this->buildUse(self::TOOL_NAME, 8, -1, 'call-5', OwnerConsentUse::STATE_REFUSED), null));
        self::assertSame(8, $this->ownerConsentsService->findOwnerConsentRefusedThrough($conversation, self::TOOL_NAME));
        self::assertSame('call-4', $this->findStoredWorkingState()['ownerConsentUses'][self::TOOL_NAME . ':8']['toolCallId']);

        self::assertSame(-1, $this->ownerConsentsService->findOwnerConsentRefusedThrough($conversation, self::OTHER_TOOL_NAME));
        self::assertSame('this environment', $this->findStoredWorkingState()['environment']);
    }

    public function testAWriteUnderALostRunClaimThrows(): void
    {
        $this->insertConversationWithWorkingState(null, ['runState' => AIConversationRunState::RUNNING, 'runToken' => 'token-of-this-worker']);
        $conversation = $this->buildConversation();

        self::assertTrue($this->ownerConsentsService->recordOwnerConsentReservation($conversation, $this->buildUse(self::TOOL_NAME, 5, -1, 'call-1', OwnerConsentUse::STATE_RESERVED), 'token-of-this-worker'));
        self::assertFalse($this->ownerConsentsService->recordOwnerConsentReservation($conversation, $this->buildUse(self::TOOL_NAME, 5, -1, 'call-2', OwnerConsentUse::STATE_RESERVED), 'token-of-this-worker'), 'a refused guard under a held claim is no lost claim');

        $this->expectException(AgentRunClaimLostException::class);
        $this->ownerConsentsService->recordOwnerConsentRefusal($conversation, $this->buildUse(self::TOOL_NAME, 7, 5, 'call-3', OwnerConsentUse::STATE_REFUSED), 'token-of-another-worker');
    }

    public function testANameThatIsNoToolNameNeverReachesAStatement(): void
    {
        $this->insertConversationWithWorkingState(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->ownerConsentsService->findOwnerConsentWindowStart($this->buildConversation(), '12345');
    }

    /**
     * @param array<string, mixed>|null $storedWorkingState null = the column is empty
     * @param array<string, mixed> $columnValues
     */
    protected function insertConversationWithWorkingState(?array $storedWorkingState, array $columnValues = []): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), $columnValues + [
            'id' => self::CONVERSATION_ID,
            'runState' => AIConversationRunState::IDLE,
            'agentWorkingState' => $storedWorkingState === null ? null : json_encode($storedWorkingState),
        ]);
    }

    protected function buildConversation(): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = self::CONVERSATION_ID;
        return $conversation;
    }

    protected function buildUse(string $toolName, int $ownerMessageIndex, int $windowStart, string $toolCallId, string $state): OwnerConsentUse
    {
        $ownerConsentUse = new OwnerConsentUse();
        $ownerConsentUse->toolName = $toolName;
        $ownerConsentUse->targetKey = 'site:1';
        $ownerConsentUse->ownerMessageIndex = $ownerMessageIndex;
        $ownerConsentUse->windowStart = $windowStart;
        $ownerConsentUse->toolCallId = $toolCallId;
        $ownerConsentUse->state = $state;
        $ownerConsentUse->at = '2026-11-11 10:00:00';
        return $ownerConsentUse;
    }

    /** @return array<string, mixed> */
    protected function findStoredWorkingState(): array
    {
        $storedConversation = $this->findStoredRow(DBAIConversationModel::getTableName(), self::CONVERSATION_ID);
        self::assertNotNull($storedConversation);
        $storedWorkingState = json_decode((string)$storedConversation['agentWorkingState'], true);
        self::assertIsArray($storedWorkingState);
        return $storedWorkingState;
    }
}
