<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Quality\AgentConversationScorecardsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Worker for the conversation MEASUREMENT and ASSESSMENT: restore auth → load fresh → the
 * scorecard of the application's quality component ({@see AgentConversationScorecardsService::writeScorecardOfFinalizedConversation()}:
 * the finalize write, the assessment claim, the quality analysis) → log. Runs on its own `ai_conversation_scorecard`
 * transport, dispatched after the completion compaction — it never holds up a turn, and a backfill or a burst of
 * finalizes never delays a compaction. Failures
 * are logged, never rethrown: the scorecard command's `--missing` mode re-selects what did not land.
 */
#[AsMessageHandler(fromTransport: AgentMessengerTransports::SCORECARD)]
class AIConversationScorecardHandler extends AIDomainMessageHandler
{
    use AITraceLogTrait;

    public function __invoke(AIConversationScorecardMessage $message): void
    {
        set_time_limit(900); // a long conversation's segments run concurrently, the final call after them
        ini_set('memory_limit', '512M');

        $this->setAuthAccountFromMessage($message);
        try {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $conversation = $aIConversationsService->find($message->aiConversationId, useEntityRegistrCache: false);
            if (!$conversation instanceof AIConversation) {
                return;
            }
            // The scorecard reads a PAST conversation and acts for nobody: an ended account or a deleted agent space (the
            // churned customers — the most telling conversations for the quality intelligence) is still analysed; only
            // an account whose AI data was erased has nothing left to read. The eligibility gate of the autonomous
            // spenders would refuse every ENDED / SUSPENDED scope and left thousands of customer chats unscored.
            $eligibility = $aIConversationsService->findAutomationEligibilityForConversation($conversation);
            if ($eligibility->state === AgentAutomationEligibility::STATE_ERASED) {
                $this->aiTrace('scorecard.skipped', ['conversationId' => $message->aiConversationId, 'reason' => $eligibility->reason]);
                return;
            }
            /** @var AgentConversationScorecardsService $agentConversationScorecardsService */
            $agentConversationScorecardsService = DDDService::instance()->getService(AgentConversationScorecardsService::class);
            $analysisResult = $agentConversationScorecardsService->writeScorecardOfFinalizedConversation($conversation, $message->forceAnalysis);
            $this->aiTrace('scorecard.done', ['conversationId' => $message->aiConversationId, 'analysis' => $analysisResult]);
        } catch (Throwable $t) {
            $this->aiTrace('scorecard.error', ['conversationId' => $message->aiConversationId, 'message' => $t->getMessage()]);
            $this->logShortException($this->getLogger(), "AIConversationScorecardHandler: scorecard failed for conversation {$message->aiConversationId}", $t);
        }
    }
}
