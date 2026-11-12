<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentDelegationRequestFromToolResult;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAgentTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateToolArguments;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\AgentSubAgentLiveActivities;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\AgentSubAgentLiveActivity;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\AgentSubAgentLiveStep;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationRunEndNotification;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationStatusReport;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The two builders without a caller yet: the notification of one run end and the
 * `check_delegations` report, each over records built in the test.
 */
class DelegationRunEndNotificationAndStatusReportTest extends UnitTestCase
{
    public function testACompletedRunNamesItsResultAndTheOtherSubAgentsStillRunning(): void
    {
        $now = self::moment('10:02:00');
        $endedRecord = self::buildRunningRecord(3, 'Services page', '10:00:00')
            ->withRunEnded(DelegationSubAgentRun::STATUS_COMPLETED, 'The page lists 6 services.', null, null, 0.01, $now);
        $otherUnfinishedRecords = new DelegationSubAgentRecords();
        $runningRecord = self::buildRunningRecord(4, 'Contact page', '10:01:00');
        $queuedRecord = DelegationSubAgentRecord::queued(5, self::buildModelRequest('Prices page'), 812347, self::moment('10:01:30'));
        $otherUnfinishedRecords->add($runningRecord, $queuedRecord);

        $delegationRunEndNotification = DelegationRunEndNotification::buildFor($endedRecord, 1, $otherUnfinishedRecords, new DelegationSubAgentRecords(), $now);

        self::assertSame(
            "Sub-agent #3 «Services page» COMPLETED (run 1, 2 min). Result:\nThe page lists 6 services.\n"
            . 'Still running: #4 «Contact page» (1 min), #5 «Prices page» (queued).',
            $delegationRunEndNotification->text
        );
        self::assertTrue($delegationRunEndNotification->resumesParent);
        self::assertSame(3, $delegationRunEndNotification->subAgentNumber);
    }

    public function testAFailedRunNamesItsReasonAndAFailedToolRunSaysNothingWasSaved(): void
    {
        $now = self::moment('10:05:00');
        $timedOutRecord = self::buildRunningRecord(4, 'Contact page', '10:00:00')
            ->withRunEnded(DelegationSubAgentRun::STATUS_TIMED_OUT, null, DelegationSubAgentRun::FAILURE_REASON_TIMED_OUT, 'no step for 5 min', null, $now);
        $toolRunRecord = DelegationSubAgentRecord::queued(
            7,
            AgentSubAgentRequest::forPurposeStep(AgentDelegationToolRunTask::forToolCall('updateOpeningHours', ['shopId' => 5100107])),
            812350,
            self::moment('10:00:00')
        )->withRunEnded(DelegationSubAgentRun::STATUS_FAILED, 'The opening hours were refused.', DelegationSubAgentRun::FAILURE_REASON_CHILD_FAILED, null, 0.0, $now);

        $timedOutNotification = DelegationRunEndNotification::buildFor($timedOutRecord, 1, new DelegationSubAgentRecords(), new DelegationSubAgentRecords(), $now);
        $toolRunNotification = DelegationRunEndNotification::buildFor($toolRunRecord, 1, new DelegationSubAgentRecords(), new DelegationSubAgentRecords(), $now);

        self::assertSame('Sub-agent #4 «Contact page» FAILED (timedOut — no step for 5 min). No result.', $timedOutNotification->text);
        self::assertSame(
            'Sub-agent #7 «updateOpeningHours 5100107» FAILED: `updateOpeningHours` — The opening hours were refused. Nothing was saved by it.',
            $toolRunNotification->text
        );
        self::assertFalse($toolRunNotification->resumesParent);
    }

