<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The `check_delegations` answer: every sub-agent record of the conversation — the newest
 * {@see RECORDS_IN_FULL_MAX} in a line each with the live look of a running child, older ones counted, the records of old
 * multi-task rows summed up — or ONE record in full with the child's last steps (`subAgent` given). Pure over the
 * passed records and live activities; the dispatch gathers them.
 *
 * @phpstan-consistent-constructor
 */
class DelegationStatusReport extends ValueObject
{
    /** The newest records listed in a line each. */
    public const int RECORDS_IN_FULL_MAX = 20;

    /** The newest steps of a child the drill-down lists. */
    public const int DRILL_DOWN_STEPS_MAX = 5;

    /** The report's closing line: facts only, no polling. */
    public const string CLOSING_LINE = "Each sub-agent's end arrives as a notification by itself — no need to poll.";

    /** The report text. */
    public string $text = '';

    public static function buildFor(
        DelegationSubAgentRecords $delegationSubAgentRecords,
        AgentSubAgentLiveActivities $agentSubAgentLiveActivities,
        DateTime $now,
        ?int $subAgentNumber
    ): static {
        $delegationStatusReport = new static();
        $delegationStatusReport->text = $subAgentNumber === null
            ? self::buildOverviewText($delegationSubAgentRecords, $agentSubAgentLiveActivities, $now)
            : self::buildDrillDownText($delegationSubAgentRecords, $agentSubAgentLiveActivities, $now, $subAgentNumber);
        return $delegationStatusReport;
    }

    public function toModelText(): string
    {
        return $this->text;
    }

    protected static function buildOverviewText(
        DelegationSubAgentRecords $delegationSubAgentRecords,
        AgentSubAgentLiveActivities $agentSubAgentLiveActivities,
        DateTime $now
    ): string {
        $numberedRecords = $delegationSubAgentRecords->getNumbered()->getElements();
        $legacyRecords = $delegationSubAgentRecords->getLegacy()->getElements();
        if ($numberedRecords === [] && $legacyRecords === []) {
            return 'No sub-agents in this conversation yet.';
        }
        $reportLines = [];
        if ($numberedRecords !== []) {
            $reportLines[] = 'Sub-agents of this conversation (' . count($numberedRecords) . '):';
            $olderRecords = array_slice($numberedRecords, 0, max(0, count($numberedRecords) - self::RECORDS_IN_FULL_MAX));
            if ($olderRecords !== []) {
                $olderUnfinishedCount = count(array_filter(
                    $olderRecords,
                    static fn (DelegationSubAgentRecord $olderRecord): bool => $olderRecord->hasUnfinishedRun()
                ));
                $reportLines[] = '  (' . count($olderRecords) . ' older sub-agents not listed'
                    . ($olderUnfinishedCount > 0 ? ', ' . $olderUnfinishedCount . ' of them still running' : ', all ended') . ')';
            }
            foreach (array_slice($numberedRecords, -self::RECORDS_IN_FULL_MAX) as $listedRecord) {
                $reportLines[] = '  ' . self::buildRecordLine($listedRecord, $agentSubAgentLiveActivities, $now);
            }
        }
        if ($legacyRecords !== []) {
            $legacyWithResultCount = count(array_filter(
                $legacyRecords,
                static fn (DelegationSubAgentRecord $legacyRecord): bool => $legacyRecord->getCurrentRun()?->status === DelegationSubAgentRun::STATUS_COMPLETED
            ));
            $reportLines[] = 'Earlier delegations (before sub-agent numbers): ' . count($legacyRecords) . ' sub-agents, '
                . ($legacyWithResultCount === count($legacyRecords) ? 'all with a result' : $legacyWithResultCount . ' with a result') . '.';
        }
        $reportLines[] = self::CLOSING_LINE;
        return implode("\n", $reportLines);
    }

