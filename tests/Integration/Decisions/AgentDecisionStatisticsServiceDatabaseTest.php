<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics\AgentDecisionStatistics;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics\AgentDecisionStatisticsRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics\AgentDecisionStatisticsSection;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventModel;
use DDD\Domain\AI\Services\Decisions\AgentDecisionStatisticsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The raw statements of {@see AgentDecisionStatisticsService} against the database, on both engines: the
 * reconciliation, the decisions per day / mode / arm, the skip reasons, the follow-up passes, the request latency,
 * the teacher agreement with its turn window and load reads, the preload outcomes, and the per-arm sections of the
 * turn-closing, effort and stall sites. Every window lies in the first week of November 2026; the clock of the
 * database plays no part.
 */
class AgentDecisionStatisticsServiceDatabaseTest extends DatabaseTestCase
{
    protected const string WINDOW_FROM = '2026-11-01 00:00:00';

    protected const string WINDOW_TO = '2026-11-08 00:00:00';

    protected const string CUTOFF = '2026-11-05 00:00:00';

    protected const string DECISION_DAY = '2026-11-03';

    protected AgentDecisionStatisticsService $statisticsService;

    protected int $nextEventId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->statisticsService = new AgentDecisionStatisticsService();
        $this->nextEventId = 1;
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testTheSkillPreloadSiteCountsDecisionsSkipsPassesLatencyAndTheTeacherAgreementWithoutEvalEvents(): void
    {
        $this->insertConversation(100, []);
        $this->insertConversation(102, []);
        // pick 1: a shadow pick that the model's first load confirms
        $this->insertDecisionEvent(100, AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 'shadow', 0, [
            'mode' => 'shadow', 'pickedSkillName' => 'writer', 'pickConfidence' => 0.95, 'pickMargin' => 0.8,
            'wouldPreload' => true, 'applied' => false, 'decidedBy' => 'model', 'skipReason' => null,
        ], ['elapsedMs' => 100]);
        // a shadow decision without a pick: stored as JSON null, never a pick
        $this->insertDecisionEvent(100, AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 'shadow', 10, [
            'mode' => 'shadow', 'pickedSkillName' => null, 'wouldPreload' => false, 'applied' => false,
            'decidedBy' => 'fallback:timeout', 'skipReason' => 'lowConfidence',
        ], ['elapsedMs' => 200]);
        // an eval decision: left out unless eval is included
        $this->insertDecisionEvent(100, AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 'shadow', 20, [
            'mode' => 'shadow', 'pickedSkillName' => null, 'skipReason' => 'evalOnly',
        ], ['evalRun' => true]);
        // a follow-up pass under the root `writer`
        $this->insertDecisionEvent(100, AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 'on', 0, [
            'mode' => 'on', 'pass' => 2, 'unlockedByRootSkillName' => 'writer', 'wouldPreload' => true, 'applied' => true,
            'decidedBy' => 'model', 'skipReason' => null,
        ], ['elapsedMs' => 50, 'subject' => 'pass2:writer']);
        // pick 2: younger than the cutoff, no terminal row, no next initiator → censored
        $this->insertDecisionEvent(102, AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 'shadow', 0, [
            'mode' => 'shadow', 'pickedSkillName' => 'editor', 'pickConfidence' => 0.9, 'pickMargin' => 0.5, 'skipReason' => null,
        ], ['created' => '2026-11-06 10:00:00']);
        $this->insertEvent(100, AIConversationTurnEvent::KIND_TURN_END, null, 'a', 0, ['outcome' => 'completed'], []);
        $this->insertEvent(100, AIConversationTurnEvent::KIND_REQUEST, null, null, 0, [], ['elapsedMs' => 120]);
        $this->insertEvent(100, AIConversationTurnEvent::KIND_REQUEST, null, null, 10, [], ['elapsedMs' => 80]);
        $this->insertEvent(100, AIConversationTurnEvent::KIND_PRELOAD_OUTCOME, null, 'on', 0, ['outcome' => 'used'], []);
        // the turn of pick 1: owner text 0, the skill load 1, a failed load 2, the next owner text 10
        $this->insertMessage(100, 0, ['role' => AIConversationMessage::ROLE_USER, 'messageType' => AIConversationMessage::MESSAGE_TEXT, 'authorType' => 'USER']);
        $this->insertMessage(100, 1, ['messageType' => AIConversationMessage::MESSAGE_TOOL_CALL, 'toolCall' => json_encode([
            'name' => 'load_skills', 'status' => 'COMPLETED', 'arguments' => ['skill_names' => ['writer']], 'result' => 'Skills loaded: writer',
        ])]);
        $this->insertMessage(100, 2, ['messageType' => AIConversationMessage::MESSAGE_TOOL_CALL, 'toolCall' => json_encode([
            'name' => 'load_skills', 'status' => 'FAILED', 'arguments' => ['skill_names' => ['editor']], 'result' => 'Unknown skill',
        ])]);
        $this->insertMessage(100, 10, ['role' => AIConversationMessage::ROLE_USER, 'messageType' => AIConversationMessage::MESSAGE_TEXT, 'authorType' => 'USER']);

        $statistics = $this->statisticsService->buildStatistics($this->buildRequest(AgentDecisionModelSettings::SITE_SKILL_PRELOAD));

        self::assertSame(
            [['outcome' => 'completed', 'arm' => 'a', 'n' => '1']],
            $this->findTableRowsOfSection($statistics, 'Terminal records (turnEnd) by outcome × arm')
        );
        $decisionRows = $this->findTableRowsOfSection($statistics, 'Decisions per day × mode × arm');
        self::assertSame([
            ['day' => self::DECISION_DAY, 'mode' => 'on', 'arm' => 'on', 'decisions' => '1', 'applied' => '1', 'wouldPreload' => '1', 'wouldApply' => '0', 'fallbacks' => '0', 'avgMs' => '50', 'maxMs' => '50'],
            ['day' => self::DECISION_DAY, 'mode' => 'shadow', 'arm' => 'shadow', 'decisions' => '2', 'applied' => '0', 'wouldPreload' => '1', 'wouldApply' => '0', 'fallbacks' => '1', 'avgMs' => '150', 'maxMs' => '200'],
            ['day' => '2026-11-06', 'mode' => 'shadow', 'arm' => 'shadow', 'decisions' => '1', 'applied' => '0', 'wouldPreload' => '0', 'wouldApply' => '0', 'fallbacks' => '0', 'avgMs' => '', 'maxMs' => ''],
        ], $decisionRows, 'a key missing in every payload counts 0, never NULL');
        self::assertSame(
            [['skipReason' => '(preloaded)', 'n' => '3'], ['skipReason' => 'lowConfidence', 'n' => '1']],
            $this->findTableRowsOfSection($statistics, 'Skip reasons'),
            'a JSON null skip reason is a preload, the eval decision is left out'
        );
        self::assertSame(
            [['pass' => '2', 'root' => 'writer', 'n' => '1', 'wouldPreload' => '1', 'applied' => '1']],
            $this->findTableRowsOfSection($statistics, 'Follow-up passes (sub-skills unlocked by an admitted root)')
        );
        self::assertContains('turn-start request latency: n=2 p50=80 ms p95=120 ms', $this->findTextLinesOfSection($statistics, ''));
        $agreementLines = $this->findTextLinesOfSection($statistics, 'Teacher agreement (shadow picks)');
        self::assertContains(sprintf('%-18s %d', 'agree', 1), $agreementLines);
        self::assertContains(sprintf('%-18s %d', 'missNoTool', 0), $agreementLines, 'a decision without a pick is no pick');
        self::assertContains(sprintf('%-18s %d', 'censored', 1), $agreementLines);
        self::assertSame(
            [['skill' => 'writer', 'picks' => '1', 'agree' => '1', 'precision' => '1']],
            $this->findTableRowsOfSection($statistics, 'Per-skill precision')
        );
        self::assertSame([['outcome' => 'used', 'n' => '1']], $this->findTableRowsOfSection($statistics, 'Preload outcomes (on)'));
    }

