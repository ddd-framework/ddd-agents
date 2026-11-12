<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\StateDelivery;

use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentConversationState;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentSpaceContextFields;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateChangeDetection;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDelta;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDeltaTexts;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The pure parts of the state notes: the fields of the space's context text as the application's class reads them and
 * the change lines between two texts, the value per kind and the epoch record, the change detection over every kind and
 * the task-plan rule (whole plan at the start, on a structural change and after eight notes; a note otherwise).
 */
class AgentStateDeltasTest extends UnitTestCase
{
    public const string AGENT_SPACE_CONTEXT_BEFORE = 'Name: Café Rosa; Categories: [Café, Bakery]; Website: https://cafe-rosa.de; ';

    public const string AGENT_SPACE_CONTEXT_AFTER = 'Name: Café Rosa & Sohn; Categories: [Café, Bakery, Breakfast restaurant]; ';

    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::tearDown();
    }

    public function testWithoutAnApplicationClassNoFieldIsReadAndTheNoteCarriesTheWholeNewText(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentSpaceContextFields::class] = AgentSpaceContextFields::class;

        $fieldsAfter = AgentSpaceContextFields::parse(self::AGENT_SPACE_CONTEXT_AFTER);
        self::assertSame(AgentSpaceContextFields::class, $fieldsAfter::class);
        self::assertSame([], $fieldsAfter->valuesByField);
        self::assertSame([], $fieldsAfter->getFieldsInOrder());
        self::assertSame([], $fieldsAfter->describeChangesSince(AgentSpaceContextFields::parse(self::AGENT_SPACE_CONTEXT_BEFORE)));

        self::assertSame(
            AgentStateDeltaTexts::buildAgentSpaceContextLead() . "\n- " . trim(self::AGENT_SPACE_CONTEXT_AFTER) . "\n" . AgentStateDeltaTexts::BACKGROUND_SUFFIX,
            AgentStateDeltaTexts::buildAgentSpaceContextText(self::AGENT_SPACE_CONTEXT_BEFORE, self::AGENT_SPACE_CONTEXT_AFTER)
        );
        self::assertSame(
            AgentStateDeltaTexts::buildAgentSpaceContextLead() . "\n- no work context any more\n" . AgentStateDeltaTexts::BACKGROUND_SUFFIX,
            AgentStateDeltaTexts::buildAgentSpaceContextText(self::AGENT_SPACE_CONTEXT_BEFORE, null)
        );
    }

    public function testTheChangeLinesNameEachChangedFieldInTheOrderTheApplicationDeclares(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentSpaceContextFields::class] = AgentSpaceContextFieldsOfAnApplicationWithLabels::class;

        $fieldsBefore = AgentSpaceContextFields::parse(self::AGENT_SPACE_CONTEXT_BEFORE);
        self::assertInstanceOf(AgentSpaceContextFieldsOfAnApplicationWithLabels::class, $fieldsBefore, 'the module builds the class of the application');
        self::assertSame(
            ['Name' => 'Café Rosa', 'Categories' => '[Café, Bakery]', 'Website' => 'https://cafe-rosa.de'],
            $fieldsBefore->valuesByField
        );

        $fieldsAfter = AgentSpaceContextFields::parse(self::AGENT_SPACE_CONTEXT_AFTER);
        self::assertSame([
            'Name: "Café Rosa" → "Café Rosa & Sohn"',
            'Categories: "[Café, Bakery]" → "[Café, Bakery, Breakfast restaurant]"',
            'Website: removed (was "https://cafe-rosa.de")',
        ], $fieldsAfter->describeChangesSince($fieldsBefore));
        self::assertSame(['Website: added "https://cafe-rosa.de"'], AgentSpaceContextFields::parse('Website: https://cafe-rosa.de; ')->describeChangesSince(AgentSpaceContextFields::parse('')));
        self::assertSame([], $fieldsAfter->describeChangesSince(AgentSpaceContextFields::parse(self::AGENT_SPACE_CONTEXT_AFTER)));

        $noteText = AgentStateDeltaTexts::buildAgentSpaceContextText(self::AGENT_SPACE_CONTEXT_BEFORE, self::AGENT_SPACE_CONTEXT_AFTER);
        self::assertStringStartsWith(AgentStateDeltaTexts::buildAgentSpaceContextLead() . "\n- Name: ", $noteText);
        self::assertStringEndsWith("\n" . AgentStateDeltaTexts::BACKGROUND_SUFFIX, $noteText);
    }

    public function testAClassOfAnApplicationReadsItsOwnFieldsWhenItIsCalledItself(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentSpaceContextFields::class] = AgentSpaceContextFields::class;

        $fields = AgentSpaceContextFieldsOfAnApplicationWithLabels::parse(self::AGENT_SPACE_CONTEXT_BEFORE);
        self::assertInstanceOf(AgentSpaceContextFieldsOfAnApplicationWithLabels::class, $fields);
        self::assertSame('Café Rosa', $fields->valuesByField[AgentSpaceContextFieldsOfAnApplicationWithLabels::FIELD_NAME]);
        self::assertSame(['Name', 'Categories', 'Website'], $fields->getFieldsInOrder());
    }

    public function testTheEpochRecordKeepsTheSystemValuesAndRoundTrips(): void
    {
        $currentState = self::buildCurrentState();
        $currentState->timezoneUnconfirmedNote = '[SIGNAL:time-zone] Europe/Berlin is the country default — …';
        $currentState->situationNote = '[SIGNAL:situation] Of the situation …';
        $currentState->unknownKinds = [AgentStateDelta::getKindOfAgentSpaceContext()];

        $epochRecord = $currentState->buildEpochRecord();
        self::assertSame(self::AGENT_SPACE_CONTEXT_BEFORE, $epochRecord->agentSpaceContext);
        self::assertSame('Europe/Berlin', $epochRecord->timezoneIdentifier);
        self::assertNull($epochRecord->timezoneUnconfirmedNote, 'a tail-only value is delivered by the epoch\'s first call');
        self::assertNull($epochRecord->situationNote);
        self::assertSame([AgentStateDelta::getKindOfAgentSpaceContext()], $epochRecord->unknownKinds, 'a base value the run could not resolve stays unknown in the record (verifier A MINOR-2)');
        self::assertEquals($epochRecord, AgentConversationState::fromArray($epochRecord->toArray()), 'the snapshot form round-trips');

        $delta = AgentStateDelta::of(AgentStateDelta::KIND_TIME_ZONE, 'note', ['zone' => 'Europe/London', 'unconfirmedNote' => null]);
        $afterTheDelta = $epochRecord->withDeltaApplied($delta);
        self::assertSame('Europe/London', $afterTheDelta->timezoneIdentifier);
        self::assertSame('Europe/Berlin', $epochRecord->timezoneIdentifier, 'the record itself is not changed');
        self::assertEquals($delta, AgentStateDelta::fromArray($delta->toArray()));
        self::assertNull(AgentStateDelta::fromArray(['kind' => 'x']));
    }

    public function testTheChangeDetectionDeliversEveryChangedKindInOrder(): void
    {
        $seenState = self::buildCurrentState()->buildEpochRecord();
        $currentState = self::buildCurrentState();
        self::assertSame([], AgentStateChangeDetection::buildDeltasFor($seenState, $currentState), 'nothing changed, nothing owed');

        $currentState->agentSpaceContext = self::AGENT_SPACE_CONTEXT_AFTER;
        $currentState->ownerAddressing = 'Address the owner as "du".';
        $currentState->timezoneIdentifier = 'Europe/London';
        $currentState->memoryDirectives = "\n\n# Standing instructions\n- Never post on Sundays.";
        $currentState->aiBudgetAwareness = 'Fair use.';
        $currentState->situationNote = '[SIGNAL:situation] Of the situation in the loaded-skills block: businessClass=walkIn is proposed.';
        $deltas = AgentStateChangeDetection::buildDeltasFor($seenState, $currentState);

        self::assertSame([
            AgentStateDelta::getKindOfAgentSpaceContext(),
            AgentStateDelta::KIND_OWNER_ADDRESSING,
            AgentStateDelta::KIND_TIME_ZONE,
            AgentStateDelta::KIND_STANDING_INSTRUCTIONS,
            AgentStateDelta::KIND_PLAN_OF_THE_PRODUCT,
            AgentStateDelta::KIND_SITUATION,
        ], array_map(static fn (AgentStateDelta $delta): string => $delta->kind, $deltas));
        self::assertSame(AgentStateDeltaTexts::OWNER_ADDRESSING_LEAD . "\nAddress the owner as \"du\".", $deltas[1]->text);
        self::assertStringContainsString('now Europe/London (was Europe/Berlin)', $deltas[2]->text);
        self::assertStringContainsString("# AI budget\n\nFair use.", $deltas[4]->text);
        self::assertStringNotContainsString('# The customer\'s plan', $deltas[4]->text, 'only the changed block');
        self::assertSame($currentState->situationNote, $deltas[5]->text, 'the provenance note keeps today\'s text');

        $seenAfterTheDeltas = $seenState;
        foreach ($deltas as $delta) {
            $seenAfterTheDeltas = $seenAfterTheDeltas->withDeltaApplied($delta);
        }
        self::assertSame([], AgentStateChangeDetection::buildDeltasFor($seenAfterTheDeltas, $currentState), 'once delivered, a change is not delivered again');

        $currentState->unknownKinds = [AgentStateDelta::getKindOfAgentSpaceContext()];
        $currentState->agentSpaceContext = 'another text';
        self::assertSame([], AgentStateChangeDetection::buildDeltasFor($seenAfterTheDeltas, $currentState), 'an unknown value is never compared');

        $confirmedState = clone $currentState;
        $seenWithUnconfirmedNote = clone $seenAfterTheDeltas;
        $seenWithUnconfirmedNote->timezoneUnconfirmedNote = '[SIGNAL:time-zone] Europe/London is the country default — …';
        $confirmationDeltas = AgentStateChangeDetection::buildDeltasFor($seenWithUnconfirmedNote, $confirmedState);
        self::assertSame('[SIGNAL:time-zone] Europe/London is confirmed now — no need to ask for the time zone.', $confirmationDeltas[0]->text);
    }

    /**
     * A note about the space's context text names every field changed since the epoch record — the line the model
     * reads — so the newest note carries the whole current delta and may replace the earlier ones (the rule says so).
     */
    public function testTheNewestNoteAboutTheContextTextCarriesEveryChangeSinceTheRecord(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentSpaceContextFields::class] = AgentSpaceContextFieldsOfAnApplicationWithLabels::class;
        $epochRecord = self::buildCurrentState();
        $epochRecord->agentSpaceContext = 'Name: Alpha Bakery; Categories: [Bakery]; ';
        $epochRecord = $epochRecord->buildEpochRecord();
        $afterTheNameWrite = clone $epochRecord;
        $afterTheNameWrite->agentSpaceContext = 'Name: Beta Bakery; Categories: [Bakery]; ';
        $firstNotes = AgentStateChangeDetection::buildDeltasFor($epochRecord, $afterTheNameWrite, $epochRecord);
        self::assertSame(AgentStateDeltaTexts::buildAgentSpaceContextLead() . "\n- Name: \"Alpha Bakery\" → \"Beta Bakery\"\n" . AgentStateDeltaTexts::BACKGROUND_SUFFIX, $firstNotes[0]->text);

        $seenAfterTheFirstNote = $epochRecord->withDeltaApplied($firstNotes[0]);
        $afterTheCategoriesWrite = clone $afterTheNameWrite;
        $afterTheCategoriesWrite->agentSpaceContext = 'Name: Beta Bakery; Categories: [Bakery, Cafe]; ';
        $secondNotes = AgentStateChangeDetection::buildDeltasFor($seenAfterTheFirstNote, $afterTheCategoriesWrite, $epochRecord);
        self::assertSame(
            AgentStateDeltaTexts::buildAgentSpaceContextLead() . "\n- Name: \"Alpha Bakery\" → \"Beta Bakery\"\n- Categories: \"[Bakery]\" → \"[Bakery, Cafe]\"\n" . AgentStateDeltaTexts::BACKGROUND_SUFFIX,
            $secondNotes[0]->text,
            'the second note keeps the name: read as a replacement, it loses nothing'
        );

        $seenAfterTheSecondNote = $seenAfterTheFirstNote->withDeltaApplied($secondNotes[0]);
        $backToTheRecord = clone $afterTheCategoriesWrite;
        $backToTheRecord->agentSpaceContext = $epochRecord->agentSpaceContext;
        $revertNotes = AgentStateChangeDetection::buildDeltasFor($seenAfterTheSecondNote, $backToTheRecord, $epochRecord);
        self::assertSame(AgentStateDeltaTexts::buildAgentSpaceContextAsInInstructionsText() . "\n" . AgentStateDeltaTexts::BACKGROUND_SUFFIX, $revertNotes[0]->text, 'a write back to the record says the instructions\' line holds again');
    }

    protected static function buildCurrentState(): AgentConversationState
    {
        $agentConversationState = new AgentConversationState();
        $agentConversationState->agentSpaceContext = self::AGENT_SPACE_CONTEXT_BEFORE;
        $agentConversationState->ownerAddressing = 'Address the owner as "Sie".';
        $agentConversationState->timezoneIdentifier = 'Europe/Berlin';
        $agentConversationState->productCapabilities = 'Plan: Pro.';
        $agentConversationState->surfaceKey = 'PRESENT_IN_CHAT|screen|kit';
        return $agentConversationState;
    }

    /** @param string[] $itemTexts */
    protected static function buildTaskPlan(array $itemTexts): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = TaskPlanItems::fromPurposeSeedDefinition($itemTexts);
        return $taskPlan;
    }
}
