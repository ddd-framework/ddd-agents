<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\StateDelivery;

use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentConversationState;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDelta;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Libs\Arr;

/**
 * A defect found in an A/B comparison: the state epoch record and the state notes ride the request
 * snapshot, which the message row stores as JSON. Every kind is written as the repository writes it
 * (`mapToRepository()` → JSON), read back as the repository reads it (the JSON column decoded to an array →
 * `mapFromRepository()`) and rebuilt with `fromArray()`; the rebuilt values must equal the written ones, and a task plan
 * whose items carry keys `"1"`, `"1.1"`, `"2"` must reload (a key map mixed integer and string keys and made every later
 * turn of the conversation fail to load its rows).
 */
class AgentStateDeliverySnapshotRoundTripTest extends UnitTestCase
{
    public function testEveryStateKindSurvivesPersistReloadAndFromArray(): void
    {
        $epochRecord = self::buildEpochRecord();
        $writtenDeltas = [
            AgentStateDelta::of(AgentStateDelta::getKindOfAgentSpaceContext(), '[SIGNAL:work-context] changed', 'Name: Café Rosa & Sohn; '),
            AgentStateDelta::of(AgentStateDelta::KIND_OWNER_ADDRESSING, '[SIGNAL:owner-addressing] du', 'Address the owner as "du".'),
            AgentStateDelta::of(AgentStateDelta::KIND_TIME_ZONE, '[SIGNAL:time-zone] London', ['zone' => 'Europe/London', 'unconfirmedNote' => null]),
            AgentStateDelta::of(AgentStateDelta::KIND_STANDING_INSTRUCTIONS, '[SIGNAL:standing-instructions] none', null),
            AgentStateDelta::of(AgentStateDelta::KIND_PLAN_OF_THE_PRODUCT, '[SIGNAL:plan-of-the-product] budget', [
                'productCapabilities' => 'Plan: Pro.',
                'aiBudgetAwareness' => 'Fair use.',
                'restrictedCapabilities' => null,
            ]),
            AgentStateDelta::of(AgentStateDelta::KIND_SITUATION, '[SIGNAL:situation] confirmed', null),
        ];
        $requestSnapshot = new RequestSnapshot();
        $requestSnapshot->stateEpochStartIndex = 3;
        $requestSnapshot->stateEpochCause = 'conversationStart';
        $requestSnapshot->stateAtEpochStart = $epochRecord->toArray();
        $requestSnapshot->stateDeltas = array_map(static fn (AgentStateDelta $agentStateDelta): array => $agentStateDelta->toArray(), $writtenDeltas);

        $reloadedSnapshot = self::persistAndReload($requestSnapshot);

        self::assertSame(3, $reloadedSnapshot->stateEpochStartIndex);
        self::assertEquals($epochRecord, AgentConversationState::fromArray((array)$reloadedSnapshot->stateAtEpochStart), 'the epoch record reloads whole');
        $reloadedDeltas = array_map(static fn (array $deltaArray): ?AgentStateDelta => AgentStateDelta::fromArray($deltaArray), (array)$reloadedSnapshot->stateDeltas);
        self::assertCount(count($writtenDeltas), $reloadedDeltas);
        foreach ($writtenDeltas as $deltaPosition => $writtenDelta) {
            $reloadedDelta = $reloadedDeltas[$deltaPosition];
            self::assertNotNull($reloadedDelta, 'the ' . $writtenDelta->kind . ' note reloads (#' . $deltaPosition . ')');
            self::assertSame($writtenDelta->kind, $reloadedDelta->kind);
            self::assertSame($writtenDelta->text, $reloadedDelta->text, 'the literal note text is re-rendered byte for byte');
            // the stored form drops null members (`unconfirmedNote: null`); what the model has seen after the note is the same
            self::assertEquals(
                $epochRecord->withDeltaApplied($writtenDelta),
                $epochRecord->withDeltaApplied($reloadedDelta),
                'the ' . $writtenDelta->kind . ' note sets the same seen value after the reload (#' . $deltaPosition . ')'
            );
        }
    }

    public function testTheKeyMapOfTheFirstBuildCouldNotBeLoaded(): void
    {
        $this->expectExceptionMessage('both integer and string keys');
        Arr::toObject(['lines' => ['1' => '[ ] 1 A', '1.1' => '[ ] 1.1 B']]);
    }

    /** The repository's write and read of the snapshot column: `mapToRepository()` → JSON → array → `mapFromRepository()`. */
    protected static function persistAndReload(RequestSnapshot $requestSnapshot): RequestSnapshot
    {
        $storedJson = json_encode($requestSnapshot->mapToRepository(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $reloadedSnapshot = new RequestSnapshot();
        $reloadedSnapshot->mapFromRepository(json_decode($storedJson, true, 512, JSON_THROW_ON_ERROR));
        return $reloadedSnapshot;
    }

    /** An epoch record stored before the context text of the space got its present key is still read. */
    public function testAnEpochRecordStoredUnderTheFormerKeyOfTheContextTextIsRead(): void
    {
        $storedRecord = self::buildEpochRecord()->toArray();
        $storedRecord[AgentConversationState::FORMER_SNAPSHOT_KEY_OF_AGENT_SPACE_CONTEXT] = $storedRecord['agentSpaceContext'];
        unset($storedRecord['agentSpaceContext']);

        self::assertEquals(self::buildEpochRecord(), AgentConversationState::fromArray($storedRecord));
        self::assertArrayNotHasKey(AgentConversationState::FORMER_SNAPSHOT_KEY_OF_AGENT_SPACE_CONTEXT, AgentConversationState::fromArray($storedRecord)->toArray());
    }

    protected static function buildEpochRecord(): AgentConversationState
    {
        $agentConversationState = new AgentConversationState();
        $agentConversationState->agentSpaceContext = "Name: Café Rosa; Address: Hauptstraße 1, 10115 Berlin; \nKeywords: [\"café\"]";
        $agentConversationState->ownerAddressing = 'Address the owner as "Sie".';
        $agentConversationState->timezoneIdentifier = 'Europe/Berlin';
        $agentConversationState->memoryDirectives = "\n\n# Standing instructions\n- Never post on Sundays.";
        $agentConversationState->productCapabilities = 'Plan: Pro.';
        $agentConversationState->aiBudgetAwareness = 'Fair use.';
        $agentConversationState->restrictedCapabilities = 'Replies need manageReputation.';
        $agentConversationState->surfaceKey = 'PRESENT_IN_CHAT|screen|kit';
        $agentConversationState->unknownKinds = [AgentStateDelta::KIND_OWNER_ADDRESSING];
        return $agentConversationState->buildEpochRecord();
    }

    protected static function buildNestedTaskPlan(): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Woche planen', 'subtasks' => ['Wochentage festlegen', 'Zeiten festlegen']],
            'Termine eintragen',
        ]);
        return $taskPlan;
    }
}