    public function testTheWindowOfAShadowPickEndsAtTheNextSignalThatStartsATurn(): void
    {
        $this->insertConversation(104, []);
        $this->insertDecisionEvent(104, AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 'shadow', 0, [
            'mode' => 'shadow', 'pickedSkillName' => 'writer', 'pickConfidence' => 0.95, 'pickMargin' => 0.8,
            'wouldPreload' => true, 'applied' => false, 'decidedBy' => 'model', 'skipReason' => null,
        ], ['elapsedMs' => 100]);
        // the owner's text 0, a marker of the same source on a TEXT row 3 (no turn start), the supervisor's
        // correction 5 (starts the next turn), the load of the picked skill 6 — in the NEXT turn
        $this->insertMessage(104, 0, ['role' => AIConversationMessage::ROLE_USER, 'messageType' => AIConversationMessage::MESSAGE_TEXT, 'authorType' => 'USER']);
        $this->insertMessage(104, 3, [
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'signalSource' => AIConversationSignalSource::SUPERVISOR_CORRECTION->value,
        ]);
        $this->insertMessage(104, 5, [
            'role' => AIConversationMessage::ROLE_SYSTEM,
            'messageType' => AIConversationMessage::MESSAGE_SIGNAL,
            'signalSource' => AIConversationSignalSource::SUPERVISOR_CORRECTION->value,
        ]);
        $this->insertMessage(104, 6, ['messageType' => AIConversationMessage::MESSAGE_TOOL_CALL, 'toolCall' => json_encode([
            'name' => 'load_skills', 'status' => 'COMPLETED', 'arguments' => ['skill_names' => ['writer']], 'result' => 'Skills loaded: writer',
        ])]);

        $statistics = $this->statisticsService->buildStatistics($this->buildRequest(AgentDecisionModelSettings::SITE_SKILL_PRELOAD));

        $agreementLines = $this->findTextLinesOfSection($statistics, 'Teacher agreement (shadow picks)');
        self::assertContains(sprintf('%-18s %d', 'agree', 0), $agreementLines, 'the load after the next turn start is not this pick\'s');
        self::assertContains(sprintf('%-18s %d', 'missNoTool', 1), $agreementLines);
        self::assertContains(sprintf('%-18s %d', 'censored', 0), $agreementLines);
    }

