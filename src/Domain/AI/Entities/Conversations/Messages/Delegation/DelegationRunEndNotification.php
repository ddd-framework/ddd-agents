<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The ONE notification of one sub-agent run's end: rendered from the records only — number, label,
 * status, verdict or reason, the parent's other unfinished sub-agents, and the requesting tool's next step once its last
 * sub-agent ended (rendered once: in the notification of its LAST run end). Facts only, no instruction to answer. The `[SIGNAL:…]` prefix is the wire mapper's; this text is the
 * signal row's message.
 *
 * @phpstan-consistent-constructor
 */
class DelegationRunEndNotification extends ValueObject
{
    /** The longest verdict a notification carries; a longer one is cut with a pointer to the drill-down. */
    public const int VERDICT_MAX_LENGTH = 4000;

    /** The other unfinished sub-agents named in the "Still running" line; the rest are counted. */
    public const int STILL_RUNNING_NAMED_MAX = 8;

    /** The number of the sub-agent whose run ended; null for a legacy record. */
    public ?int $subAgentNumber = null;

    /** The run that ended. */
    public int $runNumber = 1;

    /** Whether the notification resumes an idle parent (false: it rides the parent's next genuine turn). */
    public bool $resumesParent = true;

    /** The signal row's message. */
    public string $text = '';

    /**
     * @param DelegationSubAgentRecord $delegationSubAgentRecord the record AFTER the run end was written
     * @param DelegationSubAgentRecords $otherUnfinishedRecords the parent's OTHER records with an unfinished run
     * @param DelegationSubAgentRecords $requesterSiblingRecords every record of the same requesting tool call, this one included
     */
    public static function buildFor(
        DelegationSubAgentRecord $delegationSubAgentRecord,
        int $runNumber,
        DelegationSubAgentRecords $otherUnfinishedRecords,
        DelegationSubAgentRecords $requesterSiblingRecords,
        DateTime $now
    ): static {
        $delegationRunEndNotification = new static();
        $delegationRunEndNotification->subAgentNumber = $delegationSubAgentRecord->number;
        $delegationRunEndNotification->runNumber = $runNumber;
        $endedRun = $delegationSubAgentRecord->runs->getRun($runNumber) ?? $delegationSubAgentRecord->getCurrentRun();
        // a run the platform cancelled because newer work replaced it (a superseded edit) never resumes the
        // parent for the replaced work — its end rides the parent's next genuine turn
        $delegationRunEndNotification->resumesParent = !$delegationSubAgentRecord->notifiesParentWithoutResuming
            && $endedRun?->failureReason !== DelegationSubAgentRun::FAILURE_REASON_SUPERSEDED;
        $notificationLines = [self::buildRunEndText($delegationSubAgentRecord, $endedRun, $now)];
        $stillRunningLine = self::buildStillRunningLine($otherUnfinishedRecords, $now);
        if ($stillRunningLine !== null) {
            $notificationLines[] = $stillRunningLine;
        }
        $requestEndedLine = self::buildRequestEndedLine($delegationSubAgentRecord, $requesterSiblingRecords);
        if ($requestEndedLine !== null) {
            $notificationLines[] = $requestEndedLine;
        }
        $delegationRunEndNotification->text = implode("\n", $notificationLines);
        return $delegationRunEndNotification;
    }

    protected static function buildRunEndText(DelegationSubAgentRecord $delegationSubAgentRecord, ?DelegationSubAgentRun $endedRun, DateTime $now): string
    {
        $subAgentName = 'Sub-agent ' . $delegationSubAgentRecord->buildName();
        if ($endedRun === null) {
            return $subAgentName . ' ended. No result.';
        }
        $verdict = trim((string)$endedRun->verdict);
        if ($endedRun->hasFailed()) {
            if ($delegationSubAgentRecord->task instanceof AgentDelegationToolRunTask) {
                $failureVerdict = $verdict !== '' ? $verdict : (string)$endedRun->buildFailureText();
                // a legacy background operation that is no plain tool call names its operation (the CMS detection)
                $failedToolName = $delegationSubAgentRecord->task->toolName !== ''
                    ? $delegationSubAgentRecord->task->toolName
                    : (string)$delegationSubAgentRecord->task->legacyBackgroundOperationName;
                return $subAgentName . ' FAILED: `' . $failedToolName . '` — '
                    . rtrim(self::cutVerdict($failureVerdict, $delegationSubAgentRecord), '.') . '. Nothing was saved by it.';
            }
            return $subAgentName . ' ' . $endedRun->getModelFacingStatus() . ' (' . $endedRun->buildFailureText() . '). No result.';
        }
        $runSeconds = $endedRun->getRunSeconds($now);
        $runFacts = 'run ' . $endedRun->runNumber . ($runSeconds === null ? '' : ', ' . DelegationSubAgentRun::formatDuration($runSeconds));
        $runEndHead = $subAgentName . ' ' . $endedRun->getModelFacingStatus() . ' (' . $runFacts . ').';
        if ($verdict === '') {
            return $runEndHead . ' It returned no result.';
        }
        return $runEndHead . " Result:\n" . self::cutVerdict($verdict, $delegationSubAgentRecord);
    }

