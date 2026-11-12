<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AIConversationTurnOptions;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The publications of a message row the turn has written — its recall embedding, its
 * console line and the realtime tick that tells the clients to read it. Run once the row is visible to other
 * processes (the caller hands it to {@see AIConversationMessagesService::publishAfterCommit()}); never writes a row.
 * The messages and realtime ports come from the caller, which owns them for the turn (its test doubles included).
 */
class AIConversationMessagePublicationService extends Service
{
    public function publishWrittenMessage(
        AgentRun $agentRun,
        AIConversationMessage $message,
        ?AIConversation $conversation,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal,
        AIConversationMessagesService $aIConversationMessagesService,
        AIConversationRealtimeSignalService $realtimeSignalService
    ): void {
        // the run that wrote the row: how it does its side work, and where this persist's breakdown is measured
        $turnOptions = $agentRun->turnOptions;
        $turnTimings = $agentRun->turnTimings;
        // Embed this message via the service's `bool $async` seam. The flag follows the turn's
        // sync/async split ({@see AIConversationTurnOptions::$embedMessagesAsync}): the CLI/eval path embeds INLINE, the
        // HTTP/worker path dispatches the embed OFF the turn. Best-effort — a failure never breaks the turn; the
        // backfill re-embeds.
        if ($turnOptions->embedMessagesEnabled) {
            $publicationStartedAt = hrtime(true);
            try {
                $aIConversationMessagesService->embedMessage($message, async: $turnOptions->embedMessagesAsync);
            } catch (Throwable) {
                // swallow — embedding is best-effort + off the critical path; the backfill command re-embeds misses.
            }
            $turnTimings->lastPersistBreakdownMs['embed'] = (int)round((hrtime(true) - $publicationStartedAt) / 1e6);
        }
        // Stream the (now id-bearing, if persisted) message to the console — with its conversation as delegation
        // context (the renderer derives the gutter from it).
        $consoleStream?->streamMessage($message, $conversation);
        // Per-message realtime tick — only when the worker enabled it (async drive) AND the conversation is
        // persisted. Best-effort (publishChangeSignal swallows its own errors).
        if ($emitRealtimeSignal && $conversation !== null && $message->aiConversationId !== null) {
            $publicationStartedAt = hrtime(true);
            $realtimeSignalService->publishChangeSignal($conversation, AIConversationRealtimeSignalService::STATUS_RUNNING, $agentRun->pushConfigMemo);
            $turnTimings->lastPersistBreakdownMs['signal'] = (int)round((hrtime(true) - $publicationStartedAt) / 1e6);
            // the signal service splits its own publish: the Firebase round trip and the A2A config COUNT
            $publishBreakdown = $realtimeSignalService->lastPublishBreakdownMs ?? null;
            $turnTimings->lastPersistBreakdownMs['firebase'] = (int)($publishBreakdown['firebase'] ?? 0);
            $turnTimings->lastPersistBreakdownMs['a2aCount'] = (int)($publishBreakdown['a2aCount'] ?? 0);
        }
    }
}
