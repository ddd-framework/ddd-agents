<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;

/**
 * The conversation class of an application that writes a nightly digest for itself and sends a reminder without the
 * owner: it declares the initiator types of these two runs and redeclares the two lists of the module's class.
 */
class AIConversationOfAnApplicationWithANightlyDigestRun extends AIConversation
{
    public const string INITIATOR_TYPE_NIGHTLY_DIGEST = 'NIGHTLY_DIGEST';

    public const string INITIATOR_TYPE_REMINDER = 'REMINDER';

    protected const array OWNER_ABSENT_INITIATOR_TYPES = [
        self::INITIATOR_TYPE_SCHEDULE,
        self::INITIATOR_TYPE_ADMIN_TASK,
        self::INITIATOR_TYPE_NIGHTLY_DIGEST,
        self::INITIATOR_TYPE_REMINDER,
    ];

    protected const array PLATFORM_OWN_RUN_INITIATOR_TYPES = [self::INITIATOR_TYPE_NIGHTLY_DIGEST];
}