    protected static function buildRecordLine(
        DelegationSubAgentRecord $delegationSubAgentRecord,
        AgentSubAgentLiveActivities $agentSubAgentLiveActivities,
        DateTime $now
    ): string {
        $recordLine = $delegationSubAgentRecord->buildStatusLine($now);
        if ($delegationSubAgentRecord->getCurrentRun()?->status !== DelegationSubAgentRun::STATUS_RUNNING) {
            return $recordLine;
        }
        $agentSubAgentLiveActivity = $agentSubAgentLiveActivities->getForChildConversationId($delegationSubAgentRecord->childConversationId);
        return $agentSubAgentLiveActivity === null ? $recordLine : $recordLine . $agentSubAgentLiveActivity->buildActivitySuffix($now);
    }

    protected static function buildDrillDownText(
        DelegationSubAgentRecords $delegationSubAgentRecords,
        AgentSubAgentLiveActivities $agentSubAgentLiveActivities,
        DateTime $now,
        int $subAgentNumber
    ): string {
        $delegationSubAgentRecord = $delegationSubAgentRecords->getByNumber($subAgentNumber);
        if ($delegationSubAgentRecord === null) {
            return 'No sub-agent #' . $subAgentNumber . ' in this conversation — check_delegations lists them.';
        }
        $drillDownLines = ['Sub-agent ' . $delegationSubAgentRecord->buildName() . ' — ' . $delegationSubAgentRecord->runs->countRuns() . ' run(s):'];
        foreach ($delegationSubAgentRecord->runs->getRunsInOrder() as $delegationSubAgentRun) {
            $drillDownLines[] = self::buildDrillDownRunText($delegationSubAgentRun, $now);
        }
        $agentSubAgentLiveActivity = $delegationSubAgentRecord->hasUnfinishedRun()
            ? $agentSubAgentLiveActivities->getForChildConversationId($delegationSubAgentRecord->childConversationId)
            : null;
        if ($agentSubAgentLiveActivity !== null && $agentSubAgentLiveActivity->newestSteps !== []) {
            $drillDownLines[] = 'Last steps (newest first, ' . $agentSubAgentLiveActivity->stepCount . ' in this run):';
            foreach (array_slice($agentSubAgentLiveActivity->newestSteps, 0, self::DRILL_DOWN_STEPS_MAX) as $agentSubAgentLiveStep) {
                $drillDownLines[] = '  - ' . $agentSubAgentLiveStep->buildStepLine($now);
            }
        }
        $drillDownLines[] = self::CLOSING_LINE;
        return implode("\n", $drillDownLines);
    }

    protected static function buildDrillDownRunText(DelegationSubAgentRun $delegationSubAgentRun, DateTime $now): string
    {
        $runFacts = [$delegationSubAgentRun->hasEnded() ? $delegationSubAgentRun->getModelFacingStatus() : $delegationSubAgentRun->status];
        $runSeconds = $delegationSubAgentRun->getRunSeconds($now);
        if ($runSeconds !== null && $delegationSubAgentRun->status !== DelegationSubAgentRun::STATUS_NOT_RUN) {
            $runFacts[] = ($delegationSubAgentRun->hasEnded() ? 'ran ' : 'for ') . DelegationSubAgentRun::formatDuration($runSeconds);
        }
        $continuedByText = $delegationSubAgentRun->buildContinuedByText();
        if ($continuedByText !== null) {
            $runFacts[] = $continuedByText . ($delegationSubAgentRun->openingMessage !== null ? ': "' . $delegationSubAgentRun->openingMessage . '"' : '');
        }
        $runText = '  run ' . $delegationSubAgentRun->runNumber . ': ' . implode(', ', $runFacts);
        $failureText = $delegationSubAgentRun->buildFailureText();
        if ($failureText !== null) {
            return $runText . ' — ' . $failureText;
        }
        $verdict = trim((string)$delegationSubAgentRun->verdict);
        return $verdict === '' ? $runText : $runText . " — Result:\n" . $verdict;
    }
}