    public function testOneModeAndTheEvalEventsAreReadWhenTheRequestAsksForThem(): void
    {
        $this->insertConversation(100, []);
        $this->insertDecisionEvent(100, AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 'shadow', 0, ['mode' => 'shadow', 'skipReason' => 'lowConfidence'], []);
        $this->insertDecisionEvent(100, AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 'on', 10, ['mode' => 'on', 'skipReason' => null], []);
        $this->insertDecisionEvent(100, AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 'shadow', 20, ['mode' => 'shadow', 'skipReason' => 'evalOnly'], ['evalRun' => true]);

        $request = $this->buildRequest(AgentDecisionModelSettings::SITE_SKILL_PRELOAD);
        $request->mode = 'shadow';
        $request->includeEval = true;
        $statistics = $this->statisticsService->buildStatistics($request);

        $decisionRows = $this->findTableRowsOfSection($statistics, 'Decisions per day × mode × arm');
        self::assertSame(['shadow'], array_column($decisionRows, 'mode'));
        self::assertSame(['2'], array_column($decisionRows, 'decisions'), 'the eval decision counts when eval is included');
        $skipRows = $this->findTableRowsOfSection($statistics, 'Skip reasons');
        self::assertContains(['skipReason' => 'evalOnly', 'n' => '1'], $skipRows);
    }

