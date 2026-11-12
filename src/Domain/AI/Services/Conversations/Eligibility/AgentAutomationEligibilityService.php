<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Eligibility;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Whether the platform may run automated AI work for a space or an account right now — asked before a schedule fires,
 * a worker runs, a report is delivered and a tool executes — and the audited support override that lifts the refusal
 * for one run.
 *
 * Every default answers "eligible", "never refuses" and "no override": an application without account states runs
 * every automation. An application puts a same-path subclass under its own root namespace; the core resolves this
 * service only through `DDDService::instance()->getService(AgentAutomationEligibilityService::class)`. The support
 * override is held by the application (process state); the core asks the INSTANCE methods, never a static.
 */
class AgentAutomationEligibilityService extends Service
{
    /**
     * The eligibility of `$agentSpace`. The default: eligible. Work without a space is asked with
     * {@see self::findEligibilityForAccountId()}.
     *
     * @param AgentSpace $agentSpace
     * @param bool $useCache false = read past the application's cache
     * @return AgentAutomationEligibility
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findEligibilityForAgentSpace(AgentSpace $agentSpace, bool $useCache = true): AgentAutomationEligibility
    {
        return AgentAutomationEligibility::eligible();
    }

    /**
     * The eligibility of account `$accountId`. The default: eligible.
     *
     * @param int $accountId
     * @param bool $useCache false = read past the application's cache
     * @return AgentAutomationEligibility
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findEligibilityForAccountId(int $accountId, bool $useCache = true): AgentAutomationEligibility
    {
        return AgentAutomationEligibility::eligible($accountId);
    }

    /**
     * Whether `$eligibility` refuses `$operation` (logged with `$logContext` when it does; an active support override
     * lifts the refusal in the application's implementation). The default never refuses.
     *
     * @param AgentAutomationEligibility $eligibility
     * @param string $operation e.g. `scheduleFire`, `reportDelivery`, `toolCall`
     * @param AgentEligibilityLogContext $logContext
     * @return bool
     */
    public function refusesAutomation(AgentAutomationEligibility $eligibility, string $operation, AgentEligibilityLogContext $logContext): bool
    {
        return false;
    }

    /**
     * Whether `$agentSpace` refuses `$operation`. The default never refuses. Work without a space is asked with
     * {@see self::refusesAutomationForAccountId()}.
     *
     * @param AgentSpace $agentSpace
     * @param string $operation
     * @param AgentEligibilityLogContext $logContext
     * @return bool
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function refusesAutomationForAgentSpace(AgentSpace $agentSpace, string $operation, AgentEligibilityLogContext $logContext): bool
    {
        return false;
    }

    /**
     * Whether account `$accountId` refuses `$operation`. The default never refuses.
     *
     * @param int $accountId
     * @param string $operation
     * @param AgentEligibilityLogContext $logContext
     * @return bool
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function refusesAutomationForAccountId(int $accountId, string $operation, AgentEligibilityLogContext $logContext): bool
    {
        return false;
    }

    /**
     * Activates the audited support override for the current run and returns the note that was active before (restore
     * it with {@see restoreSupportOverride()} in a `finally`). The default holds no override state: null.
     *
     * @param string $auditNote who / which run lifts the refusal
     * @return string|null the prior note
     */
    public function activateSupportOverride(string $auditNote): ?string
    {
        return null;
    }

    /**
     * Restores the support override note that was active before {@see activateSupportOverride()}. The default does
     * nothing.
     *
     * @param string|null $priorNote
     * @return void
     */
    public function restoreSupportOverride(?string $priorNote): void
    {
    }

    /**
     * Whether a support override is active. The default: false.
     *
     * @return bool
     */
    public function isSupportOverrideActive(): bool
    {
        return false;
    }

    /**
     * The note of the active support override. The default: null.
     *
     * @return string|null
     */
    public function getActiveSupportOverrideNote(): ?string
    {
        return null;
    }
}
