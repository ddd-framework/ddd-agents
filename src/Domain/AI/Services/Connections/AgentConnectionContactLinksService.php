<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContact;
use DDD\Domain\AI\Entities\Connections\AgentConnection;

/**
 * HOST HOOKS for the links between an application's own records (a support contact, a customer) and the people of a
 * contacts connection. The package keeps no links: an application that has them overrides this service at its same
 * path. Both hooks authorize as well (the acting account may see or move the links); the defaults are inert, so
 * without an override no links are shown and none are moved.
 */
class AgentConnectionContactLinksService
{
    /**
     * HOST HOOK: lines describing the host records linked to the person, readable by the acting account.
     *
     * @return string[]
     */
    public function findHostLinksOfPerson(AgentConnection $agentConnection, AgentConnectionContact $contact, int $actingAccountId): array
    {
        return [];
    }

    /** HOST HOOK: moves the links of the donor to the survivor of a merge; returns how many moved */
    public function retargetHostLinks(AgentConnection $agentConnection, string $donorContactId, string $survivorContactId, int $actingAccountId): int
    {
        return 0;
    }
}