    public function testTheTurnClosingSiteGroupsByTheArmOfTheConversationRowAndLeavesEvalConversationsOut(): void
    {
        $this->insertConversation(200, ['decisionModelTurnClosing' => 'on']);
        $this->insertConversation(201, []);
        $this->insertConversation(202, ['decisionModelTurnClosing' => 'on', 'evalRun' => true]);
        $this->insertConversation(203, ['decisionModelTurnClosing' => 'on']);
        // the event's own `arm` column differs from the conversation's arm: the section groups by the conversation's
        $this->insertDecisionEvent(200, AgentDecisionModelSettings::SITE_TURN_CLOSING, 'x', 0, [
            'turnClosingMode' => 'checked', 'decidedBy' => 'model', 'fallbackReason' => null, 'wouldBounce' => true,
        ], ['elapsedMs' => 100]);
        $this->insertDecisionEvent(200, AgentDecisionModelSettings::SITE_TURN_CLOSING, 'y', 3, [
            'turnClosingMode' => 'checked', 'decidedBy' => 'model', 'fallbackReason' => null, 'wouldBounce' => false,
        ], ['elapsedMs' => 300]);
        $this->insertDecisionEvent(201, AgentDecisionModelSettings::SITE_TURN_CLOSING, 'x', 0, [
            'turnClosingMode' => 'checked', 'decidedBy' => 'fallback:timeout', 'fallbackReason' => 'timeout',
        ], []);
        $this->insertEvent(200, AIConversationTurnEvent::KIND_TURN_END, null, 'x', 0, ['outcome' => 'completed'], []);
        // the turn of conversation 200: owner text, two assistant heads, two bounces in between and after
        $this->insertMessage(200, 0, ['role' => AIConversationMessage::ROLE_USER, 'messageType' => AIConversationMessage::MESSAGE_TEXT, 'authorType' => 'USER', 'created' => '2026-11-03 10:00:00']);
        $this->insertMessage(200, 1, ['role' => AIConversationMessage::ROLE_ASSISTANT, 'messageType' => AIConversationMessage::MESSAGE_TEXT, 'inputTokens' => 100, 'created' => '2026-11-03 10:00:30']);
        $this->insertBounceSignal(200, 2, '2026-11-03 10:00:40');
        $this->insertMessage(200, 3, ['role' => AIConversationMessage::ROLE_ASSISTANT, 'messageType' => AIConversationMessage::MESSAGE_TEXT, 'inputTokens' => 50, 'created' => '2026-11-03 10:01:00']);
        $this->insertBounceSignal(200, 4, '2026-11-03 10:01:10');
        $this->insertBounceSignal(203, 0, '2026-11-03 11:00:00');
        $this->insertBounceSignal(202, 0, '2026-11-03 12:00:00');

        $statistics = $this->statisticsService->buildStatistics($this->buildRequest(AgentDecisionModelSettings::SITE_TURN_CLOSING));

        self::assertSame([
            ['arm' => 'inherit', 'turnClosingMode' => 'checked', 'decidedBy' => 'fallback:timeout', 'fallbackReason' => 'timeout', 'n' => '1', 'wouldBounce' => '0', 'avgMs' => '', 'totalMs' => '0'],
            ['arm' => 'on', 'turnClosingMode' => 'checked', 'decidedBy' => 'model', 'fallbackReason' => '', 'n' => '2', 'wouldBounce' => '1', 'avgMs' => '200', 'totalMs' => '400'],
        ], $this->findTableRowsOfSection($statistics, 'Closing decisions per arm × turn-closing mode × decidedBy × fallback (fallback cost = n × avgMs)'));
        self::assertSame(
            [['arm' => 'on', 'signalSource' => AIConversationSignalSource::TASK_PLAN_OPEN_BOUNCE->value, 'n' => '3', 'runs' => '2', 'perRun' => '1.50']],
            $this->findTableRowsOfSection($statistics, 'Checked-stop bounces per arm × trigger (per run)'),
            'the bounce of the eval conversation is left out; three bounces over two runs are 1.50 per run, not a whole-number quotient'
        );
        self::assertSame(
            [['arm' => 'on', 'turns' => '1', 'p50s' => '60', 'p95s' => '60', 'avgS' => '60']],
            $this->findTableRowsOfSection($statistics, 'Per-turn wall clock per arm (owner text initiator → last assistant head)')
        );
    }

