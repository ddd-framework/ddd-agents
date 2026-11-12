<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationMessagePublicationService;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;

/**
 * The publications of a message row a run has written — its recall embedding, its console line, its realtime tick
 * ({@see AIConversationMessagePublicationService::publishWrittenMessage()}) — handed by
 * {@see AIConversationMessagesService::persistMessageUnderRun()} to the claim-fenced write, which publishes it once the
 * row is visible to other processes (a value object instead of a closure). Transient.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationWrittenMessagePublication implements AIConversationMessageWritePublication
{
    protected AgentRun $agentRun;

    protected AIConversationMessage $message;

    /** The conversation the row was written into; null when the message carries none. */
    protected ?AIConversation $conversation = null;

    protected ?AgentConsoleStream $consoleStream = null;

    protected bool $emitRealtimeSignal = false;

    /** The messages service that wrote the row (its test double included). */
    protected AIConversationMessagesService $aIConversationMessagesService;

    protected AIConversationMessagePublicationService $aIConversationMessagePublicationService;

    /** The realtime service the tick goes through (its test double included). */
    protected AIConversationRealtimeSignalService $aIConversationRealtimeSignalService;

    public static function ofWrittenMessage(
        AgentRun $agentRun,
        AIConversationMessage $message,
        ?AIConversation $conversation,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal,
        AIConversationMessagesService $aIConversationMessagesService,
        AIConversationMessagePublicationService $aIConversationMessagePublicationService,
        AIConversationRealtimeSignalService $aIConversationRealtimeSignalService
    ): static {
        $writtenMessagePublication = new static();
        $writtenMessagePublication->agentRun = $agentRun;
        $writtenMessagePublication->message = $message;
        $writtenMessagePublication->conversation = $conversation;
        $writtenMessagePublication->consoleStream = $consoleStream;
        $writtenMessagePublication->emitRealtimeSignal = $emitRealtimeSignal;
        $writtenMessagePublication->aIConversationMessagesService = $aIConversationMessagesService;
        $writtenMessagePublication->aIConversationMessagePublicationService = $aIConversationMessagePublicationService;
        $writtenMessagePublication->aIConversationRealtimeSignalService = $aIConversationRealtimeSignalService;
        return $writtenMessagePublication;
    }

    public function publish(): void
    {
        $this->aIConversationMessagePublicationService->publishWrittenMessage(
            $this->agentRun,
            $this->message,
            $this->conversation,
            $this->consoleStream,
            $this->emitRealtimeSignal,
            $this->aIConversationMessagesService,
            $this->aIConversationRealtimeSignalService
        );
    }
}
