<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/** One label (contact group) of My Contacts. */
class AgentConnectionContactGroup extends ValueObject
{
    public const string TYPE_USER = 'USER_CONTACT_GROUP';
    public const string TYPE_SYSTEM = 'SYSTEM_CONTACT_GROUP';

    /** @var string contactGroups/abc */
    public string $groupId = '';
    public string $name = '';
    public string $groupType = self::TYPE_USER;
    public int $memberCount = 0;
}
