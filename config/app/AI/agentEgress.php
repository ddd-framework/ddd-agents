<?php

declare(strict_types=1);

/**
 * The HTTP transfer of the agent's model calls ({@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation})
 * — the package default, read as `Config::get('AI.agentEgress')`. An application replaces this file whole with its own
 * `config/app/AI/agentEgress.php` (keys are never merged).
 *
 * `transferStallAbort`: cURL's low-speed abort — the transfer ends as a transport failure when fewer than
 * `bytesPerSecond` bytes per second arrive on average over `seconds` seconds; `seconds` 0 switches it off. The package
 * default is off.
 *
 * `liteLlmEndpoint`: the Argus load endpoint (`POST:https://…/chat/completions`) of the LiteLLM proxy egress, the
 * test-only alternative selected per process with `AGENT_EGRESS=litellm`. null = no proxy: the flip is ignored with a
 * log line and the call keeps the direct endpoint.
 */
return [
    'transferStallAbort' => [
        'bytesPerSecond' => 0,
        'seconds' => 0,
    ],
    'liteLlmEndpoint' => null,
];
