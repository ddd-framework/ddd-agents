<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\ComposedViewBindingResolutionService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `$from` hook point: every row the run persists passes through
 * {@see ComposedViewBindingResolutionService::resolveBindingsOfRowBeforePersist()} before it is written (here the
 * transient path, which has no database), and the hook changes nothing until a binding resolver fills it.
 */
class ComposedViewBindingResolutionHookTest extends UnitTestCase
{
    public function testEveryPersistedRowPassesTheHookBeforeItIsWritten(): void
    {
        $recordingResolution = new RecordingComposedViewBindingResolutionService();
        $messagesService = new HookProbeMessagesService($recordingResolution);
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->aiConversation = null;
        $message->aiConversationId = null;
        $message->message = "Visits:\n```widget\n{\"type\":\"ComposedView\",\"view\":{\"type\":\"Widget\"}}\n```";
        $agentRun = AgentRun::forTurnEntry();
        $agentRun->turnOptions->embedMessagesEnabled = false;

        $messagesService->persistMessageUnderRun($agentRun, $message);

        self::assertSame([$message], $recordingResolution->resolvedRows);
        self::assertStringContainsString('"type":"ComposedView"', $message->message, 'the hook changes nothing before L-SERIAL');
    }

    public function testTheHookLeavesARowWithoutAComposedViewUntouched(): void
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->message = 'Plain prose.';
        (new ComposedViewBindingResolutionService())->resolveBindingsOfRowBeforePersist($message);
        self::assertSame('Plain prose.', $message->message);
    }
}
