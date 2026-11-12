<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentBackgroundOperationOutcome;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Infrastructure\Services\Service;

/**
 * The mechanical operations a hidden background run carries out besides a tool run (an application's product operation,
 * e.g. a check of a record the space holds). The operation names and their labels are the application's
 * (`BackgroundMechanicalOperationPurpose` names the core's own: the tool run): a tool result may request exactly the
 * names {@see getSupportedOperationNames()} lists, the sub-agent's seed message starts with {@see getOperationLabel()},
 * and the run calls {@see runMechanicalOperation()}.
 *
 * The default supports no operation and reports it as failed. An application puts a same-path subclass under its own
 * root namespace; the core resolves this service only through
 * `DDDService::instance()->getService(AgentBackgroundOperationsService::class)`.
 */
class AgentBackgroundOperationsService extends Service
{
    /**
     * @return string[] The names of the operations the application carries out; a requested name outside this list
     *                  (and outside the tool run) starts no sub-agent.
     */
    public function getSupportedOperationNames(): array
    {
        return [];
    }

    /**
     * The label line of the seed message of one operation — what the requester's sub-agent list shows as the task.
     *
     * @param string $operation the operation name
     * @return string|null null: the seed is labelled with the operation's name
     */
    public function getOperationLabel(string $operation): ?string
    {
        return null;
    }

    /**
     * Carries out `$operation` for `$conversation` (the background run) and returns its outcome.
     *
     * @param AIConversation $conversation the hidden background run
     * @param string $operation the operation name from the run's seed payload
     * @return AgentBackgroundOperationOutcome
     */
    public function runMechanicalOperation(AIConversation $conversation, string $operation): AgentBackgroundOperationOutcome
    {
        return AgentBackgroundOperationOutcome::failed('Background job failed: the operation "' . $operation . '" is not supported.');
    }
}
