<?php
// placeholder {{NAMESPACE}} sample: App\Symfony\Messenger\Middleware

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Interfaces\AIConversationOverheadCostsMessageInterface;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Host duty (workers, only with a cost recorder): books onto the conversation what the host's usage recorder reports
 * through `AIUsageEnvelopesService::recordBookedCost()` while a consumed conversation job runs (a compaction, a summary,
 * a tool-result screening, what a turn books around its loop), in one atomic statement when the job ends. Only
 * messages that implement `AIConversationOverheadCostsMessageInterface` are recorded. With ddd-ai's default recorder
 * nothing is reported and nothing is booked: the amounts exist only once the host's own recorder (a same-path
 * subclass of ddd-ai's model-call usage recorder) reports them. A host that records costs needs both, its recorder and
 * this middleware, for a correct overhead cost per conversation.
 *
 * Register it after the envelope middleware (see the transport block template).
 */
class AgentConversationOverheadCostsMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if (!$message instanceof AIConversationOverheadCostsMessageInterface || !$envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }
        $aiConversationId = $message->getAIConversationIdForOverheadCosts();
        if ($aiConversationId === null) {
            return $stack->next()->handle($envelope, $stack);
        }
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        $jobBookedCosts = $aIUsageEnvelopesService->startRecordingBookedCosts();
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $aIUsageEnvelopesService->stopRecordingBookedCosts($jobBookedCosts);
            $aIConversationsService->addOverheadCostsToConversation($aiConversationId, $jobBookedCosts);
        }
    }
}
