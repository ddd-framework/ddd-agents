<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Mandates;

use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Infrastructure\Services\Service;

/**
 * What a revoked mandate changes beyond its own row. A mandate authorizes a class of actions; when the class covers a
 * standing automation of the application (an unattended reply, a recurring run), the customer's withdrawal should stop
 * that automation too, not only future grants. The core knows no automation: the default changes nothing. An
 * application puts a same-path subclass under its own root namespace; the core resolves this service only through
 * `DDDService::instance()->getService(AgentMandateRevocationEffectsService::class)`.
 *
 * Called by {@see AgentMandatesService::revokeMandate()} after the revoke persisted, inside a catch-all: a failing
 * effect never blocks the revoke.
 */
class AgentMandateRevocationEffectsService extends Service
{
    /**
     * Applies the effects of a revoke. The default: none.
     *
     * @param AgentMandate $revokedAgentMandate The mandate as persisted with status REVOKED
     */
    public function onMandateRevoked(AgentMandate $revokedAgentMandate): void
    {
    }
}
