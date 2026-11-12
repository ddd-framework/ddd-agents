<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Recovery;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Recovery\AIConversationStuckRun;
use DDD\Domain\AI\Entities\Conversations\Recovery\AIConversationStuckRuns;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DBALException;

/**
 * The READ half of the recovery machinery (`findStuckAgentRuns`): the seven candidate sets the reaper
 * and the recovery cron (two console commands of the application) compute before they act — called through the SAME
 * finders with the SAME arguments, then joined with the conversation row facts. Nothing is re-driven, expired, timed
 * out or delivered: the finders are pure SELECTs, and this service calls none of the acting methods.
 */
class AIConversationStuckRunsReadService extends Service
{
    /** The quiet window after which the reaper closes an IDLE conversation whose purpose reached its goal (the reaper's own constant). */
    public const int PURPOSE_SETTLE_QUIET_SECONDS = 600;

    /** @throws DBALException */
    public function findStuckRuns(?DateTime $now = null): AIConversationStuckRuns
    {
        $now ??= new DateTime();
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        /** @var AIConversationDelegationBatchesService $aIConversationDelegationBatchesService */
        $aIConversationDelegationBatchesService = DDDService::instance()->getService(AIConversationDelegationBatchesService::class);

        $stuckRuns = AIConversationStuckRuns::newInstance();
        $stuckRuns->environment = AIConversationsService::currentEnvironment();
        $stuckRuns->readAt = $now->format('Y-m-d H:i:s');

        // the reaper's passes (AgentReapStuckConversationsCommand, the order it runs them in)
        $stuckRuns->addReasonToConversations(AIConversationStuckRun::REASON_NO_HEARTBEAT, $aIConversationsService->findStuckConversationIds());
        $passedDeadlineParentIds = $this->findParentConversationIdsByBatchIds($aIConversationDelegationBatchesService->findTimedOutUnfinishedBatchIds());
        $stuckRuns->addReasonToConversations(AIConversationStuckRun::REASON_DEADLINE_PASSED, array_keys($passedDeadlineParentIds));
        foreach ($passedDeadlineParentIds as $parentConversationId => $batchIds) {
            $stuckRuns->findOrAddStuckRun($parentConversationId)->passedDeadlineBatchIds = $batchIds;
        }
        foreach (AIConversationPurpose::getPurposeNamesClosedWithoutAnOwnerTurn() as $purposeNameClosedWithoutAnOwnerTurn) {
            $stuckRuns->addReasonToConversations(
                AIConversationStuckRun::REASON_QUIET_IDLE_PURPOSED,
                $aIConversationsService->findQuietIdlePurposedConversationIds($purposeNameClosedWithoutAnOwnerTurn, self::PURPOSE_SETTLE_QUIET_SECONDS)
            );
        }
        $stuckRuns->addReasonToConversations(
            AIConversationStuckRun::REASON_UNREAD_CHILD_RESULTS,
            $aIConversationDelegationBatchesService->findParentConversationIdsWithUnreadChildResults()
        );
        // the recovery cron's passes (deliver-owed-reports-and-resume-owed-turns, with its defaults)
        $stuckRuns->addReasonToConversations(AIConversationStuckRun::REASON_OWING_REPORT, $aIConversationsService->findConversationIdsOwingReports());
        $stuckRuns->addReasonToConversations(AIConversationStuckRun::REASON_OWING_CONTINUATION, $aIConversationsService->findConversationIdsOwingContinuations());
        $stuckRuns->addReasonToConversations(AIConversationStuckRun::REASON_INBOUND_LEFT_IDLE, $aIConversationsService->findConversationIdsWithInboundLeftIdle());

        $this->applyConversationRows($stuckRuns, $now);
        return $stuckRuns;
    }

    /**
     * The parent conversation of each batch, with the batch ids per parent.
     *
     * @param int[] $batchIds
     * @return int[][] batch ids by parent conversation id
     * @throws DBALException
     */
    protected function findParentConversationIdsByBatchIds(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }
        $batchRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT `id`, `parentConversationId` FROM `' . DBAIConversationDelegationBatchModel::getTableName() . '` WHERE `id` IN (:batchIds)',
            ['batchIds' => array_values($batchIds)],
            ['batchIds' => ArrayParameterType::INTEGER]
        );
        $batchIdsByParentConversationId = [];
        foreach ($batchRows as $batchRow) {
            $batchIdsByParentConversationId[(int)$batchRow['parentConversationId']][] = (int)$batchRow['id'];
        }
        return $batchIdsByParentConversationId;
    }

    /** The row facts of every listed conversation, one statement, without the working state. @throws DBALException */
    protected function applyConversationRows(AIConversationStuckRuns $stuckRuns, DateTime $now): void
    {
        $conversationIds = $stuckRuns->getConversationIds();
        if ($conversationIds === []) {
            return;
        }
        $conversationRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT `id`, `accountId`, `agentSpaceId`, `purposeName`, `runState`, `rootConversationId`, `runHeartbeatAt`, `lastInboundMessageAt`,'
            . ' `resumeAttempts`, `agentTurnOwedAt`, `reportOwedAt` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` IN (:conversationIds)',
            ['conversationIds' => $conversationIds],
            ['conversationIds' => ArrayParameterType::INTEGER]
        );
        foreach ($conversationRows as $conversationRow) {
            $stuckRuns->findOrAddStuckRun((int)$conversationRow['id'])->applyRow($conversationRow, $now);
        }
    }
}