    /**
     * The next step is shown in the notification of the request's LAST run end — every other
     * sub-agent of the request ended AND was told (two run ends close together both see "all ended"; only the later
     * notification, written after the earlier one stamped its run, names the next step).
     */
    public function testTheLastRunEndOfARequestNamesItsNextStepAndALongVerdictIsCut(): void
    {
        $now = self::moment('10:10:00');
        $producingToolCall = new ToolCall();
        $producingToolCall->toolCallId = 'call_pages01';
        $producingToolCall->name = 'writeAllPendingPages';
        $agentDelegationRequestFromToolResult = AgentDelegationRequestFromToolResult::fromStructuredContent(
            ['delegationTasks' => ['Write the home page.', 'Write the contact page.'], 'delegationNextStepWhenAllSubAgentsEnded' => 'Call buildAndPreview now.'],
            1
        );
        $requestRecords = new DelegationSubAgentRecords();
        $pageNumber = 1;
        foreach ($agentDelegationRequestFromToolResult->tasks as $pageTask) {
            $pageRecord = DelegationSubAgentRecord::queued(
                $pageNumber,
                AgentSubAgentRequest::forToolResultTask($pageTask, '', $agentDelegationRequestFromToolResult, $producingToolCall),
                812400 + $pageNumber,
                self::moment('10:00:00')
            )->withRunEnded(DelegationSubAgentRun::STATUS_COMPLETED, str_repeat('page ', 1000), null, null, 0.02, $now);
            $requestRecords->add($pageRecord);
            $pageNumber++;
        }
        $firstRecord = $requestRecords->getByNumber(1);
        $lastRecord = $requestRecords->getByNumber(2);
        self::assertNotNull($firstRecord);
        self::assertNotNull($lastRecord);

        $beforeTheFirstWasTold = DelegationRunEndNotification::buildFor($lastRecord, 1, new DelegationSubAgentRecords(), $requestRecords, $now);
        self::assertStringNotContainsString('NEXT:', $beforeTheFirstWasTold->text, 'the other run ended but its notification is not written yet');

        $firstRecord->getCurrentRun()->reportedToParentAt = $now;
        $delegationRunEndNotification = DelegationRunEndNotification::buildFor($lastRecord, 1, new DelegationSubAgentRecords(), $requestRecords, $now);

        self::assertStringContainsString("\n… (cut — check_delegations({subAgent: 2}) shows the whole result)\n", $delegationRunEndNotification->text);
        self::assertStringEndsWith('All 2 sub-agents started by `writeAllPendingPages` have ended. NEXT: Call buildAndPreview now.', $delegationRunEndNotification->text);

        $firstRecordStillRunning = new DelegationSubAgentRecords();
        $stillRunningPage = DelegationSubAgentRecord::queued(
            1,
            AgentSubAgentRequest::forToolResultTask(AgentDelegationAgentTask::forTask('Write the home page.', []), '', $agentDelegationRequestFromToolResult, $producingToolCall),
            812401,
            self::moment('10:00:00')
        );
        $firstRecordStillRunning->add($stillRunningPage, $lastRecord);
        self::assertStringNotContainsString('NEXT:', DelegationRunEndNotification::buildFor($lastRecord, 1, new DelegationSubAgentRecords(), $firstRecordStillRunning, $now)->text);

        // a request newer work replaced: a superseded run end never resumes the parent and the request owes no next step
        $supersededRecord = DelegationSubAgentRecord::queued(
            1,
            AgentSubAgentRequest::forToolResultTask(AgentDelegationAgentTask::forTask('Write the home page.', []), 'Edit page home', $agentDelegationRequestFromToolResult, $producingToolCall),
            812401,
            self::moment('10:00:00')
        )->withRunEnded(DelegationSubAgentRun::STATUS_CANCELLED, null, DelegationSubAgentRun::FAILURE_REASON_SUPERSEDED, 'newer work replaced it before it started', null, $now);
        $supersededRecords = new DelegationSubAgentRecords();
        $supersededRecords->add($supersededRecord, $lastRecord);
        $supersededNotification = DelegationRunEndNotification::buildFor($supersededRecord, 1, new DelegationSubAgentRecords(), $supersededRecords, $now);
        self::assertFalse($supersededNotification->resumesParent);
        self::assertSame('Sub-agent #1 «Edit page home» CANCELLED (superseded — newer work replaced it before it started). No result.', $supersededNotification->text);
        self::assertStringNotContainsString('NEXT:', DelegationRunEndNotification::buildFor($lastRecord, 1, new DelegationSubAgentRecords(), $supersededRecords, $now)->text);
    }

    /** A PLATFORM STORED line beyond the cut is kept — the setup skill reads it from the notification. */
    public function testAPlatformStoredLineBeyondTheCutIsKept(): void
    {
        $now = self::moment('10:03:00');
        $storedLine = ToolCall::RESULT_MARKER_PLATFORM_STORED . ' the social profile links of the own website: facebook.com/example.';
        $warmUpRecord = self::buildRunningRecord(2, 'Own website', '10:00:00')
            ->withRunEnded(DelegationSubAgentRun::STATUS_COMPLETED, str_repeat('page text ', 500) . "\n" . $storedLine, null, null, 0.01, $now);

        $delegationRunEndNotification = DelegationRunEndNotification::buildFor($warmUpRecord, 1, new DelegationSubAgentRecords(), new DelegationSubAgentRecords(), $now);

        self::assertStringContainsString("\n… (cut — check_delegations({subAgent: 2}) shows the whole result)\n" . $storedLine, $delegationRunEndNotification->text);
    }

