<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A delegation batch references its parent conversation by id, and the end of a sub-agent finds the parent through
 * that id. A conversation that is not stored has none: the batch is refused before the write, with the reason, in
 * both ways a batch is created.
 */
class DelegationBatchNeedsAStoredParentConversationTest extends UnitTestCase
{
    public function testABatchForAParentConversationThatIsNotStoredIsRefusedBeforeTheWrite(): void
    {
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('A conversation that is not stored cannot delegate');

        (new AIConversationDelegationBatchesService())->createBatch(new AIConversation(), 2);
    }

    public function testTheDeadlineRowOfOneSubAgentRunIsRefusedForAParentConversationThatIsNotStored(): void
    {
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('A conversation that is not stored cannot delegate');

        (new AIConversationDelegationBatchesService())->createSubAgentRunDeadline(new AIConversation(), new AIConversationMessage(), 60);
    }
}
