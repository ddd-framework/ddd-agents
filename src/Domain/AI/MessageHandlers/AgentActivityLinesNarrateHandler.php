<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Worker of the activity-line narration. Thin: restore auth → load the root conversation → the
 * service's sync path. Best-effort and never retried: a late line is worthless, the next step schedules again.
 */
#[AsMessageHandler(fromTransport: AgentMessengerTransports::ACTIVITY_LINES)]
class AgentActivityLinesNarrateHandler extends AIDomainMessageHandler
{
    use AITraceLogTrait;

    public function __invoke(AgentActivityLinesNarrateMessage $message): void
    {
        set_time_limit(60);
        $this->setAuthAccountFromMessage($message);
        try {
            $rootConversation = AIConversation::getService()->find($message->rootConversationId, false);
            if (!$rootConversation instanceof AIConversation) {
                $this->aiTrace('activityLines.skipped', ['conversationId' => $message->rootConversationId, 'reason' => 'conversation not found']);
                return;
            }
            /** @var AgentActivityLinesService $agentActivityLinesService */
            $agentActivityLinesService = DDDService::instance()->getService(AgentActivityLinesService::class);
            $agentActivityLinesService->narrateTurnActivityLines($rootConversation, async: false);
        } catch (Throwable $t) {
            $this->aiTrace('activityLines.error', ['conversationId' => $message->rootConversationId, 'message' => $t->getMessage()]);
            $this->logIssue($t, $message, "AgentActivityLinesNarrateHandler: narration failed for conversation {$message->rootConversationId}");
        }
    }
}
