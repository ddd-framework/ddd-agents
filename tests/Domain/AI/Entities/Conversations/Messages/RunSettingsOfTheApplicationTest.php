<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The module's run settings carry what the module itself reads. An application whose own runs need a setting of
 * their own declares it on a same-path class: the module builds that class wherever it creates run settings, and a
 * stored value that names the class is read back as that class, with the application's setting.
 */
class RunSettingsOfTheApplicationTest extends UnitTestCase
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

    public function testTheModulesRunSettingsDeclareNoSettingOfOneApplication(): void
    {
        $propertyNames = [];
        foreach ((new ReflectionClass(RunSettings::class))->getProperties() as $property) {
            $propertyNames[] = $property->getName();
        }

        foreach (['dayContext', 'localSignals', 'chronicle', 'cancellationReview'] as $applicationTopic) {
            foreach ($propertyNames as $propertyName) {
                self::assertStringNotContainsStringIgnoringCase($applicationTopic, $propertyName);
            }
        }
    }

    public function testWithoutAnApplicationClassTheModuleBuildsItsOwnRunSettings(): void
    {
        StaticRegistry::$frameworkToAppClassNames[RunSettings::class] = RunSettings::class;

        $runSettingsForRun = $this->buildScheduleWithACostCap()->buildRunSettingsForRun();

        self::assertSame(RunSettings::class, $runSettingsForRun::class);
    }

    public function testTheModuleBuildsTheApplicationsRunSettings(): void
    {
        StaticRegistry::$frameworkToAppClassNames[RunSettings::class] = RunSettingsOfAnApplicationWithNightlyReports::class;

        $runSettingsForRun = $this->buildScheduleWithACostCap()->buildRunSettingsForRun();

        self::assertInstanceOf(RunSettingsOfAnApplicationWithNightlyReports::class, $runSettingsForRun);
        self::assertSame(1.5, $runSettingsForRun->maxTurnCostUsd);
        self::assertNull($runSettingsForRun->nightlyReportId);
    }

    public function testAStoredValueThatNamesTheApplicationsClassIsReadBackAsThatClass(): void
    {
        $runSettings = new RunSettingsOfAnApplicationWithNightlyReports();
        $runSettings->maxStepsPerTurn = 40;
        $runSettings->nightlyReportId = 812;
        $storedRunSettings = json_decode(json_encode($runSettings->toObject(forPersistence: true)));

        self::assertSame(RunSettingsOfAnApplicationWithNightlyReports::class, $storedRunSettings->objectType);

        $conversation = new AIConversation();
        $storedConversation = (object)['runSettings' => $storedRunSettings];
        $conversation->setPropertiesFromObject($storedConversation);

        self::assertInstanceOf(RunSettingsOfAnApplicationWithNightlyReports::class, $conversation->runSettings);
        self::assertSame(40, $conversation->runSettings->maxStepsPerTurn);
        self::assertSame(812, $conversation->runSettings->nightlyReportId);
    }

    protected function buildScheduleWithACostCap(): AIConversationSchedule
    {
        $schedule = new AIConversationSchedule();
        $schedule->perRunCostCapUsd = 1.5;
        return $schedule;
    }
}

/** An application whose nightly report runs record the report row they write. */
class RunSettingsOfAnApplicationWithNightlyReports extends RunSettings
{
    /** @var int|null The report row a nightly report run writes; null on every other conversation. */
    public ?int $nightlyReportId = null;
}
