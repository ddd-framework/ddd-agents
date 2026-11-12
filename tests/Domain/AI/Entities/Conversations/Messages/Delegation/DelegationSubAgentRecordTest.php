<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAgentTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateToolArguments;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The sub-agent record: its factories, the fixed spawn answer, the status
 * line of `check_delegations`, the refusals of `message_sub_agent` and the pure run-end twin.
 */
class DelegationSubAgentRecordTest extends UnitTestCase
{
    public function testAQueuedRecordCarriesTheRequestAndRunOneQueued(): void
    {
        $delegationSubAgentRecord = DelegationSubAgentRecord::queued(3, self::buildModelRequest('Services page'), 812345, self::moment('10:00:00'));

        self::assertSame(3, $delegationSubAgentRecord->number);
        self::assertSame('Services page', $delegationSubAgentRecord->label);
        self::assertSame(812345, $delegationSubAgentRecord->childConversationId);
        self::assertSame(AgentSubAgentRequest::REQUESTED_BY_MODEL, $delegationSubAgentRecord->requestedBy);
        self::assertSame(DelegationSubAgentRun::STATUS_QUEUED, $delegationSubAgentRecord->getCurrentRun()?->status);
        self::assertTrue($delegationSubAgentRecord->hasUnfinishedRun());
        self::assertFalse($delegationSubAgentRecord->isNotRun());
        self::assertFalse($delegationSubAgentRecord->isLegacy());
    }

    public function testTheSpawnAnswerIsStartedQueuedOrTheCorrective(): void
    {
        $delegationSubAgentRecord = DelegationSubAgentRecord::queued(3, self::buildModelRequest('Services page'), 812345, self::moment('10:00:00'));
        $refusedRecord = DelegationSubAgentRecord::notRun(
            4,
            self::buildModelRequest('Contact page'),
            DelegationSubAgentRun::FAILURE_REASON_REFUSED,
            'delegate REJECTED — name the skills the sub-agent needs.',
            self::moment('10:00:00')
        );
        $shapelessRecord = DelegationSubAgentRecord::notRunWithoutTask(5, DelegateToolArguments::fromToolCallArguments(['label' => 'Both']), self::moment('10:00:00'));

        self::assertSame(
            'Sub-agent #3 «Services page» started (running). Its result arrives as a notification when it ends.',
            $delegationSubAgentRecord->buildSpawnAnswerText(null)
        );
        self::assertSame(
            'Sub-agent #3 «Services page» queued (5 sub-agents already run in this conversation). Its result arrives as a notification when it ends.',
            $delegationSubAgentRecord->buildSpawnAnswerText(5)
        );
        self::assertSame('delegate REJECTED — name the skills the sub-agent needs.', $refusedRecord->buildSpawnAnswerText(null));
        self::assertTrue($refusedRecord->isNotRun());
        self::assertNull($refusedRecord->childConversationId);
        self::assertNull($shapelessRecord->task);
        self::assertSame('Both', $shapelessRecord->label);
        self::assertStringStartsWith('delegate REJECTED — the call names no task', $shapelessRecord->buildSpawnAnswerText(null));
    }

    public function testTheStatusLineOfEachRunState(): void
    {
        $now = self::moment('10:06:00');
        $queuedRecord = DelegationSubAgentRecord::queued(5, self::buildModelRequest('Prices page'), 812347, self::moment('10:05:30'));
        $runningRecord = DelegationSubAgentRecord::queued(4, self::buildModelRequest('Contact page'), 812346, self::moment('10:03:00'));
        $runningRecord->getCurrentRun()->status = DelegationSubAgentRun::STATUS_RUNNING;
        $runningRecord->getCurrentRun()->startedAt = self::moment('10:04:00');
        $completedRecord = DelegationSubAgentRecord::queued(3, self::buildModelRequest('Services page'), 812345, self::moment('10:00:00'))
            ->withRunEnded(DelegationSubAgentRun::STATUS_COMPLETED, "The page lists 6 services:\nimplants, bleaching.", null, null, 0.01, self::moment('10:02:00'));
        $timedOutRecord = DelegationSubAgentRecord::queued(6, self::buildModelRequest('Team page'), 812348, self::moment('10:00:00'))
            ->withRunEnded(DelegationSubAgentRun::STATUS_TIMED_OUT, null, DelegationSubAgentRun::FAILURE_REASON_TIMED_OUT, 'no step for 5 min', null, self::moment('10:05:00'));

        self::assertSame('#5 «Prices page» — QUEUED for 30 s (run 1)', $queuedRecord->buildStatusLine($now));
        self::assertSame('#4 «Contact page» — RUNNING for 2 min (run 1)', $runningRecord->buildStatusLine($now));
        self::assertSame(
            '#3 «Services page» — COMPLETED 4 min ago (run 1, 2 min). Result: The page lists 6 services: implants, bleaching.',
            $completedRecord->buildStatusLine($now)
        );
        self::assertSame('#6 «Team page» — FAILED 1 min ago (run 1, 5 min): timedOut — no step for 5 min', $timedOutRecord->buildStatusLine($now));
    }

    public function testAContinuedRunNamesWhoContinuedItAndListsTheOlderRuns(): void
    {
        $delegationSubAgentRecord = DelegationSubAgentRecord::fromStoredArray([
            'number' => 5,
            'label' => 'Prices page',
            'task' => ['kind' => 'agent', 'taskText' => 'Read the prices page.', 'skillNames' => ['Web.Search']],
            'childConversationId' => 812347,
            'requestedBy' => 'MODEL',
            'currentRunNumber' => 2,
            'runs' => [
                '1' => ['runNumber' => 1, 'status' => 'FAILED', 'failureReason' => 'childFailed', 'queuedAt' => '2026-10-22 10:00:00', 'finishedAt' => '2026-10-22 10:01:00'],
                '2' => ['runNumber' => 2, 'status' => 'RUNNING', 'continuedBy' => 'parent', 'openingMessage' => 'Also read the prices page.', 'queuedAt' => '2026-10-22 10:05:00', 'startedAt' => '2026-10-22 10:05:30'],
            ],
        ]);

        self::assertSame(
            '#5 «Prices page» — RUNNING for 30 s (run 2, continued by you: "Also read the prices page."); run 1 FAILED (childFailed)',
            $delegationSubAgentRecord->buildStatusLine(self::moment('10:06:00'))
        );
    }

