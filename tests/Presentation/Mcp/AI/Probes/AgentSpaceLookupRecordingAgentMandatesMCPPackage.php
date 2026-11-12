<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI\Probes;

use DDD\Presentation\Mcp\Client\AI\AgentMandatesMCPPackage;

/** The package's own mandate tools, with the space of a call answered by the test. */
class AgentSpaceLookupRecordingAgentMandatesMCPPackage extends AgentMandatesMCPPackage
{
    use RecordsAgentSpaceLookupOfMandateCallTrait;
}