    public function testTheReasoningEffortSiteCountsDecisionsRaisesAndTheModelCallsPerEffortSent(): void
    {
        $this->insertConversation(300, ['decisionModelReasoningEffort' => 'on']);
        $this->insertDecisionEvent(300, AgentDecisionModelSettings::SITE_REASONING_EFFORT, 'x', 0, [
            'preconditionReason' => 'ownerAsked', 'decidedBy' => 'model', 'effectiveEffort' => 'high', 'resolvedEffort' => 'high', 'applied' => true,
        ], ['elapsedMs' => 10]);
        foreach ([3, 6] as $initiatorMessageIndex) {
            $this->insertDecisionEvent(300, AgentDecisionModelSettings::SITE_REASONING_EFFORT, 'x', $initiatorMessageIndex, [
                'preconditionReason' => 'none', 'decidedBy' => 'rule', 'effectiveEffort' => 'low', 'resolvedEffort' => 'low', 'applied' => false,
            ], ['elapsedMs' => 30]);
        }
        $this->insertMessage(300, 0, ['role' => AIConversationMessage::ROLE_ASSISTANT, 'inputTokens' => 100, 'outputTokens' => 10, 'costInUSD' => 0.0125, 'requestSnapshot' => json_encode(['reasoningEffort' => 'high'])]);
        $this->insertMessage(300, 1, ['role' => AIConversationMessage::ROLE_ASSISTANT, 'inputTokens' => 300, 'outputTokens' => 30, 'costInUSD' => 0.025, 'requestSnapshot' => json_encode(['reasoningEffort' => 'high'])]);
        $this->insertMessage(300, 2, ['role' => AIConversationMessage::ROLE_ASSISTANT, 'inputTokens' => 50, 'outputTokens' => 5, 'costInUSD' => 0.001, 'requestSnapshot' => json_encode(['sentAt' => '2026-11-03 10:00:00'])]);

        $statistics = $this->statisticsService->buildStatistics($this->buildRequest(AgentDecisionModelSettings::SITE_REASONING_EFFORT));

        self::assertSame([
            ['arm' => 'on', 'preconditionReason' => 'none', 'decidedBy' => 'rule', 'effectiveEffort' => 'low', 'n' => '2', 'applied' => '0', 'avgMs' => '30'],
            ['arm' => 'on', 'preconditionReason' => 'ownerAsked', 'decidedBy' => 'model', 'effectiveEffort' => 'high', 'n' => '1', 'applied' => '1', 'avgMs' => '10'],
        ], $this->findTableRowsOfSection($statistics, 'Effort decisions per arm × precondition × decidedBy × effective effort'));
        self::assertSame(
            [['arm' => 'on', 'turns' => '3', 'asked' => '1', 'askedShare' => '0.333', 'raisedMedium' => '0', 'raisedHigh' => '1', 'raiseShare' => '0.333']],
            $this->findTableRowsOfSection($statistics, 'Raise share per arm (owner turns → asked → raised)')
        );
        self::assertSame([
            ['arm' => 'on', 'sentEffort' => '(none)', 'calls' => '1', 'avgInputTokens' => '50', 'avgOutputTokens' => '5', 'costUsd' => '0.0010'],
            ['arm' => 'on', 'sentEffort' => 'high', 'calls' => '2', 'avgInputTokens' => '200', 'avgOutputTokens' => '20', 'costUsd' => '0.0375'],
        ], $this->findTableRowsOfSection($statistics, 'Model calls per arm × effort sent (tokens, cost)'));
    }

    public function testTheStallRecoverySiteCountsDecisionsForcedReDrivesAndNudgesPerArm(): void
    {
        $this->insertConversation(400, ['decisionModelStallRecovery' => 'on']);
        $this->insertConversation(401, []);
        foreach ([0, 3] as $initiatorMessageIndex) {
            $this->insertDecisionEvent(400, AgentDecisionModelSettings::SITE_STALL_RECOVERY, 'x', $initiatorMessageIndex, [
                'stallShape' => 'textOnly', 'decidedBy' => 'model', 'move' => 'nudge', 'applied' => true,
            ], ['elapsedMs' => 40]);
        }
        $this->insertDecisionEvent(401, AgentDecisionModelSettings::SITE_STALL_RECOVERY, 'on', 0, [
            'stallShape' => 'silent', 'decidedBy' => 'rule', 'move' => 'wait', 'applied' => false,
        ], ['elapsedMs' => 20]);
        $this->insertEvent(400, AIConversationTurnEvent::KIND_FORCED_RE_DRIVE, null, null, 0, ['forcedBy' => null, 'succeeded' => true], []);
        $this->insertEvent(400, AIConversationTurnEvent::KIND_FORCED_RE_DRIVE, null, null, 3, ['forcedBy' => 'model', 'succeeded' => false], []);
        foreach ([[400, 0], [400, 1], [401, 0]] as [$conversationId, $indexInConversation]) {
            $this->insertMessage($conversationId, $indexInConversation, [
                'role' => AIConversationMessage::ROLE_SYSTEM, 'messageType' => AIConversationMessage::MESSAGE_SIGNAL,
                'signalSource' => AIConversationSignalSource::LOOP_NUDGE->value,
            ]);
        }

        $statistics = $this->statisticsService->buildStatistics($this->buildRequest(AgentDecisionModelSettings::SITE_STALL_RECOVERY));

        self::assertSame([
            ['arm' => 'inherit', 'stallShape' => 'silent', 'decidedBy' => 'rule', 'move' => 'wait', 'n' => '1', 'applied' => '0', 'avgMs' => '20'],
            ['arm' => 'on', 'stallShape' => 'textOnly', 'decidedBy' => 'model', 'move' => 'nudge', 'n' => '2', 'applied' => '2', 'avgMs' => '40'],
        ], $this->findTableRowsOfSection($statistics, 'Stall decisions per arm × shape × decidedBy × move'));
        self::assertSame([
            ['arm' => 'on', 'forcedBy' => 'ladder', 'n' => '1', 'succeeded' => '1'],
            ['arm' => 'on', 'forcedBy' => 'model', 'n' => '1', 'succeeded' => '0'],
        ], $this->findTableRowsOfSection($statistics, 'Forced re-drives per arm × who forced (succeeded = a tool call came back)'));
        self::assertSame([
            ['arm' => 'inherit', 'nudges' => '1', 'runs' => '1', 'perRun' => '1.00'],
            ['arm' => 'on', 'nudges' => '2', 'runs' => '1', 'perRun' => '2.00'],
        ], $this->findTableRowsOfSection($statistics, 'Stall nudges per arm (per run)'));
    }

