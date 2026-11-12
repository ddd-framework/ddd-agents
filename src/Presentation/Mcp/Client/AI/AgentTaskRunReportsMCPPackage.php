<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Reports\AIConversationEarlierRunReport;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\MCPPackages\MCPHiddenQueryOptions;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Presentation\Mcp\Client\AI\Dtos\PreviousTaskRunReportsGetRequestDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\PreviousTaskRunReportsMcpResponseDto;

/**
 * The reports the earlier runs of a scheduled run's OWN schedule wrote for the owner:
 * the run reads them to keep its report's structure and to state what changed since. Offered only inside a
 * `Schedule.Run` conversation (the purpose preloads its skill); the schedule comes from the run itself, never from an
 * argument.
 */
class AgentTaskRunReportsMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.taskRunReports';

    public static ?string $description = 'Inside a scheduled run: read the reports the earlier runs of the same task wrote for the owner.';

    /** @var int Earlier reports one call returns at most. */
    public const int MAX_REPORTS = 3;

    /** @var int Earlier runs looked at for those reports — runs that reported nothing are skipped. */
    public const int MAX_EARLIER_RUNS_LOOKED_AT = 12;

    public const string NOTE_NO_SCHEDULED_RUN = 'This conversation is no scheduled run — there are no earlier reports of its task.';

    public const string NOTE_FIRST_REPORT = 'This is the first report of this task: no earlier run wrote one. Compose the report from the task.';

    /**
     * The reports the earlier runs of THIS task wrote for the owner, newest first: the run, its day, the subject and the
     * report text with its widget blocks and their values, and whether the owner changed the task since. Call it once
     * before writing the report: keep the sections and widgets of the newest report in the same order, update the
     * values, and say what changed since then. Runs that reported nothing or ended on a notice are skipped.
     *
     * @example {"count": 1}
     * @example {"count": 2}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads stored reports of finished runs')]
    #[MCPHiddenQueryOptions('noCache')]
    #[MCPToolSummary('Inside a scheduled run: the reports the earlier runs of THIS task wrote for the owner (subject, text with its blocks and values, day; whether the task changed since), newest first — to keep the report\'s structure and state what changed. count 1–3.')]
    public function getPreviousTaskRunReports(
        PreviousTaskRunReportsGetRequestDto $requestDto,
        AIConversationsService $aIConversationsService,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService,
        AIConversationMessagesService $aIConversationMessagesService,
        AgentToolCallProvenance $callProvenance
    ): PreviousTaskRunReportsMcpResponseDto {
        $responseDto = new PreviousTaskRunReportsMcpResponseDto();
        // the run is the tree's root: a sub-agent of the run inherits its schedule and reads the same reports
        $runConversation = $aIConversationsService->findRootConversationForToolCall($callProvenance);
        $scheduleId = $runConversation instanceof AIConversation ? (int)($runConversation->scheduleId ?? 0) : 0;
        $schedule = $scheduleId > 0 ? $aIConversationSchedulesService->find($scheduleId) : null;
        if (!$runConversation instanceof AIConversation || !$schedule instanceof AIConversationSchedule) {
            $responseDto->note = self::NOTE_NO_SCHEDULED_RUN;
            return $responseDto;
        }
        $reportCount = max(1, min(self::MAX_REPORTS, (int)($requestDto->count ?? 1)));
        foreach ($aIConversationsService->findEarlierRunConversationsOfSchedule($schedule, $runConversation, self::MAX_EARLIER_RUNS_LOOKED_AT) as $earlierRunConversation) {
            $ownerReport = $aIConversationOwnerReportDeliveryService->findModelWrittenOwnerReportOfRun($earlierRunConversation);
            if ($ownerReport === null) {
                continue;
            }
            $earlierRunReport = new AIConversationEarlierRunReport();
            $earlierRunReport->conversationId = (int)$earlierRunConversation->id;
            $earlierRunReport->startedAt = $earlierRunConversation->changeHistory->createdTime ?? null;
            $earlierRunReport->ownerReport = $ownerReport;
            $runSeedMessage = $aIConversationMessagesService->findOwnerTextMessagesInOrder($earlierRunConversation)?->first();
            $earlierRunReport->taskChangedSince = !$schedule->isCurrentTaskOfRunSeed((string)($runSeedMessage->message ?? ''));
            $responseDto->reports[] = $earlierRunReport;
            if (count($responseDto->reports) >= $reportCount) {
                break;
            }
        }
        if ($responseDto->reports === []) {
            $responseDto->note = self::NOTE_FIRST_REPORT;
        }
        return $responseDto;
    }
}