    public function testTheStillRunningLineNamesEightAndCountsTheRest(): void
    {
        $now = self::moment('10:10:00');
        $endedRecord = self::buildRunningRecord(1, 'First', '10:00:00')->withRunEnded(DelegationSubAgentRun::STATUS_COMPLETED, 'ok', null, null, null, $now);
        $otherUnfinishedRecords = new DelegationSubAgentRecords();
        for ($number = 2; $number <= 11; $number++) {
            $runningRecord = self::buildRunningRecord($number, 'Page ' . $number, '10:09:00');
            $otherUnfinishedRecords->add($runningRecord);
        }

        $text = DelegationRunEndNotification::buildFor($endedRecord, 1, $otherUnfinishedRecords, new DelegationSubAgentRecords(), $now)->text;

        self::assertStringEndsWith('#9 «Page 9» (1 min) and 2 more.', $text);
    }

    public function testTheReportListsEveryRecordWithTheLiveLookAndSumsUpTheLegacyRecords(): void
    {
        $now = self::moment('10:06:00');
        $delegationSubAgentRecords = new DelegationSubAgentRecords();
        $legacyRecordA = DelegationSubAgentRecord::legacy(AgentDelegationAgentTask::forTask('Old task A.', []), 971101, 'done', null);
        $legacyRecordB = DelegationSubAgentRecord::legacy(AgentDelegationAgentTask::forTask('Old task B.', []), 971102, null, null);
        $completedRecord = self::buildRunningRecord(3, 'Services page', '10:00:00')
            ->withRunEnded(DelegationSubAgentRun::STATUS_COMPLETED, 'The page lists 6 services.', null, null, 0.01, self::moment('10:02:00'));
        $runningRecord = self::buildRunningRecord(4, 'Contact page', '10:04:00');
        $delegationSubAgentRecords->add($legacyRecordA, $legacyRecordB, $completedRecord, $runningRecord);
        $agentSubAgentLiveActivities = new AgentSubAgentLiveActivities();
        $contactPageActivity = AgentSubAgentLiveActivity::forChild(812004, 'RUNNING', $now, 9, [
            AgentSubAgentLiveStep::forToolCall('getWebPageAsMarkdown', 'COMPLETED', self::moment('10:05:40')),
        ]);
        $agentSubAgentLiveActivities->add($contactPageActivity);

        $report = DelegationStatusReport::buildFor($delegationSubAgentRecords, $agentSubAgentLiveActivities, $now, null);

        self::assertSame(
            "Sub-agents of this conversation (2):\n"
            . "  #3 «Services page» — COMPLETED 4 min ago (run 1, 2 min). Result: The page lists 6 services.\n"
            . "  #4 «Contact page» — RUNNING for 2 min (run 1) — 9 steps, last step 20 s ago: getWebPageAsMarkdown\n"
            . "Earlier delegations (before sub-agent numbers): 2 sub-agents, 1 with a result.\n"
            . DelegationStatusReport::CLOSING_LINE,
            $report->toModelText()
        );
        self::assertSame('No sub-agents in this conversation yet.', DelegationStatusReport::buildFor(new DelegationSubAgentRecords(), $agentSubAgentLiveActivities, $now, null)->toModelText());
    }

    public function testTheReportListsTheNewestTwentyAndCountsTheOlderOnes(): void
    {
        $now = self::moment('10:30:00');
        $delegationSubAgentRecords = new DelegationSubAgentRecords();
        for ($number = 1; $number <= 22; $number++) {
            $record = self::buildRunningRecord($number, 'Page ' . $number, '10:00:00');
            if ($number > 1) {
                $record = $record->withRunEnded(DelegationSubAgentRun::STATUS_COMPLETED, 'ok', null, null, null, self::moment('10:10:00'));
            }
            $delegationSubAgentRecords->add($record);
        }

        $reportLines = explode("\n", DelegationStatusReport::buildFor($delegationSubAgentRecords, new AgentSubAgentLiveActivities(), $now, null)->toModelText());

        self::assertSame('Sub-agents of this conversation (22):', $reportLines[0]);
        self::assertSame('  (2 older sub-agents not listed, 1 of them still running)', $reportLines[1]);
        self::assertStringStartsWith('  #3 «Page 3»', $reportLines[2]);
        self::assertCount(23, $reportLines);
    }