    protected function buildRequest(string $site): AgentDecisionStatisticsRequest
    {
        $request = new AgentDecisionStatisticsRequest();
        $request->site = $site;
        $request->from = self::WINDOW_FROM;
        $request->to = self::WINDOW_TO;
        $request->cutoff = self::CUTOFF;
        return $request;
    }

    /** @param array<string, mixed> $runSettings */
    protected function insertConversation(int $conversationId, array $runSettings): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => $conversationId, 'runSettings' => json_encode((object)$runSettings)]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $columnValues
     */
    protected function insertDecisionEvent(int $conversationId, string $site, string $arm, int $initiatorMessageIndex, array $payload, array $columnValues): void
    {
        $this->insertEvent($conversationId, AIConversationTurnEvent::KIND_DECISION, $site, $arm, $initiatorMessageIndex, $payload, $columnValues);
    }

    /**
     * One turn event on the decision day, unless `$columnValues` names another moment.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $columnValues
     */
    protected function insertEvent(int $conversationId, string $kind, ?string $site, ?string $arm, int $initiatorMessageIndex, array $payload, array $columnValues): void
    {
        $eventId = $this->nextEventId++;
        $this->insertRow(DBAIConversationTurnEventModel::getTableName(), $columnValues + [
            'id' => $eventId,
            'aiConversationId' => $conversationId,
            'kind' => $kind,
            'site' => $site,
            'arm' => $arm,
            'eventKey' => 'event-' . $eventId,
            'initiatorMessageIndex' => $initiatorMessageIndex,
            'evaluationMessageIndex' => $initiatorMessageIndex,
            'payload' => json_encode((object)$payload),
            'created' => self::DECISION_DAY . ' 10:00:00',
        ]);
    }

    /**
     * One message row on the decision day, unless `$columnValues` names another moment.
     *
     * @param array<string, mixed> $columnValues
     */
    protected function insertMessage(int $conversationId, int $indexInConversation, array $columnValues): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), $columnValues + [
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'message' => '',
            'created' => self::DECISION_DAY . ' 10:00:05',
        ]);
    }

    protected function insertBounceSignal(int $conversationId, int $indexInConversation, string $created): void
    {
        $this->insertMessage($conversationId, $indexInConversation, [
            'role' => AIConversationMessage::ROLE_SYSTEM,
            'messageType' => AIConversationMessage::MESSAGE_SIGNAL,
            'signalSource' => AIConversationSignalSource::TASK_PLAN_OPEN_BOUNCE->value,
            'created' => $created,
        ]);
    }

    protected function findSection(AgentDecisionStatistics $statistics, string $title): AgentDecisionStatisticsSection
    {
        foreach ($statistics->sections as $section) {
            if ($section->title === $title) {
                return $section;
            }
        }
        self::fail('no section titled "' . $title . '"');
    }

    /** @return array<int, array<string, string>> The cells of each table row by column name */
    protected function findTableRowsOfSection(AgentDecisionStatistics $statistics, string $title): array
    {
        $section = $this->findSection($statistics, $title);
        return array_map(
            static fn ($statisticsRow): array => array_combine($section->columns, $statisticsRow->cells),
            $section->rows
        );
    }

    /** @return string[] */
    protected function findTextLinesOfSection(AgentDecisionStatistics $statistics, string $title): array
    {
        return $this->findSection($statistics, $title)->textLines;
    }
}
