<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services;

use DDD\Domain\AI\Entities\MCPPackages\MCPServerProvidedInput;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use Throwable;

/** The executor with the issues log replaced: it records what was logged and answers a fixed issue id. */
class IssueIdStubbedToolExecutor extends MCPToolExecutorService
{
    /** @var string[] The messages of the throwables handed to the issues log, in order */
    public array $loggedMessages = [];

    public ?string $stubbedIssueId = 'a1b2c3';

    public function callBuildToolFailureText(string $toolName, Throwable $throwable, ?MCPServerProvidedInput $serverProvidedInput = null): string
    {
        return $this->buildToolFailureText($toolName, $throwable, $serverProvidedInput);
    }

    protected function logInternalToolFailure(string $toolName, Throwable $throwable): ?string
    {
        $this->loggedMessages[] = $throwable->getMessage();
        return $this->stubbedIssueId;
    }
}
