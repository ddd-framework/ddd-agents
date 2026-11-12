<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\QueryOptions;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversation;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A conversation class of an application: the module's conversation with a column of the application's own next to
 * `agentSpaceId` (the id of the shop a space stands for). As every class of an application that derives a filterable
 * class of the module it names its repository and uses `QueryOptionsTrait` itself: the framework reads both off the
 * class, not off its parents.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversation::class)]
class ApplicationConversationFixture extends AIConversation
{
    use QueryOptionsTrait;

    /** @var int|null The application's own id of the thing the conversation works for */
    public ?int $shopId = null;
}
