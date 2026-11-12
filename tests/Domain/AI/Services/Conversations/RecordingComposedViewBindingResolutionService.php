<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\ComposedViewBindingResolutionService;

/** Records the rows the hook saw. */
class RecordingComposedViewBindingResolutionService extends ComposedViewBindingResolutionService
{
    /** @var AIConversationMessage[] */
    public array $resolvedRows = [];

    public function resolveBindingsOfRowBeforePersist(AIConversationMessage $message): void
    {
        $this->resolvedRows[] = $message;
        parent::resolveBindingsOfRowBeforePersist($message);
    }
}
