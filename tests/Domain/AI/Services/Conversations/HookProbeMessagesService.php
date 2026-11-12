<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\ComposedViewBindingResolutionService;

/** The messages service with the hook's service replaced (the method the production code resolves it through). */
class HookProbeMessagesService extends AIConversationMessagesService
{
    public function __construct(protected ComposedViewBindingResolutionService $probeResolution = new RecordingComposedViewBindingResolutionService())
    {
    }

    protected function composedViewBindingResolutionService(): ComposedViewBindingResolutionService
    {
        return $this->probeResolution;
    }
}