    protected static function cutVerdict(string $verdict, DelegationSubAgentRecord $delegationSubAgentRecord): string
    {
        if (mb_strlen($verdict) <= self::VERDICT_MAX_LENGTH) {
            return $verdict;
        }
        $drillDownPointer = $delegationSubAgentRecord->number === null
            ? ''
            : ' — check_delegations({subAgent: ' . $delegationSubAgentRecord->number . '}) shows the whole result';
        $keptVerdict = mb_substr($verdict, 0, self::VERDICT_MAX_LENGTH);
        // a line the platform wrote about what it STORED (for example the links it verified on a page and saved)
        // is never cut away: the skill reads it from the notification
        $platformStoredLinesBeyondTheCut = [];
        foreach (preg_split('/\R/u', mb_substr($verdict, self::VERDICT_MAX_LENGTH)) ?: [] as $verdictLine) {
            if (str_contains($verdictLine, ToolCall::RESULT_MARKER_PLATFORM_STORED)) {
                $platformStoredLinesBeyondTheCut[] = trim($verdictLine);
            }
        }
        $cutLine = "\n… (cut" . $drillDownPointer . ')';
        if ($platformStoredLinesBeyondTheCut !== [] && !str_contains($keptVerdict, ToolCall::RESULT_MARKER_PLATFORM_STORED)) {
            return $keptVerdict . $cutLine . "\n" . implode("\n", $platformStoredLinesBeyondTheCut);
        }
        return $keptVerdict . $cutLine;
    }

    protected static function buildStillRunningLine(DelegationSubAgentRecords $otherUnfinishedRecords, DateTime $now): ?string
    {
        $stillRunningNames = [];
        $unnamedCount = 0;
        foreach ($otherUnfinishedRecords->getElements() as $unfinishedRecord) {
            if (!$unfinishedRecord->hasUnfinishedRun()) {
                continue;
            }
            if (count($stillRunningNames) >= self::STILL_RUNNING_NAMED_MAX) {
                $unnamedCount++;
                continue;
            }
            $currentRun = $unfinishedRecord->getCurrentRun();
            $runSeconds = $currentRun?->getRunSeconds($now);
            $runAge = $currentRun === null || $currentRun->isQueued() || $runSeconds === null
                ? 'queued'
                : DelegationSubAgentRun::formatDuration($runSeconds);
            $stillRunningNames[] = $unfinishedRecord->buildName() . ' (' . $runAge . ')';
        }
        if ($stillRunningNames === []) {
            return null;
        }
        return 'Still running: ' . implode(', ', $stillRunningNames) . ($unnamedCount > 0 ? ' and ' . $unnamedCount . ' more' : '') . '.';
    }

    protected static function buildRequestEndedLine(DelegationSubAgentRecord $delegationSubAgentRecord, DelegationSubAgentRecords $requesterSiblingRecords): ?string
    {
        $nextStep = trim((string)$delegationSubAgentRecord->nextStepWhenAllSubAgentsOfRequestEnded);
        if ($delegationSubAgentRecord->requestedByToolCallId === null || $nextStep === '') {
            return null;
        }
        if ($delegationSubAgentRecord->hasUnfinishedRun()) {
            return null;
        }
        $recordsOfRequest = $requesterSiblingRecords->getOfRequesterToolCallId($delegationSubAgentRecord->requestedByToolCallId);
        // shown ONCE: in the notification of the request's last run end — every OTHER sub-agent of the request ended and
        // was told already (two run ends close together both see "all ended"; the notifiers write one after the other
        // under the parent's lock, so only the later one finds the earlier one reported)
        // a request whose work newer work replaced (a superseded edit) owes no next step any more
        if ($delegationSubAgentRecord->getCurrentRun()?->failureReason === DelegationSubAgentRun::FAILURE_REASON_SUPERSEDED) {
            return null;
        }
        foreach ($recordsOfRequest->getElements() as $siblingRecord) {
            if ($siblingRecord->getCurrentRun()?->failureReason === DelegationSubAgentRun::FAILURE_REASON_SUPERSEDED) {
                return null;
            }
            if ($siblingRecord->number !== null && $siblingRecord->number === $delegationSubAgentRecord->number) {
                continue;
            }
            if (!$siblingRecord->hasCurrentRunEndedAndBeenReported()) {
                return null;
            }
        }
        $requestSize = max(1, $recordsOfRequest->count());
        $requesterName = '`' . ($delegationSubAgentRecord->requestedByToolName ?? 'the tool') . '`';
        $requestEnded = $requestSize === 1
            ? 'The sub-agent started by ' . $requesterName . ' has ended.'
            : 'All ' . $requestSize . ' sub-agents started by ' . $requesterName . ' have ended.';
        return $requestEnded . ' NEXT: ' . $nextStep;
    }
}