    public function testTheDrillDownShowsEveryRunWithFullVerdictsAndTheLastSteps(): void
    {
        $now = self::moment('10:06:00');
        $delegationSubAgentRecord = DelegationSubAgentRecord::fromStoredArray([
            'number' => 5,
            'label' => 'Prices page',
            'task' => ['kind' => 'agent', 'taskText' => 'Read the prices page.', 'skillNames' => ['Web.Search']],
            'childConversationId' => 812347,
            'currentRunNumber' => 2,
            'runs' => [
                '1' => ['runNumber' => 1, 'status' => 'COMPLETED', 'queuedAt' => '2026-10-22 10:00:00', 'startedAt' => '2026-10-22 10:00:00',
                    'finishedAt' => '2026-10-22 10:01:00', 'verdict' => "Prices:\n- cleaning 80 EUR"],
                '2' => ['runNumber' => 2, 'status' => 'RUNNING', 'continuedBy' => 'parent', 'openingMessage' => 'Also the children prices.',
                    'queuedAt' => '2026-10-22 10:05:00', 'startedAt' => '2026-10-22 10:05:00'],
            ],
        ]);
        $delegationSubAgentRecords = new DelegationSubAgentRecords();
        $delegationSubAgentRecords->add($delegationSubAgentRecord);
        $agentSubAgentLiveActivities = new AgentSubAgentLiveActivities();
        $pricesActivity = AgentSubAgentLiveActivity::forChild(812347, 'RUNNING', $now, 2, [
            AgentSubAgentLiveStep::forToolCall('getWebPageAsMarkdown', 'PENDING', self::moment('10:05:50')),
            AgentSubAgentLiveStep::forToolCall('searchWeb', 'COMPLETED', self::moment('10:05:20')),
        ]);
        $agentSubAgentLiveActivities->add($pricesActivity);

        self::assertSame(
            "Sub-agent #5 «Prices page» — 2 run(s):\n"
            . "  run 1: COMPLETED, ran 1 min — Result:\nPrices:\n- cleaning 80 EUR\n"
            . "  run 2: RUNNING, for 1 min, continued by you: \"Also the children prices.\"\n"
            . "Last steps (newest first, 2 in this run):\n"
            . "  - getWebPageAsMarkdown (PENDING, 10 s ago)\n"
            . "  - searchWeb (COMPLETED, 40 s ago)\n"
            . DelegationStatusReport::CLOSING_LINE,
            DelegationStatusReport::buildFor($delegationSubAgentRecords, $agentSubAgentLiveActivities, $now, 5)->toModelText()
        );
        self::assertSame(
            'No sub-agent #9 in this conversation — check_delegations lists them.',
            DelegationStatusReport::buildFor($delegationSubAgentRecords, $agentSubAgentLiveActivities, $now, 9)->toModelText()
        );
    }

    protected static function buildRunningRecord(int $number, string $label, string $startedAt): DelegationSubAgentRecord
    {
        $delegationSubAgentRecord = DelegationSubAgentRecord::queued($number, self::buildModelRequest($label), 812000 + $number, self::moment($startedAt));
        $currentRun = $delegationSubAgentRecord->getCurrentRun();
        self::assertNotNull($currentRun);
        $currentRun->status = DelegationSubAgentRun::STATUS_RUNNING;
        $currentRun->startedAt = self::moment($startedAt);
        return $delegationSubAgentRecord;
    }

    protected static function buildModelRequest(string $label): AgentSubAgentRequest
    {
        $agentSubAgentRequest = AgentSubAgentRequest::forModelCall(DelegateToolArguments::fromToolCallArguments([
            'label' => $label,
            'task' => 'Read the ' . $label . ' of the website.',
            'skill_names' => ['Web.Search'],
        ]));
        self::assertNotNull($agentSubAgentRequest);
        return $agentSubAgentRequest;
    }

    protected static function moment(string $time): DateTime
    {
        return DateTime::fromString('2026-10-22 ' . $time);
    }
}
