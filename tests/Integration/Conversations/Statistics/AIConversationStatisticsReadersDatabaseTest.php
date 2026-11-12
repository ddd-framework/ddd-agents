<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Statistics;

use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentConversationStat;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentConversationStats;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversation;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Common\Entities\Charts\DateValueSequence;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The statistics statements of the conversation repo ({@see DBAIConversation}) and of the message repo
 * ({@see DBAIConversationMessage}) against the database, on both engines: the bucket keys, the counts, the totals of
 * a conversation tree and the order of the rows are the same on MariaDB and on PostgreSQL.
 *
 * Every row sits on a fixed day of March 2026 (the 2nd and the 9th are Mondays), so a week bucket never depends on
 * the day the test runs.
 */
class AIConversationStatisticsReadersDatabaseTest extends DatabaseTestCase
{
    protected const int FIRST_ACCOUNT_ID = 10;
    protected const int SECOND_ACCOUNT_ID = 11;

    protected const int MONDAY_CONVERSATION_ID = 1;
    protected const int WEDNESDAY_CONVERSATION_ID = 2;
    protected const int LATE_WEDNESDAY_CONVERSATION_ID = 3;
    protected const int NEXT_MONDAY_CONVERSATION_WITHOUT_ACCOUNT_ID = 4;
    protected const int SUB_AGENT_CONVERSATION_ID = 5;
    protected const int SUPERVISOR_CONVERSATION_ID = 6;

    protected const int DELEGATE_MESSAGE_ID = 102;

    protected int $nextMessageId = 1000;

