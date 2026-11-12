<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;

/**
 * The base of every worker of the agent: the framework's handler base ({@see AppMessageHandler} — `getLogger()`,
 * `logIssue()`, `logShortException()`, the fresh worker state before every job). The process state the agent's code
 * leaves behind (tool blocks, the decision-model settings, run claims, the usage envelopes, …) is reset before every
 * consumed job by the agent's worker state resetter
 * ({@see \DDD\Domain\AI\Messenger\Conversations\AgentWorkerStateResetter}), an application's own state by the
 * application's resetters — whatever the handler's base. The transports the handlers consume are
 * {@see AgentMessengerTransports}.
 */
abstract class AIDomainMessageHandler extends AppMessageHandler
{
}
