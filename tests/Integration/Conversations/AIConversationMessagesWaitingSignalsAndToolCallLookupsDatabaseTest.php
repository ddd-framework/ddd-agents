<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallInjectionScreeningVerdict;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The statements of {@see AIConversationMessagesService} over signals that wait for a running turn and over stored tool
 * calls, against the database on both engines: a signal merges into the waiting row of its source or becomes a new
 * row, the running loop reads every waiting signal once, a mark is written only while the row is still the one that
 * was read, and the lookups of tool calls read the `toolCall` document the same way on MariaDB and on PostgreSQL.
 */
class AIConversationMessagesWaitingSignalsAndToolCallLookupsDatabaseTest extends DatabaseTestCase
{
    protected const int CONVERSATION_ID = 100;

    protected function setUp(): void
    {
        parent::setUp();
        // the writers of this test are a worker's: no account is signed in
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_ID]);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        AIConversationMessagesService::forgetClaimFencedWriteStateForNewJob();
        $connection = $this->getDatabaseConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    public function testASignalWrittenWhileATurnRunsMergesIntoTheWaitingRowOfItsSourceOrBecomesANewRow(): void
    {
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation();
        $messageTableName = DBAIConversationMessageModel::getTableName();

        $firstClick = $this->buildSignal(AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, 'the owner picked the first offer');
        self::assertFalse($messagesService->persistSignalWaitingForRunningTurn($conversation, $firstClick, true), 'no row of the source waits yet');
        self::assertSame(AIConversationMessage::STATUS_PENDING, $this->findStoredRow($messageTableName, (int)$firstClick->id)['status']);

        $newerClick = $this->buildSignal(AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, 'the owner picked the second offer');
        self::assertTrue($messagesService->persistSignalWaitingForRunningTurn($conversation, $newerClick, true), 'the waiting row takes the newest text');
        self::assertSame((int)$firstClick->id, (int)$newerClick->id);
        self::assertSame($firstClick->indexInConversation, $newerClick->indexInConversation);
        self::assertSame('the owner picked the second offer', $this->findStoredRow($messageTableName, (int)$firstClick->id)['message']);

        $doubledClick = $this->buildSignal(AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, 'the owner picked the second offer');
        self::assertTrue(
            $messagesService->persistSignalWaitingForRunningTurn($conversation, $doubledClick, true, true),
            'a doubled click is the waiting row, on an engine that counts changed rows as on one that counts matched rows'
        );
        self::assertSame((int)$firstClick->id, (int)$doubledClick->id);

        $differentClick = $this->buildSignal(AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, 'the owner picked the third offer');
        self::assertFalse($messagesService->persistSignalWaitingForRunningTurn($conversation, $differentClick, true, true), 'two different clicks are two answers');
        self::assertNotSame((int)$firstClick->id, (int)$differentClick->id);

        $signalOfAnotherSource = $this->buildSignal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 'a sub-agent ended');
        self::assertFalse($messagesService->persistSignalWaitingForRunningTurn($conversation, $signalOfAnotherSource, false), 'no merge was asked for');

        self::assertSame(2, $messagesService->countSignalsWaitingForRunningTurnOfSource($conversation, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK));
        self::assertSame(1, $messagesService->countSignalsWaitingForRunningTurnOfSource($conversation, AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED));
        self::assertSame(
            (int)$differentClick->id,
            $messagesService->findNewestSignalWaitingForRunningTurnOfSource($conversation, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK)?->messageId
        );
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
    }

    public function testTheRunningLoopReadsEveryWaitingSignalOnceAndMarksItRead(): void
    {
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation();
        $this->insertSignalRow(601, 3, AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, AIConversationMessage::STATUS_PENDING, 'the first run ended');
        $this->insertSignalRow(602, 4, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, AIConversationMessage::STATUS_COMPLETED, 'a click a loop has read');
        $this->insertSignalRow(603, 5, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, AIConversationMessage::STATUS_PENDING, 'a click');
        $this->insertSignalRow(604, 6, AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE, AIConversationMessage::STATUS_PENDING, 'a source no running turn takes over');
        $this->insertSignalRow(605, 7, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, AIConversationMessage::STATUS_PENDING, 'a truncated click', '2026-01-02 03:04:05');

        self::assertTrue($messagesService->hasSignalWaitingForRunningTurn($conversation));
        self::assertTrue($messagesService->hasSignalWaitingForRunningTurn($conversation, 4));
        self::assertFalse($messagesService->hasSignalWaitingForRunningTurn($conversation, 5), 'only rows above the index');

        $readSignals = $messagesService->findAndMarkReadSignalsWaitingForRunningTurn($conversation);

        self::assertSame([601, 603], array_map(static fn (AIConversationMessage $readSignal): int => (int)$readSignal->id, $readSignals), 'in index order');
        self::assertSame('the first run ended', $readSignals[0]->message);
        self::assertSame(AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, $readSignals[1]->signalSource);
        self::assertSame(5, $readSignals[1]->indexInConversation);
        $messageTableName = DBAIConversationMessageModel::getTableName();
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $this->findStoredRow($messageTableName, 601)['status']);
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $this->findStoredRow($messageTableName, 603)['status']);
        self::assertSame(AIConversationMessage::STATUS_PENDING, $this->findStoredRow($messageTableName, 604)['status']);
        self::assertSame(AIConversationMessage::STATUS_PENDING, $this->findStoredRow($messageTableName, 605)['status']);
        self::assertFalse($messagesService->hasSignalWaitingForRunningTurn($conversation));
        self::assertSame([], $messagesService->findAndMarkReadSignalsWaitingForRunningTurn($conversation), 'a signal is read once');
        self::assertFalse($messagesService->replaceTextOfSignalWaitingForRunningTurn(603, 'a newer click'), 'a row a loop has read takes no text');
        self::assertSame('a click', $this->findStoredRow($messageTableName, 603)['message']);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
    }

    public function testAWaitingSignalIsMarkedReadOnlyWhileItsTextIsTheOneThatWasLoaded(): void
    {
        $messagesService = $this->getMessagesService();
        $this->insertSignalRow(601, 3, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, AIConversationMessage::STATUS_PENDING, 'the text the release path loaded');
        $this->insertSignalRow(602, 4, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, AIConversationMessage::STATUS_PENDING, 'a newer text an injection merged');
        $loadedSignal = $this->buildLoadedSignal(601, 'the text the release path loaded');
        $signalWithANewerStoredText = $this->buildLoadedSignal(602, 'the text the release path loaded');

        self::assertTrue($messagesService->markSignalReadUnlessItsTextChanged($loadedSignal));
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $loadedSignal->status);
        self::assertFalse($messagesService->markSignalReadUnlessItsTextChanged($loadedSignal), 'a row that is read is not marked again');
        self::assertFalse($messagesService->markSignalReadUnlessItsTextChanged($signalWithANewerStoredText));
        self::assertSame(AIConversationMessage::STATUS_PENDING, $signalWithANewerStoredText->status);

        $messageTableName = DBAIConversationMessageModel::getTableName();
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $this->findStoredRow($messageTableName, 601)['status']);
        self::assertSame(AIConversationMessage::STATUS_PENDING, $this->findStoredRow($messageTableName, 602)['status']);
    }

    public function testATerminalTurnEndMarksOnlyTheWaitingRunEndNotificationsRead(): void
    {
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation();
        $this->insertSignalRow(601, 3, AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, AIConversationMessage::STATUS_PENDING, 'the first run ended');
        $this->insertSignalRow(602, 4, AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, AIConversationMessage::STATUS_PENDING, 'the second run ended');
        $this->insertSignalRow(603, 5, AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, AIConversationMessage::STATUS_COMPLETED, 'a run end a loop has read');
        $this->insertSignalRow(604, 6, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, AIConversationMessage::STATUS_PENDING, 'a click');

        self::assertSame(2, $messagesService->markWaitingSubAgentRunEndNotificationsReadAtTerminalTurnEnd($conversation));
        self::assertSame(0, $messagesService->markWaitingSubAgentRunEndNotificationsReadAtTerminalTurnEnd($conversation));

        $messageTableName = DBAIConversationMessageModel::getTableName();
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $this->findStoredRow($messageTableName, 601)['status']);
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $this->findStoredRow($messageTableName, 602)['status']);
        self::assertSame(AIConversationMessage::STATUS_PENDING, $this->findStoredRow($messageTableName, 604)['status'], 'the click still waits for its turn');
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
    }

    public function testACapabilitySetAboveAnIndexIsFoundOnlyOnRowsThatAreNotTruncated(): void
    {
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation();
        $this->insertSignalRow(601, 3, AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE, AIConversationMessage::STATUS_COMPLETED, 'a row with a set', null, '["Seo.Keywords"]');
        $this->insertSignalRow(602, 4, AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE, AIConversationMessage::STATUS_COMPLETED, 'a row without a set');
        $this->insertSignalRow(603, 5, AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE, AIConversationMessage::STATUS_COMPLETED, 'a truncated row with a set', '2026-01-02 03:04:05', '["Presence.Posts"]');

        self::assertTrue($messagesService->hasPersistedLoadedCapabilitiesAboveIndex($conversation, 2));
        self::assertFalse($messagesService->hasPersistedLoadedCapabilitiesAboveIndex($conversation, 3), 'the row at the index is part of the view');
        self::assertFalse($messagesService->hasPersistedLoadedCapabilitiesAboveIndex($this->buildConversation(999), -1));
    }

    public function testCompletedCallsOfAToolAreCountedByTheirArgumentValues(): void
    {
        $messagesService = $this->getMessagesService();
        $this->insertToolCallRow(801, 0, ['toolCallId' => 'call_1', 'name' => 'searchSectionImages', 'arguments' => ['sectionKey' => 'hero', 'query' => 'sun', 'page' => 2], 'status' => 'COMPLETED']);
        $this->insertToolCallRow(802, 1, ['toolCallId' => 'call_2', 'name' => 'searchSectionImages', 'arguments' => ['sectionKey' => 'hero', 'query' => "the owner's sea"], 'status' => 'COMPLETED']);
        $this->insertToolCallRow(803, 2, ['toolCallId' => 'call_3', 'name' => 'searchSectionImages', 'arguments' => ['sectionKey' => 'hero'], 'status' => 'PENDING']);
        $this->insertToolCallRow(804, 3, ['toolCallId' => 'call_4', 'name' => 'searchSectionImages', 'arguments' => ['sectionKey' => 'about'], 'status' => 'COMPLETED']);
        $this->insertToolCallRow(805, 4, ['toolCallId' => 'call_5', 'name' => 'writeSection', 'arguments' => ['sectionKey' => 'hero'], 'status' => 'COMPLETED']);

        self::assertSame(3, $messagesService->countCompletedToolCallsInConversation(self::CONVERSATION_ID, 'searchSectionImages'));
        self::assertSame(2, $messagesService->countCompletedToolCallsInConversation(self::CONVERSATION_ID, 'searchSectionImages', ['sectionKey' => 'hero']), 'the pending call is not counted');
        self::assertSame(1, $messagesService->countCompletedToolCallsInConversation(self::CONVERSATION_ID, 'searchSectionImages', ['sectionKey' => 'hero', 'query' => "the owner's sea"]));
        self::assertSame(1, $messagesService->countCompletedToolCallsInConversation(self::CONVERSATION_ID, 'searchSectionImages', ['page' => '2']), 'a number is compared as its text');
        self::assertSame(0, $messagesService->countCompletedToolCallsInConversation(self::CONVERSATION_ID, 'searchSectionImages', ['sectionKey' => 'contact']));
        self::assertSame(0, $messagesService->countCompletedToolCallsInConversation(self::CONVERSATION_ID, 'searchSectionImages', ["a key with a ' and a \"" => 'hero']), 'the argument name is a bound value');
        self::assertSame(0, $messagesService->countCompletedToolCallsInConversation(999, 'searchSectionImages'));

        self::assertSame(805, $messagesService->findNewestToolCallMessageIdOfNamesAfter(self::CONVERSATION_ID, 0, ['searchSectionImages', 'writeSection']));
        self::assertSame(804, $messagesService->findNewestToolCallMessageIdOfNamesAfter(self::CONVERSATION_ID, 802, ['searchSectionImages']), 'a call of any status');
        self::assertNull($messagesService->findNewestToolCallMessageIdOfNamesAfter(self::CONVERSATION_ID, 804, ['searchSectionImages']), 'only rows with a higher id');
        self::assertNull($messagesService->findNewestToolCallMessageIdOfNamesAfter(self::CONVERSATION_ID, 0, []));
    }

    public function testStoredCallsAreFoundByTheirToolCallIds(): void
    {
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation();
        $this->insertToolCallRow(701, 0, [
            'toolCallId' => 'call_a', 'name' => 'getKeywords', 'arguments' => [], 'status' => 'COMPLETED', 'result' => 'three keywords',
            'bindableResult' => ['data' => ['keywords' => ['pizza', 'pasta']]],
        ]);
        $this->insertToolCallRow(702, 1, ['toolCallId' => 'call_b', 'name' => 'getKeywords', 'arguments' => [], 'status' => 'FAILED', 'result' => null]);
        $this->insertToolCallRow(703, 2, [
            'toolCallId' => 'call_c', 'name' => 'readWebPage', 'arguments' => [], 'status' => 'COMPLETED', 'result' => 'a page text',
            'injectionScreening' => ToolCall::INJECTION_SCREENING_REMOVED, 'bindableResult' => ['data' => ['title' => 'a page']],
        ]);
        $this->insertToolCallRow(704, 3, ['toolCallId' => 'call_d', 'name' => 'getKeywords', 'arguments' => [], 'status' => 'COMPLETED', 'result' => 'x'], ['truncatedAt' => '2026-01-02 03:04:05']);
        $this->insertToolCallRow(705, 4, ['toolCallId' => 'call_e', 'name' => 'getKeywords', 'arguments' => [], 'status' => 'COMPLETED', 'result' => 'y']);

        $toolCallResultsByMessageId = $messagesService->findToolCallResultsByToolCallIds($conversation, ['call_a', 'call_b', 'call_c', 'call_d', 'call_unknown']);
        ksort($toolCallResultsByMessageId);

        self::assertSame([701, 702, 703], array_keys($toolCallResultsByMessageId), 'the truncated row and the rows not asked for stay out');
        self::assertSame('call_a', $toolCallResultsByMessageId[701]->toolCallId);
        self::assertSame('COMPLETED', $toolCallResultsByMessageId[701]->status);
        self::assertFalse($toolCallResultsByMessageId[701]->resultIsUnusable);
        self::assertTrue($toolCallResultsByMessageId[701]->hasBindableData);
        self::assertSame(['keywords' => ['pizza', 'pasta']], $toolCallResultsByMessageId[701]->bindableData);
        self::assertSame('FAILED', $toolCallResultsByMessageId[702]->status);
        self::assertNull($toolCallResultsByMessageId[702]->bindableResultRecord, 'a call that stored no bindable record');
        self::assertTrue($toolCallResultsByMessageId[703]->resultIsUnusable, 'a result removed for security binds nothing');
        self::assertSame([], $messagesService->findToolCallResultsByToolCallIds($conversation, []));
        self::assertSame([], $messagesService->findToolCallResultsByToolCallIds($this->buildConversation(999), ['call_a']));
    }

    public function testAScreeningVerdictIsWrittenOnlyWhileTheCallIsStillPending(): void
    {
        $messagesService = $this->getMessagesService();
        $this->insertToolCallRow(701, 0, [
            'toolCallId' => 'call_a', 'name' => 'readWebPage', 'arguments' => [], 'status' => 'COMPLETED', 'result' => 'a page text',
            'injectionScreening' => ToolCall::INJECTION_SCREENING_PENDING,
        ]);
        $this->insertToolCallRow(702, 1, ['toolCallId' => 'call_b', 'name' => 'readWebPage', 'arguments' => [], 'status' => 'COMPLETED', 'result' => 'another page text']);
        $pendingCallMessage = $this->buildLoadedCallMessage(701);
        $callMessageWithoutScreening = $this->buildLoadedCallMessage(702);
        $cleanVerdict = ToolCallInjectionScreeningVerdict::fromScreening(false, false, 0.01, true);

        self::assertTrue($messagesService->persistToolCallVerdictWhileScreeningPending($pendingCallMessage, $cleanVerdict));
        self::assertSame(ToolCall::INJECTION_SCREENING_CLEAN, $pendingCallMessage->toolCall->injectionScreening);
        self::assertSame(ToolCall::INJECTION_SCREENING_CLEAN, $this->findStoredToolCall(701)['injectionScreening']);
        self::assertSame('a page text', $this->findStoredToolCall(701)['result'], 'the rest of the call stays');

        $flaggingVerdict = ToolCallInjectionScreeningVerdict::fromScreening(true, false, 0.9, true);
        self::assertFalse($messagesService->persistToolCallVerdictWhileScreeningPending($pendingCallMessage, $flaggingVerdict), 'a verdict is written once');
        self::assertSame(ToolCall::INJECTION_SCREENING_CLEAN, $this->findStoredToolCall(701)['injectionScreening']);
        self::assertFalse($messagesService->persistToolCallVerdictWhileScreeningPending($callMessageWithoutScreening, $flaggingVerdict), 'a call that was never pending');
        self::assertArrayNotHasKey('injectionScreening', array_filter($this->findStoredToolCall(702), static fn (mixed $storedValue): bool => $storedValue !== null));
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
    }

    protected function getMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /** A conversation object that names a stored row and holds none of its messages. */
    protected function buildConversation(int $conversationId = self::CONVERSATION_ID): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = $conversationId;
        return $conversation;
    }

    /** A new signal of the test's conversation, not stored yet. */
    protected function buildSignal(string $signalSource, string $signalText): AIConversationMessage
    {
        $signalMessage = new AIConversationMessage();
        $signalMessage->aiConversationId = self::CONVERSATION_ID;
        $signalMessage->role = AIConversationMessage::ROLE_SYSTEM;
        $signalMessage->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $signalMessage->signalSource = $signalSource;
        $signalMessage->message = $signalText;
        return $signalMessage;
    }

    /** The in-memory copy of a stored waiting signal, with the text its reader loaded. */
    protected function buildLoadedSignal(int $messageId, string $loadedText): AIConversationMessage
    {
        $loadedSignal = $this->buildSignal(AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, $loadedText);
        $loadedSignal->id = $messageId;
        $loadedSignal->status = AIConversationMessage::STATUS_PENDING;
        return $loadedSignal;
    }

    /** The in-memory copy of a stored call row: the id is all the verdict write reads of it. */
    protected function buildLoadedCallMessage(int $messageId): AIConversationMessage
    {
        $callMessage = new AIConversationMessage();
        $callMessage->id = $messageId;
        $callMessage->aiConversationId = self::CONVERSATION_ID;
        return $callMessage;
    }

    protected function insertSignalRow(
        int $messageId,
        int $indexInConversation,
        string $signalSource,
        string $status,
        string $signalText,
        ?string $truncatedAt = null,
        ?string $loadedCapabilities = null
    ): void {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => self::CONVERSATION_ID,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_SIGNAL,
            'role' => AIConversationMessage::ROLE_SYSTEM,
            'message' => $signalText,
            'signalSource' => $signalSource,
            'status' => $status,
            'truncatedAt' => $truncatedAt,
            'loadedCapabilities' => $loadedCapabilities,
        ]);
    }

    /**
     * A tool-call row of the test's conversation.
     *
     * @param array<string, mixed> $storedToolCall The `toolCall` document of the row
     * @param array<string, mixed> $columnValues Further columns of the row
     */
    protected function insertToolCallRow(int $messageId, int $indexInConversation, array $storedToolCall, array $columnValues = []): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), $columnValues + [
            'id' => $messageId,
            'aiConversationId' => self::CONVERSATION_ID,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => '',
            'toolCall' => json_encode($storedToolCall, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<string, mixed> The decoded `toolCall` document of the stored message row */
    protected function findStoredToolCall(int $messageId): array
    {
        $storedMessage = $this->findStoredRow(DBAIConversationMessageModel::getTableName(), $messageId);
        self::assertNotNull($storedMessage, 'message ' . $messageId . ' is stored');
        return json_decode((string)$storedMessage['toolCall'], true, 512, JSON_THROW_ON_ERROR);
    }
}