    /** @var int[] The next free message index by conversation id */
    protected array $nextIndexInConversationByConversationId = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->nextMessageId = 1000;
        $this->nextIndexInConversationByConversationId = [];
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::FIRST_ACCOUNT_ID, 'email' => 'first@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::SECOND_ACCOUNT_ID, 'email' => 'second@example.test']);
    }

    public function testConversationStartsAreCountedPerDayAndPerWeekWithoutSubAgents(): void
    {
        $this->insertTheConversationsOfMarch();
        $dBAIConversation = new DBAIConversation();

        self::assertSame(
            ['2026-03-02' => 1.0, '2026-03-04' => 2.0, '2026-03-09' => 1.0],
            $this->getValuesByDay($dBAIConversation->findConversationCountsPerBucket($this->getStartOfMarch(), $this->getEndOfMarch()))
        );
        self::assertSame(
            ['2026-03-02' => 3.0, '2026-03-09' => 1.0],
            $this->getValuesByDay($dBAIConversation->findConversationCountsPerBucket($this->getStartOfMarch(), $this->getEndOfMarch(), byWeek: true)),
            'a week is keyed by its Monday'
        );
        self::assertSame(
            ['2026-03-04' => 1.0, '2026-03-09' => 1.0],
            $this->getValuesByDay(
                $dBAIConversation->findConversationCountsPerBucket($this->getStartOfMarch(), $this->getEndOfMarch(), false, self::FIRST_ACCOUNT_ID)
            ),
            'leaving an account out keeps the conversation without an account'
        );
        self::assertSame(
            ['2026-03-04' => 2.0],
            $this->getValuesByDay(
                $dBAIConversation->findConversationCountsPerBucket(new DateTime('2026-03-03 00:00:00'), new DateTime('2026-03-08 23:59:59'))
            ),
            'the range is taken on the start of the conversation'
        );
    }

    public function testTheEarliestConversationDateFollowsTheSameScope(): void
    {
        $dBAIConversation = new DBAIConversation();
        self::assertNull($dBAIConversation->findEarliestConversationDate(), 'no conversation, no date');

        $this->insertTheConversationsOfMarch();

        self::assertSame('2026-03-02', $dBAIConversation->findEarliestConversationDate()?->format('Y-m-d'));
        self::assertSame('2026-03-04', $dBAIConversation->findEarliestConversationDate(self::FIRST_ACCOUNT_ID)?->format('Y-m-d'));
    }

    public function testActiveAccountsAreTheDistinctAccountsThatSentAUserMessageInTheBucket(): void
    {
        $this->insertTheConversationsOfMarch();
        $this->insertTheUserMessagesOfMarch();
        $dBAIConversation = new DBAIConversation();

        self::assertSame(
            ['2026-03-02' => 1.0, '2026-03-04' => 2.0, '2026-03-05' => 1.0, '2026-03-09' => 0.0],
            $this->getValuesByDay($dBAIConversation->findActiveAccountCountsPerBucket($this->getStartOfMarch(), $this->getEndOfMarch())),
            'a day on which only a conversation without an account wrote is reported with no account'
        );
        self::assertSame(
            ['2026-03-02' => 2.0, '2026-03-09' => 0.0],
            $this->getValuesByDay($dBAIConversation->findActiveAccountCountsPerBucket($this->getStartOfMarch(), $this->getEndOfMarch(), byWeek: true)),
            'the week counts an account once, however many days it wrote on'
        );
        self::assertSame(
            ['2026-03-04' => 1.0, '2026-03-09' => 0.0],
            $this->getValuesByDay(
                $dBAIConversation->findActiveAccountCountsPerBucket($this->getStartOfMarch(), $this->getEndOfMarch(), false, self::FIRST_ACCOUNT_ID)
            )
        );
        self::assertSame(2, $dBAIConversation->countDistinctActiveAccounts($this->getStartOfMarch(), $this->getEndOfMarch()));
        self::assertSame(1, $dBAIConversation->countDistinctActiveAccounts(new DateTime('2026-03-05 00:00:00'), $this->getEndOfMarch()));
    }

    public function testTheJoinAndTheConditionOfAnApplicationRunInsideTheThreeStatements(): void
    {
        $this->insertAgentSpaceRow(['id' => 70, 'timezone' => 'Europe/Berlin']);
        $this->insertAgentSpaceRow(['id' => 71, 'timezone' => ConversationRepoThatLeavesOutMarkedAgentSpaces::MARKED_TIMEZONE]);
        $this->insertConversation(self::MONDAY_CONVERSATION_ID, '2026-03-02 09:00:00', self::FIRST_ACCOUNT_ID, ['agentSpaceId' => 71]);
        $this->insertConversation(self::WEDNESDAY_CONVERSATION_ID, '2026-03-04 10:00:00', self::SECOND_ACCOUNT_ID, ['agentSpaceId' => 70]);
        $this->insertConversation(self::NEXT_MONDAY_CONVERSATION_WITHOUT_ACCOUNT_ID, '2026-03-09 08:00:00', null);
        $this->insertUserMessage(self::MONDAY_CONVERSATION_ID, '2026-03-02 09:01:00');
        $this->insertUserMessage(self::WEDNESDAY_CONVERSATION_ID, '2026-03-04 10:01:00');
        $conversationRepoOfTheApplication = new ConversationRepoThatLeavesOutMarkedAgentSpaces();

        self::assertSame(
            ['2026-03-04' => 1.0, '2026-03-09' => 1.0],
            $this->getValuesByDay(
                $conversationRepoOfTheApplication->findConversationCountsPerBucket($this->getStartOfMarch(), $this->getEndOfMarch(), false, null, true)
            ),
            'the conversation of the marked space is left out, the one without a space stays'
        );
        self::assertSame(
            ['2026-03-02' => 1.0, '2026-03-04' => 1.0, '2026-03-09' => 1.0],
            $this->getValuesByDay(
                $conversationRepoOfTheApplication->findConversationCountsPerBucket($this->getStartOfMarch(), $this->getEndOfMarch(), false, null, false)
            ),
            'without the switch the application filter is not part of the statement'
        );
        self::assertSame(
            ['2026-03-04' => 1.0],
            $this->getValuesByDay(
                $conversationRepoOfTheApplication->findActiveAccountCountsPerBucket($this->getStartOfMarch(), $this->getEndOfMarch(), false, null, true)
            )
        );
        self::assertSame('2026-03-04', $conversationRepoOfTheApplication->findEarliestConversationDate(null, true)?->format('Y-m-d'));
        self::assertSame(
            '2026-03-09',
            $conversationRepoOfTheApplication->findEarliestConversationDate(self::SECOND_ACCOUNT_ID, true)?->format('Y-m-d'),
            'the left-out account and the application filter hold together'
        );
    }

    public function testTheTopConversationsCarryTheTotalsOfTheirWholeTree(): void
    {
        $this->insertTheConversationTreeWithCosts();
        $dBAIConversation = new DBAIConversation();

        $statsByCost = $dBAIConversation->findTopConversationStats($this->getStartOfMarch(), $this->getEndOfMarch());

        self::assertSame(
            [self::WEDNESDAY_CONVERSATION_ID, self::MONDAY_CONVERSATION_ID, self::NEXT_MONDAY_CONVERSATION_WITHOUT_ACCOUNT_ID],
            $this->getConversationIds($statsByCost),
            'costliest tree first; the sub-agent and the supervisor are no rows of their own'
        );
        $statOfTheTree = $statsByCost->getElements()[1];
        self::assertSame(self::FIRST_ACCOUNT_ID, $statOfTheTree->accountId);
        self::assertNull($statOfTheTree->agentSpaceId);
        self::assertSame(4, $statOfTheTree->messageCount, 'two rows of the root, one of the sub-agent, one of the supervisor');
        self::assertSame(195, $statOfTheTree->totalTokens);
        self::assertEqualsWithDelta(
            1.525,
            $statOfTheTree->totalCostInUSD,
            0.00005,
            'the model calls (0.10 + 0.20 + 0.30), the tool call execution (0.05) and the overhead of the root, the sub-agent and the supervisor (0.5 + 0.25 + 0.125)'
        );
        self::assertSame('2026-03-02 09:00:00', $statOfTheTree->created?->format('Y-m-d H:i:s'));
        self::assertEqualsWithDelta(2.0, $statsByCost->getElements()[0]->totalCostInUSD, 0.00005, 'a root without overhead adds none');
        self::assertSame(0, $statsByCost->getElements()[2]->accountId, 'a conversation without an account reads as account 0');

        self::assertSame(
            [self::NEXT_MONDAY_CONVERSATION_WITHOUT_ACCOUNT_ID, self::MONDAY_CONVERSATION_ID, self::WEDNESDAY_CONVERSATION_ID],
            $this->getConversationIds($dBAIConversation->findTopConversationStats($this->getStartOfMarch(), $this->getEndOfMarch(), 'messageCount'))
        );
        self::assertSame(
            [self::MONDAY_CONVERSATION_ID, self::WEDNESDAY_CONVERSATION_ID, self::NEXT_MONDAY_CONVERSATION_WITHOUT_ACCOUNT_ID],
            $this->getConversationIds($dBAIConversation->findTopConversationStats($this->getStartOfMarch(), $this->getEndOfMarch(), 'totalTokens'))
        );
        self::assertSame(
            [self::WEDNESDAY_CONVERSATION_ID],
            $this->getConversationIds($dBAIConversation->findTopConversationStats($this->getStartOfMarch(), $this->getEndOfMarch(), 'totalCostInUSD', 1))
        );
        self::assertSame(
            $this->getConversationIds($statsByCost),
            $this->getConversationIds($dBAIConversation->findTopConversationStats($this->getStartOfMarch(), $this->getEndOfMarch(), 'created; DROP')),
            'an order that is not on the list falls back to the cost'
        );
        self::assertSame(
            [self::NEXT_MONDAY_CONVERSATION_WITHOUT_ACCOUNT_ID],
            $this->getConversationIds($dBAIConversation->findTopConversationStats(new DateTime('2026-03-09 00:00:00'), $this->getEndOfMarch())),
            'the range is taken on the rows, not on the start of the conversation'
        );
    }

    /** An application that shows its own id of the space on the rows derives row and set; the read builds those. */
    public function testTheTopConversationsAreRowsOfTheClassesAnApplicationDerived(): void
    {
        $this->insertTheConversationTreeWithCosts();
        $frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        try {
            StaticRegistry::$frameworkToAppClassNames[AgentConversationStat::class] = AgentConversationStatWithShopId::class;
            StaticRegistry::$frameworkToAppClassNames[AgentConversationStats::class] = AgentConversationStatsWithShopIds::class;
            $statsByCost = (new DBAIConversation())->findTopConversationStats($this->getStartOfMarch(), $this->getEndOfMarch());
        } finally {
            StaticRegistry::$frameworkToAppClassNames = $frameworkToAppClassNamesBefore;
        }

        self::assertInstanceOf(AgentConversationStatsWithShopIds::class, $statsByCost);
        self::assertContainsOnlyInstancesOf(AgentConversationStatWithShopId::class, $statsByCost->getElements());
        self::assertSame(
            [self::WEDNESDAY_CONVERSATION_ID, self::MONDAY_CONVERSATION_ID, self::NEXT_MONDAY_CONVERSATION_WITHOUT_ACCOUNT_ID],
            $this->getConversationIds($statsByCost)
        );
    }

    public function testTheToolResponseTotalsAreGroupedByToolHeaviestFirst(): void
    {
        $this->insertConversation(self::MONDAY_CONVERSATION_ID, '2026-03-02 09:00:00', self::FIRST_ACCOUNT_ID);
        $this->insertToolCallMessage('search', '2026-03-02 09:01:00', ['arguments' => ['query' => 'first'], 'result' => 'abcde']);
        $this->insertToolCallMessage('search', '2026-03-04 09:02:00', ['arguments' => ['query' => 'second'], 'result' => 'abcdefghij']);
        $this->insertToolCallMessage('read', '2026-03-04 09:03:00', ['arguments' => ['page' => 1], 'result' => str_repeat('r', 40)]);
        $this->insertToolCallMessage('ping', '2026-03-04 09:04:00', []);
        $this->insertToolCallMessage('read', '2026-04-01 00:00:00', ['result' => str_repeat('r', 900)]);
        $this->insertUserMessage(self::MONDAY_CONVERSATION_ID, '2026-03-04 09:05:00');
        $dBAIConversationMessage = new DBAIConversationMessage();

        $toolResponseStats = $dBAIConversationMessage->findToolResponseStats($this->getStartOfMarch(), $this->getEndOfMarch())->getElements();

        self::assertCount(3, $toolResponseStats, 'a row without a tool call is no tool; the call of April is outside the range');
        self::assertSame(['read', 'search', 'ping'], [$toolResponseStats[0]->toolName, $toolResponseStats[1]->toolName, $toolResponseStats[2]->toolName]);
        self::assertSame([1, 40, 40, 40], [
            $toolResponseStats[0]->callCount,
            $toolResponseStats[0]->sumResultChars,
            $toolResponseStats[0]->avgResultChars,
            $toolResponseStats[0]->maxResultChars,
        ]);
        self::assertSame([2, 15, 8, 10], [
            $toolResponseStats[1]->callCount,
            $toolResponseStats[1]->sumResultChars,
            $toolResponseStats[1]->avgResultChars,
            $toolResponseStats[1]->maxResultChars,
        ]);
        self::assertSame([1, 0, 0, 0], [
            $toolResponseStats[2]->callCount,
            $toolResponseStats[2]->sumResultChars,
            $toolResponseStats[2]->avgResultChars,
            $toolResponseStats[2]->maxResultChars,
        ], 'a tool whose calls carry no result comes last with 0 characters');
    }

    public function testTheLargestResponsesOfOneToolComeFirstAndACallWithoutAResultLast(): void
    {
        $this->insertConversation(self::MONDAY_CONVERSATION_ID, '2026-03-02 09:00:00', self::FIRST_ACCOUNT_ID);
        $smallResultMessageId = $this->insertToolCallMessage('search', '2026-03-02 09:01:00', ['arguments' => ['query' => 'first'], 'result' => 'abcde']);
        $withoutResultMessageId = $this->insertToolCallMessage('search', '2026-03-03 09:01:00', []);
        $largeResultMessageId = $this->insertToolCallMessage('search', '2026-03-04 09:02:00', ['arguments' => ['query' => 'second'], 'result' => 'abcdefghij']);
        $this->insertToolCallMessage('read', '2026-03-04 09:03:00', ['result' => str_repeat('r', 40)]);
        $dBAIConversationMessage = new DBAIConversationMessage();

        $samples = $dBAIConversationMessage->findLargestToolResponseSamples('search', $this->getStartOfMarch(), $this->getEndOfMarch())->getElements();

        self::assertSame(
            [$largeResultMessageId, $smallResultMessageId, $withoutResultMessageId],
            [$samples[0]->messageId, $samples[1]->messageId, $samples[2]->messageId]
        );
        self::assertSame([10, 5, 0], [$samples[0]->resultChars, $samples[1]->resultChars, $samples[2]->resultChars]);
        self::assertSame(self::MONDAY_CONVERSATION_ID, $samples[0]->conversationId);
        self::assertSame('2026-03-04 09:02:00', $samples[0]->created?->format('Y-m-d H:i:s'));
        self::assertGreaterThan(0, $samples[0]->argumentsChars);
        self::assertSame(0, $samples[2]->argumentsChars, 'a call without arguments counts no characters');

        $largestSample = $dBAIConversationMessage->findLargestToolResponseSamples('search', $this->getStartOfMarch(), $this->getEndOfMarch(), 1)->getElements();
        self::assertCount(1, $largestSample);
        self::assertSame($largeResultMessageId, $largestSample[0]->messageId);
    }

    protected function getStartOfMarch(): DateTime
    {
        return new DateTime('2026-03-01 00:00:00');
    }

    protected function getEndOfMarch(): DateTime
    {
        return new DateTime('2026-03-31 23:59:59');
    }

    /**
     * Four conversations a customer started (one of them without an account) and one sub-agent of the first.
     */
    protected function insertTheConversationsOfMarch(): void
    {
        $this->insertConversation(self::MONDAY_CONVERSATION_ID, '2026-03-02 09:00:00', self::FIRST_ACCOUNT_ID);
        $this->insertConversation(self::WEDNESDAY_CONVERSATION_ID, '2026-03-04 10:00:00', self::SECOND_ACCOUNT_ID);
        $this->insertConversation(self::LATE_WEDNESDAY_CONVERSATION_ID, '2026-03-04 23:30:00', self::FIRST_ACCOUNT_ID);
        $this->insertConversation(self::NEXT_MONDAY_CONVERSATION_WITHOUT_ACCOUNT_ID, '2026-03-09 08:00:00', null);
        $this->insertMessage(self::MONDAY_CONVERSATION_ID, '2026-03-02 09:04:00', [
            'id' => self::DELEGATE_MESSAGE_ID,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'authorType' => AIConversationMessage::AUTHOR_ASSISTANT,
            'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL,
            'toolCall' => json_encode(['toolCallId' => 'call-delegate', 'name' => 'delegate', 'arguments' => []]),
        ]);
        $this->insertConversation(self::SUB_AGENT_CONVERSATION_ID, '2026-03-02 09:05:00', self::FIRST_ACCOUNT_ID, [
            'delegatedFromMessageId' => self::DELEGATE_MESSAGE_ID,
            'rootConversationId' => self::MONDAY_CONVERSATION_ID,
        ]);
    }

    protected function insertTheUserMessagesOfMarch(): void
    {
        $this->insertUserMessage(self::MONDAY_CONVERSATION_ID, '2026-03-02 09:01:00');
        $this->insertUserMessage(self::MONDAY_CONVERSATION_ID, '2026-03-02 09:10:00');
        $this->insertUserMessage(self::WEDNESDAY_CONVERSATION_ID, '2026-03-04 10:01:00');
        $this->insertUserMessage(self::LATE_WEDNESDAY_CONVERSATION_ID, '2026-03-04 23:31:00');
        $this->insertUserMessage(self::LATE_WEDNESDAY_CONVERSATION_ID, '2026-03-05 00:10:00');
        $this->insertUserMessage(self::NEXT_MONDAY_CONVERSATION_WITHOUT_ACCOUNT_ID, '2026-03-09 08:01:00');
        // an answer of the model on a day of its own: no account was active on it
        $this->insertMessage(self::WEDNESDAY_CONVERSATION_ID, '2026-03-06 10:02:00', [
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'authorType' => AIConversationMessage::AUTHOR_ASSISTANT,
        ]);
    }

    /**
     * A root with a sub-agent and a supervisor, each with rows and overhead costs of its own; a second root with one
     * expensive row; a third root without an account with five rows that cost nothing.
     */
    protected function insertTheConversationTreeWithCosts(): void
    {
        $this->insertConversation(self::MONDAY_CONVERSATION_ID, '2026-03-02 09:00:00', self::FIRST_ACCOUNT_ID, ['overheadCostsInUSD' => 0.5]);
        $this->insertUserMessage(self::MONDAY_CONVERSATION_ID, '2026-03-02 09:01:00');
        $this->insertMessage(self::MONDAY_CONVERSATION_ID, '2026-03-02 09:04:00', [
            'id' => self::DELEGATE_MESSAGE_ID,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'authorType' => AIConversationMessage::AUTHOR_ASSISTANT,
            'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL,
            'costInUSD' => 0.10,
            'inputTokens' => 100,
            'outputTokens' => 20,
            'toolCall' => json_encode([
                'toolCallId' => 'call-delegate',
                'name' => 'delegate',
                'arguments' => [],
                'executionCosts' => ['totalCostInUSD' => 0.05],
            ]),
        ]);
        $this->insertConversation(self::SUB_AGENT_CONVERSATION_ID, '2026-03-02 09:05:00', self::FIRST_ACCOUNT_ID, [
            'delegatedFromMessageId' => self::DELEGATE_MESSAGE_ID,
            'rootConversationId' => self::MONDAY_CONVERSATION_ID,
            'overheadCostsInUSD' => 0.25,
        ]);
        $this->insertMessage(self::SUB_AGENT_CONVERSATION_ID, '2026-03-02 09:06:00', [
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'authorType' => AIConversationMessage::AUTHOR_ASSISTANT,
            'costInUSD' => 0.20,
            'inputTokens' => 50,
            'outputTokens' => 10,
        ]);
        $this->insertConversation(self::SUPERVISOR_CONVERSATION_ID, '2026-03-02 09:07:00', self::FIRST_ACCOUNT_ID, [
            'supervisedConversationId' => self::MONDAY_CONVERSATION_ID,
            'overheadCostsInUSD' => 0.125,
        ]);
        $this->insertMessage(self::SUPERVISOR_CONVERSATION_ID, '2026-03-02 09:08:00', [
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'authorType' => AIConversationMessage::AUTHOR_ASSISTANT,
            'costInUSD' => 0.30,
            'inputTokens' => 10,
            'outputTokens' => 5,
        ]);

        $this->insertConversation(self::WEDNESDAY_CONVERSATION_ID, '2026-03-04 10:00:00', self::SECOND_ACCOUNT_ID);
        $this->insertMessage(self::WEDNESDAY_CONVERSATION_ID, '2026-03-04 10:01:00', [
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'authorType' => AIConversationMessage::AUTHOR_ASSISTANT,
            'costInUSD' => 2.0,
            'inputTokens' => 10,
            'outputTokens' => 10,
        ]);

        $this->insertConversation(self::NEXT_MONDAY_CONVERSATION_WITHOUT_ACCOUNT_ID, '2026-03-09 08:00:00', null);
        for ($minute = 1; $minute <= 5; $minute++) {
            $this->insertUserMessage(self::NEXT_MONDAY_CONVERSATION_WITHOUT_ACCOUNT_ID, '2026-03-09 08:0' . $minute . ':00');
        }
    }

    /** @param array<string, mixed> $furtherColumnValues */
    protected function insertConversation(int $conversationId, string $created, ?int $accountId, array $furtherColumnValues = []): void
    {
        $this->insertRow(
            DBAIConversationModel::getTableName(),
            ['id' => $conversationId, 'created' => $created, 'accountId' => $accountId] + $furtherColumnValues
        );
    }

    protected function insertUserMessage(int $conversationId, string $created): int
    {
        return $this->insertMessage($conversationId, $created, [
            'role' => AIConversationMessage::ROLE_USER,
            'authorType' => AIConversationMessage::AUTHOR_USER,
        ]);
    }

    /**
     * A tool call row of the first conversation.
     *
     * @param array<string, mixed> $furtherToolCallFields the `arguments` and the `result` of the stored call, where it has them
     */
    protected function insertToolCallMessage(string $toolName, string $created, array $furtherToolCallFields): int
    {
        return $this->insertMessage(self::MONDAY_CONVERSATION_ID, $created, [
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'authorType' => AIConversationMessage::AUTHOR_ASSISTANT,
            'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL,
            'toolCall' => json_encode(['toolCallId' => 'call-' . $this->nextMessageId, 'name' => $toolName] + $furtherToolCallFields),
        ]);
    }

    /**
     * @param array<string, mixed> $furtherColumnValues
     * @return int The id of the inserted row
     */
    protected function insertMessage(int $conversationId, string $created, array $furtherColumnValues): int
    {
        $messageId = (int)($furtherColumnValues['id'] ?? $this->nextMessageId++);
        $indexInConversation = $this->nextIndexInConversationByConversationId[$conversationId] ?? 0;
        $this->nextIndexInConversationByConversationId[$conversationId] = $indexInConversation + 1;
        $this->insertRow(DBAIConversationMessageModel::getTableName(), $furtherColumnValues + [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'message' => '',
            'created' => $created,
        ]);
        return $messageId;
    }

    /**
     * @return float[] The value of every bucket by its day (`Y-m-d`), in the order the sequence carries them
     */
    protected function getValuesByDay(DateValueSequence $dateValueSequence): array
    {
        $valuesByDay = [];
        foreach ($dateValueSequence->getElements() as $dateValue) {
            $valuesByDay[$dateValue->x->format('Y-m-d')] = $dateValue->y;
        }
        return $valuesByDay;
    }

    /**
     * @return int[] The conversation ids in the order of the set
     */
    protected function getConversationIds(AgentConversationStats $agentConversationStats): array
    {
        $conversationIds = [];
        foreach ($agentConversationStats->getElements() as $agentConversationStat) {
            $conversationIds[] = $agentConversationStat->conversationId;
        }
        return $conversationIds;
    }
}

class AgentConversationStatWithShopId extends AgentConversationStat
{
    public ?int $shopId = null;
}

class AgentConversationStatsWithShopIds extends AgentConversationStats
{
}
