<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse;

/**
 * The upward port of a model call: a collaborator of the loop that runs a model call of its own (the
 * supervisor checkpoint) receives the loop as this port — never resolved from DI — so the call goes through the loop's
 * override point and a test double that replaces the model call keeps intercepting it.
 */
interface AgentModelCallRunnerInterface
{
    /**
     * @param array<int, array<string, mixed>> $toolSchemas
     * @param string|array<string, mixed>|null $forceToolChoice
     */
    public function performModelCall(AIConversation $conversation, array $toolSchemas, string|array|null $forceToolChoice = null, ?string $modelNameOverride = null, string $baseSystemPrompt = ''): ModelResponse;
}
