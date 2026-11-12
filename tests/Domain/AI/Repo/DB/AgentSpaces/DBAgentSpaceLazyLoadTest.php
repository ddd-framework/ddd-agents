<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\DB\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpace;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The space a row names loads past the read rights ({@see DBAgentSpace::lazyload()}): a conversation, a schedule or a
 * mandate that references a space always yields it, so "no space" keeps its one meaning (account level). The read
 * rights apply again as soon as the load is over, also when it fails.
 */
class DBAgentSpaceLazyLoadTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        DBEntity::setApplyRightsRestrictions(true);
        parent::tearDown();
    }

    protected function conversationOfAgentSpaceId(int $agentSpaceId): AIConversation
    {
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->agentSpaceId = $agentSpaceId;
        return $conversation;
    }

    protected function lazyLoadOfTheRelationToTheSpace(): LazyLoad
    {
        return new LazyLoad(repoType: LazyLoadRepo::DB, propertyContainingId: 'agentSpaceId');
    }

    public function testTheSpaceARowNamesIsReadPastTheReadRightsWhichApplyAgainAfterwards(): void
    {
        DBEntity::setApplyRightsRestrictions(true);
        $spaceRepo = new DBAgentSpaceRecordingRightsRestrictionsStub();
        /** @var AgentSpace $storedAgentSpace */
        $storedAgentSpace = self::instantiateWithoutConstructor(AgentSpace::class);
        $spaceRepo->agentSpaceToReturn = $storedAgentSpace;
        $conversation = $this->conversationOfAgentSpaceId(7);
        $lazyLoadOfTheRelationToTheSpace = $this->lazyLoadOfTheRelationToTheSpace();

        $loadedAgentSpace = $spaceRepo->lazyload($conversation, $lazyLoadOfTheRelationToTheSpace);

        self::assertSame($storedAgentSpace, $loadedAgentSpace);
        self::assertSame([7], $spaceRepo->agentSpaceIdsRead);
        self::assertSame([false], $spaceRepo->readRightsAppliedPerRead, 'The read of the space ran under the read rights.');
        self::assertTrue(DBEntity::getApplyRightsRestrictions(), 'The read rights stayed lifted after the load.');
    }

    public function testTheReadRightsApplyAgainWhenTheLoadFails(): void
    {
        DBEntity::setApplyRightsRestrictions(true);
        $spaceRepo = new DBAgentSpaceRecordingRightsRestrictionsStub();
        $spaceRepo->isReadFailing = true;
        $conversation = $this->conversationOfAgentSpaceId(7);
        $lazyLoadOfTheRelationToTheSpace = $this->lazyLoadOfTheRelationToTheSpace();

        $isLoadFailed = false;
        try {
            $spaceRepo->lazyload($conversation, $lazyLoadOfTheRelationToTheSpace);
        } catch (InternalErrorException) {
            $isLoadFailed = true;
        }

        self::assertTrue($isLoadFailed);
        self::assertTrue(DBEntity::getApplyRightsRestrictions(), 'The read rights stayed lifted after a failed load.');
    }

    public function testALoadThatStartsWithTheReadRightsLiftedLeavesThemLifted(): void
    {
        DBEntity::setApplyRightsRestrictions(false);
        $spaceRepo = new DBAgentSpaceRecordingRightsRestrictionsStub();
        $conversation = $this->conversationOfAgentSpaceId(7);
        $lazyLoadOfTheRelationToTheSpace = $this->lazyLoadOfTheRelationToTheSpace();

        $spaceRepo->lazyload($conversation, $lazyLoadOfTheRelationToTheSpace);

        self::assertFalse(DBEntity::getApplyRightsRestrictions(), 'The load switched the read rights on for the code that had lifted them.');
    }
}
