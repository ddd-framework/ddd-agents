<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/** A conversation that answers the question for its purpose itself (none), so that a test needs no purpose service. */
class AIConversationWithoutAPurposeFixture extends AIConversation
{
    public function findPurpose(): ?AIConversationPurpose
    {
        return null;
    }
}
