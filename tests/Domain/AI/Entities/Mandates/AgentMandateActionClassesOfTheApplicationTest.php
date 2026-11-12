<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Mandates;

use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Mcp\Client\AI\Dtos\MandateGrantRequestDto;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionProperty;

/**
 * The action classes a mandate can be granted for belong to the application. The package declares the one class of
 * the unattended runs it owns; an application lists its own classes in its mandate class, and the allowed values of
 * the stored column, the `grantMandate` tool, the descriptions and the standing-grant rule are read off that class.
 */
class AgentMandateActionClassesOfTheApplicationTest extends UnitTestCase
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

    public function testThePackageDeclaresOnlyTheClassOfTheUnattendedRunsItOwns(): void
    {
        self::assertSame([AgentMandate::ACTION_STANDING_AUTOMATION], AgentMandate::ACTION_CLASSES);
        self::assertSame([AgentMandate::ACTION_STANDING_AUTOMATION], AgentMandate::ACTION_CLASSES_NEEDING_A_STANDING_GRANT);
        self::assertSame([AgentMandate::ACTION_STANDING_AUTOMATION], array_keys(AgentMandate::ACTION_CLASS_DESCRIPTIONS));
    }

    public function testWithoutAnApplicationClassTheListsAreThePackagesOwn(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentMandate::class] = AgentMandate::class;

        self::assertSame(AgentMandate::class, AgentMandate::getMandateClassOfTheApplication());
        self::assertSame(AgentMandate::ACTION_CLASSES, AgentMandate::getActionClasses());
        self::assertTrue(AgentMandate::isStandingGrantNeededForActionClass(AgentMandate::ACTION_STANDING_AUTOMATION));
        self::assertSame('AN_UNDESCRIBED_CLASS', AgentMandate::getActionClassDescription('AN_UNDESCRIBED_CLASS'));
    }

    public function testTheListsOfAnApplicationsMandateClassAreTheOnesRead(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentMandate::class] = AgentMandateOfAnApplicationWithItsOwnActionClasses::class;

        self::assertSame(AgentMandateOfAnApplicationWithItsOwnActionClasses::class, AgentMandate::getMandateClassOfTheApplication());
        self::assertSame(
            [AgentMandate::ACTION_STANDING_AUTOMATION, 'NEWSLETTER_SEND', 'NEWSLETTER_SEND_AUTO'],
            AgentMandate::getActionClasses()
        );
        self::assertSame('Sending a newsletter to the subscribers.', AgentMandate::getActionClassDescription('NEWSLETTER_SEND'));
        self::assertFalse(AgentMandate::isStandingGrantNeededForActionClass('NEWSLETTER_SEND'));
        self::assertTrue(AgentMandate::isStandingGrantNeededForActionClass('NEWSLETTER_SEND_AUTO'));
        self::assertTrue(AgentMandate::isStandingGrantNeededForActionClass(AgentMandate::ACTION_STANDING_AUTOMATION));
    }

    public function testTheAllowedValuesOfTheStoredColumnAndOfTheGrantToolAreTheApplicationsList(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentMandate::class] = AgentMandateOfAnApplicationWithItsOwnActionClasses::class;
        $expectedActionClasses = [AgentMandate::ACTION_STANDING_AUTOMATION, 'NEWSLETTER_SEND', 'NEWSLETTER_SEND_AUTO'];

        foreach ([AgentMandate::class, AgentMandateOfAnApplicationWithItsOwnActionClasses::class, MandateGrantRequestDto::class] as $className) {
            $choiceAttributes = (new ReflectionProperty($className, 'actionClass'))->getAttributes(Choice::class);
            self::assertCount(1, $choiceAttributes, $className);
            self::assertSame(
                $expectedActionClasses,
                Choice::getChoicesResolvedFromConstraint($choiceAttributes[0]->newInstance()),
                $className
            );
        }
    }
}

/** An application's mandate class: the package's action class plus two of its own. */
class AgentMandateOfAnApplicationWithItsOwnActionClasses extends AgentMandate
{
    public const string ACTION_NEWSLETTER_SEND = 'NEWSLETTER_SEND';

    public const string ACTION_NEWSLETTER_SEND_AUTO = 'NEWSLETTER_SEND_AUTO';

    public const array ACTION_CLASSES = [self::ACTION_STANDING_AUTOMATION, self::ACTION_NEWSLETTER_SEND, self::ACTION_NEWSLETTER_SEND_AUTO];

    public const array ACTION_CLASSES_NEEDING_A_STANDING_GRANT = [self::ACTION_STANDING_AUTOMATION, self::ACTION_NEWSLETTER_SEND_AUTO];

    public const array ACTION_CLASS_DESCRIPTIONS = [
        self::ACTION_STANDING_AUTOMATION => 'Creating or altering unattended recurring agent runs (schedules, background tasks).',
        self::ACTION_NEWSLETTER_SEND => 'Sending a newsletter to the subscribers.',
        self::ACTION_NEWSLETTER_SEND_AUTO => 'Sending newsletters automatically, without the customer.',
    ];
}
