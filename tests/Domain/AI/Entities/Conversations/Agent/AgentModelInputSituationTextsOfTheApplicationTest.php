<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentModelInputTailEntry;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The day context and the local situation are texts the application composes; the module stores them and renders them
 * under a lead of its own. An application whose texts are a particular kind of text says so in a model input class of
 * the same path: its leads, and the part of the local situation it repeats beside the newest message.
 */
class AgentModelInputSituationTextsOfTheApplicationTest extends UnitTestCase
{
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

    public function testTheModulesLeadsSayNothingOfOneApplication(): void
    {
        $agentModelInput = $this->buildModelInputWithBothTexts(new AgentModelInput());
        $segmentTexts = $this->segmentTextsByType($agentModelInput);

        self::assertStringEndsWith("\n\nthe note of the day", $segmentTexts[AgentContextSegmentType::DAY_CONTEXT->value]);
        self::assertStringEndsWith("\n\nroad works on the corner", $segmentTexts[AgentContextSegmentType::LOCAL_SITUATION->value]);
        foreach (['business', 'start screen', 'greet', 'getLocal', 'promotion', 'owner'] as $applicationWord) {
            self::assertStringNotContainsStringIgnoringCase($applicationWord, $agentModelInput->getDayContextSegmentLead());
            self::assertStringNotContainsStringIgnoringCase($applicationWord, $agentModelInput->getLocalSituationSegmentLead());
        }
    }

    public function testTheModuleRepeatsNothingOfTheLocalSituationAtTheTail(): void
    {
        $agentModelInput = $this->buildModelInputWithBothTexts(new AgentModelInput());

        self::assertNull($agentModelInput->getLocalSituationTailHint());
        self::assertNotContains(AgentModelInputTailEntry::KIND_LOCAL_SITUATION, $this->tailEntryKinds($agentModelInput));
    }

    public function testTheModuleBuildsTheApplicationsModelInput(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentModelInput::class] = AgentModelInputOfAnApplicationWithATrafficReport::class;

        $agentModelInput = $this->buildModelInputWithBothTexts(AgentModelInput::newInstance());
        $segmentTexts = $this->segmentTextsByType($agentModelInput);

        self::assertInstanceOf(AgentModelInputOfAnApplicationWithATrafficReport::class, $agentModelInput);
        self::assertSame("\n\n# This morning's briefing\n\nthe note of the day", $segmentTexts[AgentContextSegmentType::DAY_CONTEXT->value]);
        self::assertSame("\n\n# Traffic around the depot\n\nroad works on the corner", $segmentTexts[AgentContextSegmentType::LOCAL_SITUATION->value]);
        self::assertContains(AgentModelInputTailEntry::KIND_LOCAL_SITUATION, $this->tailEntryKinds($agentModelInput));
    }

    protected function buildModelInputWithBothTexts(AgentModelInput $agentModelInput): AgentModelInput
    {
        $agentModelInput->systemPrompt = 'BASE';
        $agentModelInput->dayContext = 'the note of the day';
        $agentModelInput->localSituation = 'road works on the corner';
        return $agentModelInput;
    }

    /** @return array<string, string> */
    protected function segmentTextsByType(AgentModelInput $agentModelInput): array
    {
        $segmentTexts = [];
        foreach ($agentModelInput->buildSystemSegments() as $agentContextSegment) {
            $segmentTexts[$agentContextSegment->type->value] = $agentContextSegment->text;
        }
        return $segmentTexts;
    }

    /** @return string[] */
    protected function tailEntryKinds(AgentModelInput $agentModelInput): array
    {
        return array_map(
            static fn (AgentModelInputTailEntry $agentModelInputTailEntry): string => $agentModelInputTailEntry->kind,
            $agentModelInput->buildTailEntries(null, false)->getElements()
        );
    }
}

/** An application whose day context is a briefing and whose local situation is the traffic around a depot. */
class AgentModelInputOfAnApplicationWithATrafficReport extends AgentModelInput
{
    public function getDayContextSegmentLead(): string
    {
        return "\n\n# This morning's briefing\n\n";
    }

    public function getLocalSituationSegmentLead(): string
    {
        return "\n\n# Traffic around the depot\n\n";
    }

    public function getLocalSituationTailHint(): ?string
    {
        return $this->localSituation !== null ? '[SIGNAL:traffic] ' . $this->localSituation : null;
    }
}
