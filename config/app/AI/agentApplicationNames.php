<?php

declare(strict_types=1);

/**
 * The names an application gives to what the agent core writes outside a conversation — read as
 * `Config::get('AI.agentApplicationNames')` by {@see \DDD\Domain\AI\Entities\Environment\AgentApplicationNames}. An
 * application replaces this file whole with its own `config/app/AI/agentApplicationNames.php` (keys are never merged).
 *
 * `traceLinePrefix`: the prefix of every trace line in the log (existing log filters of an application match it).
 * `consoleAssistantLabel`: the label in front of the assistant's lines on the console (`<label>> text`).
 * `furtherEnvironmentVariableNames`: per environment variable of the core
 * ({@see \DDD\Domain\AI\Entities\Environment\AgentEnvironmentVariables}) a further name under which the
 * application's hosts set it, read after the core's own name — `['AGENT_TRACE' => 'SHOP_TRACE']`.
 */
return [
    'traceLinePrefix' => '[agent:trace]',
    'consoleAssistantLabel' => 'agent',
    'furtherEnvironmentVariableNames' => [],
];
