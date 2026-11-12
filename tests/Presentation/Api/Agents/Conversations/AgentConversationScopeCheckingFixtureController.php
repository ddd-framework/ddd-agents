<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;

/**
 * A consuming application's controller that names the space of a start in its own way: never by the request's
 * `agentSpaceId`, always the one space its caller works for (none: every start is at account level).
 *
 * It runs as an admin session, so a start through it leaves the process-wide hidden properties of the conversation
 * classes as they are (the customer wire hides them statically, which would reach every later test of the run).
 */
class AgentConversationScopeCheckingFixtureController extends AgentConversationTraitsFixtureController
{
    /** @var AgentSpace|null The one space the caller of this application works for; null = none */
    public ?AgentSpace $agentSpaceOfTheCaller = null;

    /** @var int How many start requests the application was asked to name the space of */
    public int $startRequestsAskedForTheirSpace = 0;

    protected function findAgentSpaceOfStartRequest(AIConversationStartWithMessagePostRequestDto $requestDto): ?AgentSpace
    {
        $this->startRequestsAskedForTheirSpace++;
        return $this->agentSpaceOfTheCaller;
    }

    protected function isAdminSession(): bool
    {
        return true;
    }
}