    public function testAStoredRecordReadsBackToTheSameStoredArray(): void
    {
        $storedRecord = [
            'number' => 7,
            'label' => 'getWebPageAsMarkdown https://example.org',
            'task' => ['kind' => 'toolRun', 'toolName' => 'getWebPageAsMarkdown', 'toolArguments' => ['url' => 'https://example.org', 'noCache' => true], 'seedSkillNames' => ['Web.Search']],
            'childConversationId' => 812350,
            'requestedBy' => 'TOOL_RESULT',
            'requestedByToolCallId' => 'call_abc',
            'requestedByToolName' => 'importConfirmedListing',
            'nextStepWhenAllSubAgentsOfRequestEnded' => 'Write the draft.',
            'notifiesParentWithoutResuming' => true,
            'currentRunNumber' => 2,
            'runs' => [
                1 => ['runNumber' => 1, 'status' => 'COMPLETED', 'queuedAt' => '2026-10-22 10:00:00', 'startedAt' => '2026-10-22 10:00:01', 'finishedAt' => '2026-10-22 10:00:40',
                    'verdict' => '# Example', 'costInUsd' => 0.0, 'reportedToParentAt' => '2026-10-22 10:00:41'],
                2 => ['runNumber' => 2, 'status' => 'QUEUED', 'queuedAt' => '2026-10-22 10:05:00', 'continuedBy' => 'admin', 'openingMessage' => 'Again.'],
            ],
        ];

        self::assertSame($storedRecord, DelegationSubAgentRecord::fromStoredArray($storedRecord)->mapToRepository());
    }

    public function testTheRunEndTwinEndsTheCurrentRunOnACopyAndNeverOverwritesAnEndedRun(): void
    {
        $queuedRecord = DelegationSubAgentRecord::queued(3, self::buildModelRequest('Services page'), 812345, self::moment('10:00:00'));
        $failedRecord = $queuedRecord->withRunEnded(
            DelegationSubAgentRun::STATUS_FAILED,
            null,
            DelegationSubAgentRun::FAILURE_REASON_PROVIDER_ERROR,
            str_repeat('x', 400),
            0.002,
            self::moment('10:01:00')
        );
        $lateCompletion = $failedRecord->withRunEnded(DelegationSubAgentRun::STATUS_COMPLETED, 'late', null, null, 0.5, self::moment('10:02:00'));

        self::assertSame(DelegationSubAgentRun::STATUS_QUEUED, $queuedRecord->getCurrentRun()?->status);
        self::assertSame(DelegationSubAgentRun::STATUS_FAILED, $failedRecord->getCurrentRun()?->status);
        self::assertSame(DelegationSubAgentRun::FAILURE_DETAIL_MAX_LENGTH, mb_strlen((string)$failedRecord->getCurrentRun()?->failureDetail));
        self::assertSame($failedRecord->mapToRepository(), $lateCompletion->mapToRepository());
    }

    public function testMessageRefusalsForLegacyNotRunAndToolRunRecords(): void
    {
        $legacyRecord = DelegationSubAgentRecord::legacy(AgentDelegationAgentTask::forTask('Old task.', []), 971101, 'done', null);
        $notRunRecord = DelegationSubAgentRecord::notRun(9, self::buildModelRequest('X'), DelegationSubAgentRun::FAILURE_REASON_EARLIER_SUB_AGENT_FAILED, 'Not started.', self::moment('10:00:00'));
        $toolRunRecord = DelegationSubAgentRecord::queued(
            7,
            AgentSubAgentRequest::forPurposeStep(AgentDelegationToolRunTask::forToolCall('proposeKeywords', ['shopId' => 5100107])),
            812350,
            self::moment('10:00:00')
        );
        $agentRecord = DelegationSubAgentRecord::queued(4, self::buildModelRequest('Contact page'), 812346, self::moment('10:00:00'));

        self::assertStringStartsWith('This sub-agent belongs to an earlier delegation', (string)$legacyRecord->findRefusalOfMessage());
        self::assertSame('Sub-agent #9 never started (earlierSubAgentFailed) — start a new one with delegate.', $notRunRecord->findRefusalOfMessage());
        self::assertSame('Sub-agent #7 is a tool run of `proposeKeywords` — it takes no messages. Start a new delegate call.', $toolRunRecord->findRefusalOfMessage());
        self::assertSame('proposeKeywords 5100107', $toolRunRecord->label);
        self::assertTrue($toolRunRecord->notifiesParentWithoutResuming);
        self::assertNull($agentRecord->findRefusalOfMessage());
    }

    public function testDurationsAndTheLegacyRecordName(): void
    {
        self::assertSame('59 s', DelegationSubAgentRun::formatDuration(59));
        self::assertSame('1 min', DelegationSubAgentRun::formatDuration(60));
        self::assertSame('119 min', DelegationSubAgentRun::formatDuration(7199));
        self::assertSame('2 h', DelegationSubAgentRun::formatDuration(7200));
        self::assertSame('«Old task.»', DelegationSubAgentRecord::legacy(AgentDelegationAgentTask::forTask('Old task.', []), null, null, null)->buildName());
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
