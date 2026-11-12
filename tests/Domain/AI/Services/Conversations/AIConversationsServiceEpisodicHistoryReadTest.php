<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The earlier sessions a new conversation is told about: a conversation of a space reads the sessions of that space;
 * a conversation at account level works for the account as a whole and reads the sessions of the whole account. The
 * second read is a read of its own, named after what it selects — an empty space never widens the first one.
 */
class AIConversationsServiceEpisodicHistoryReadTest extends UnitTestCase
{
    public function testAConversationOfASpaceReadsTheSessionsOfItsSpace(): void
    {
        $conversation = $this->buildNewConversation(41, 11);
        $conversation->agentSpaceId = 21;
        $aIConversationsService = new AIConversationsServiceRecordingRecentSessionReads();

        $aIConversationsService->composeAndPersistEpisodicHistory($conversation);

        self::assertSame([['accountId' => 11, 'agentSpaceId' => 21, 'excludeConversationId' => 41]], $aIConversationsService->readsOfOneSpace);
        self::assertSame([], $aIConversationsService->readsAcrossTheSpacesOfAnAccount);
    }

    public function testAConversationAtAccountLevelReadsTheSessionsOfTheWholeAccount(): void
    {
        $conversation = $this->buildNewConversation(42, 11);
        $aIConversationsService = new AIConversationsServiceRecordingRecentSessionReads();

        $aIConversationsService->composeAndPersistEpisodicHistory($conversation);

        self::assertSame([['accountId' => 11, 'excludeConversationId' => 42]], $aIConversationsService->readsAcrossTheSpacesOfAnAccount);
        self::assertSame([], $aIConversationsService->readsOfOneSpace);
    }

    public function testAConversationWithoutAnAccountReadsNothing(): void
    {
        $conversation = new AIConversationWithoutAPurposeFixture();
        $conversation->id = 43;
        $aIConversationsService = new AIConversationsServiceRecordingRecentSessionReads();

        $aIConversationsService->composeAndPersistEpisodicHistory($conversation);

        self::assertSame([], $aIConversationsService->readsOfOneSpace);
        self::assertSame([], $aIConversationsService->readsAcrossTheSpacesOfAnAccount);
    }

    public function testTheQueryBothReadsShareCarriesNoConditionOnTheAccountOrTheSpace(): void
    {
        $baseModelAlias = DBAIConversations::getBaseModelAlias();

        /** @var DoctrineQueryBuilder $queryBuilder */
        $queryBuilder = self::invokeMethod(new AIConversationsService(), 'buildRecentConversationsWithSummariesQueryBuilder', [31, 0]);

        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString("$baseModelAlias.delegatedFromMessageId IS NULL", $dql, 'root conversations only');
        self::assertStringContainsString("$baseModelAlias.summary IS NOT NULL", $dql);
        self::assertStringContainsString("$baseModelAlias.id != :episodicExcludeId", $dql);
        self::assertSame(31, $queryBuilder->getParameter('episodicExcludeId')->getValue());
        self::assertSame(1, $queryBuilder->getMaxResults(), 'at least one row');
        self::assertStringNotContainsString("$baseModelAlias.accountId", $dql, 'the caller adds the account');
        self::assertStringNotContainsString("$baseModelAlias.agentSpaceId", $dql, 'the caller adds the space, or none for the read across spaces');
    }

    protected function buildNewConversation(int $conversationId, int $accountId): AIConversationWithoutAPurposeFixture
    {
        $conversation = new AIConversationWithoutAPurposeFixture();
        $conversation->id = $conversationId;
        $conversation->accountId = $accountId;
        return $conversation;
    }
}
