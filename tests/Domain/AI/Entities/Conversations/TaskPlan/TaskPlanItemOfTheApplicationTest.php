<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanItemOfAnApplicationWithAGuidedSetup;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The module's plan item carries what the module itself reads. An application whose purposes declare fields of their
 * own (a connection an item waits for, an offer it parks, a step an item completes) declares them on an item class of
 * the same path: the seed, a stored plan and the model's update then build that class, and its fields are read,
 * stored and counted as declarations.
 */
class TaskPlanItemOfTheApplicationTest extends UnitTestCase
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

    public function testTheModulesItemDeclaresNoFieldOfOneApplicationsRun(): void
    {
        $reflectionClass = new ReflectionClass(TaskPlanItem::class);
        $memberNames = array_merge(
            array_map(static fn ($property): string => $property->getName(), $reflectionClass->getProperties()),
            array_keys($reflectionClass->getConstants()),
            array_map(static fn ($method): string => $method->getName(), $reflectionClass->getMethods())
        );

        foreach (['setup', 'interfaceLanguage', 'listingImport', 'google', 'social'] as $applicationTopic) {
            foreach ($memberNames as $memberName) {
                self::assertStringNotContainsStringIgnoringCase($applicationTopic, $memberName);
            }
        }
        self::assertSame([], TaskPlanItem::CONNECTED_CHANNEL_LABELS);
    }

    public function testWithoutAnApplicationClassAConnectionNameIsNoGate(): void
    {
        StaticRegistry::$frameworkToAppClassNames[TaskPlanItem::class] = TaskPlanItem::class;

        $taskPlanItem = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Connect the calendar', 'doneRequiresConnectedChannel' => 'google'],
        ])->getElements()[0];

        self::assertSame(TaskPlanItem::class, $taskPlanItem::class);
        self::assertNull($taskPlanItem->doneRequiresConnectedChannel);
        self::assertArrayNotHasKey('completesSetupStepName', $taskPlanItem->toRepositoryFields());
    }

    public function testTheSeedBuildsTheApplicationsItemAndReadsItsDeclarations(): void
    {
        StaticRegistry::$frameworkToAppClassNames[TaskPlanItem::class] = TaskPlanItemOfAnApplicationWithAGuidedSetup::class;

        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Connect offer', 'doneRequiresConnectedChannel' => 'Google', 'deferrableAtSetupEnd' => true, 'subtasks' => [
                ['text' => 'Handover', 'completesSetupStepName' => ' basics '],
            ]],
            ['text' => 'Plain item', 'doneRequiresConnectedChannel' => 'calendar'],
        ]);
        [$connectOffer, $plainItem] = $taskPlanItems->getElements();
        $handover = $connectOffer->subtasks->getElements()[0];

        self::assertInstanceOf(TaskPlanItemOfAnApplicationWithAGuidedSetup::class, $connectOffer);
        self::assertInstanceOf(TaskPlanItemOfAnApplicationWithAGuidedSetup::class, $handover);
        self::assertSame('google', $connectOffer->doneRequiresConnectedChannel);
        self::assertSame('the Google Business Profile', $connectOffer->getConnectedChannelLabel());
        self::assertTrue($connectOffer->deferrableAtSetupEnd);
        self::assertSame('basics', $handover->completesSetupStepName);
        self::assertTrue($handover->isClosedByThePlatformFromTheRecord());
        self::assertNull($plainItem->doneRequiresConnectedChannel, 'a name the application does not declare is no gate');
        self::assertFalse($plainItem->hasAnyDeclaration());
        self::assertTrue($handover->hasAnyDeclaration());
    }

    public function testAStoredPlanIsReadBackAsTheApplicationsItemWithItsFieldsAtTheirPlace(): void
    {
        StaticRegistry::$frameworkToAppClassNames[TaskPlanItem::class] = TaskPlanItemOfAnApplicationWithAGuidedSetup::class;
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Handover', 'completesSetupStepName' => 'basics', 'confirmsInterfaceLanguage' => true],
        ]);
        $taskPlanItems->getElements()[0]->deferredAtSetupEnd = true;

        $storedItems = json_decode((string)json_encode($taskPlanItems->toRepositoryArray()), true);
        $storedFieldNames = array_keys($storedItems[0]);
        $readBackItem = TaskPlanItems::fromRepositoryArray($storedItems)->getElements()[0];

        self::assertSame(
            ['evidenceMessageIndex', 'deferrableAtSetupEnd', 'deferredAtSetupEnd', 'offerRenderedAtMessageIndex'],
            array_slice($storedFieldNames, (int)array_search('evidenceMessageIndex', $storedFieldNames, true), 4)
        );
        self::assertSame(
            ['triggersSupervisorMilestoneCheckpoint', 'completesSetupStepName', 'confirmsInterfaceLanguage', 'customerProgressStepKey'],
            array_slice($storedFieldNames, (int)array_search('triggersSupervisorMilestoneCheckpoint', $storedFieldNames, true), 4)
        );
        self::assertInstanceOf(TaskPlanItemOfAnApplicationWithAGuidedSetup::class, $readBackItem);
        self::assertSame('basics', $readBackItem->completesSetupStepName);
        self::assertTrue($readBackItem->confirmsInterfaceLanguage);
        self::assertTrue($readBackItem->deferredAtSetupEnd);
        self::assertSame(' (deferred at setup end)', $readBackItem->getBlockedMarkOnJudgeLine());
    }
}
