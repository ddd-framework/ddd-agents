<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationOrigin;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\AIConversationSummary;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClassConstant;

/**
 * The module registers the three purposes it runs itself. An application declares the names of its own purposes in a
 * purpose class at the same path and redeclares the lists; every reader asks that class, whichever class it names.
 */
class AIConversationPurposeListsOfTheApplicationTest extends UnitTestCase
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

    public function testWithoutAnApplicationClassTheModulesThreePurposesAreTheWholeRegistry(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversationPurpose::class] = AIConversationPurpose::class;

        self::assertSame(AIConversationPurpose::class, AIConversationPurpose::getPurposeClassOfTheApplication());
        self::assertSame(['Background.MechanicalOperation', 'Supervisor.Checkpoint', 'Schedule.Run'], AIConversationPurpose::getAllPurposeNames());
        self::assertSame(['Supervisor.Checkpoint', 'Background.MechanicalOperation'], AIConversationPurpose::getCustomerHiddenPurposeNames());
        self::assertSame([], AIConversationPurpose::getPurposeNamesClosedWithoutAnOwnerTurn());
        self::assertFalse(AIConversationPurpose::isRegisteredPurposeName('Intake.Chat'));
        self::assertFalse(AIConversationPurpose::isCustomerHiddenPurposeName('Nightly.Digest'));
        self::assertFalse(AIConversationPurpose::isPurposeNameHeldAsAPhoneCall('Intake.Call'));
        self::assertFalse(AIConversationPurpose::isPurposeNameHeldAsAPhoneCall(null));
    }

    public function testTheListsOfAnApplicationsPurposeClassAnswerThroughTheModulesClassName(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversationPurpose::class] = AIConversationPurposeOfAnApplicationWithAnIntake::class;

        self::assertSame(AIConversationPurposeOfAnApplicationWithAnIntake::class, AIConversationPurpose::getPurposeClassOfTheApplication());
        self::assertSame(
            ['Background.MechanicalOperation', 'Supervisor.Checkpoint', 'Schedule.Run', 'Intake.Chat', 'Intake.Call', 'Nightly.Digest'],
            AIConversationPurpose::getAllPurposeNames()
        );
        self::assertSame(AIConversationPurpose::getAllPurposeNames(), AIConversationPurposeOfAnApplicationWithAnIntake::getAllPurposeNames());
        self::assertTrue(AIConversationPurpose::isRegisteredPurposeName('Intake.Chat'));
        self::assertTrue(AIConversationPurpose::isRegisteredPurposeName('Schedule.Run'));
        self::assertFalse(AIConversationPurpose::isRegisteredPurposeName('Some.Purpose'));
        self::assertTrue(AIConversationPurpose::isCustomerHiddenPurposeName('Nightly.Digest'));
        self::assertTrue(AIConversationPurpose::isCustomerHiddenPurposeName('Supervisor.Checkpoint'));
        self::assertFalse(AIConversationPurpose::isCustomerHiddenPurposeName('Intake.Chat'));
        self::assertFalse(AIConversationPurpose::isCustomerHiddenPurposeName(null));
        self::assertSame(['Intake.Chat'], AIConversationPurpose::getPurposeNamesClosedWithoutAnOwnerTurn());
        self::assertTrue(AIConversationPurpose::isPurposeNameHeldAsAPhoneCall('Intake.Call'));
        self::assertFalse(AIConversationPurpose::isPurposeNameHeldAsAPhoneCall('Intake.Chat'));
    }

    public function testTheListsAreNotReadableAsConstantsOfTheModulesClass(): void
    {
        $listConstantNames = [
            'REGISTERED_PURPOSE_NAMES',
            'CUSTOMER_HIDDEN_PURPOSE_NAMES',
            'PURPOSE_NAMES_CLOSED_WITHOUT_AN_OWNER_TURN',
            'PURPOSE_NAMES_HELD_AS_A_PHONE_CALL',
        ];
        foreach ($listConstantNames as $listConstantName) {
            self::assertTrue((new ReflectionClassConstant(AIConversationPurpose::class, $listConstantName))->isProtected(), $listConstantName);
        }
    }

    public function testAConversationOfAPurposeHeldAsAPhoneCallHasTheOwnerOnThePhone(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversationPurpose::class] = AIConversationPurposeOfAnApplicationWithAnIntake::class;

        $originOfTheIntakeCall = $this->buildOrigin('Intake.Call', null);
        self::assertTrue($originOfTheIntakeCall->isHeldAsAPhoneCallByItsPurpose());
        self::assertFalse($originOfTheIntakeCall->readsTheLiveCallForOwnerPresence());
        self::assertSame(OwnerPresence::PRESENT_ON_THE_PHONE, $originOfTheIntakeCall->buildOwnerPresence(null, null));

        self::assertFalse($this->buildOrigin('Intake.Call', AIConversation::INITIATOR_TYPE_SCHEDULE)->isHeldAsAPhoneCallByItsPurpose());
        self::assertFalse($this->buildOrigin('Intake.Chat', null)->isHeldAsAPhoneCallByItsPurpose());
        self::assertTrue($this->buildOrigin('Intake.Chat', null)->readsTheLiveCallForOwnerPresence());
        self::assertFalse($this->buildOrigin(null, null)->isHeldAsAPhoneCallByItsPurpose());
    }

    public function testWithoutAnApplicationClassNoPurposeIsHeldAsAPhoneCall(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversationPurpose::class] = AIConversationPurpose::class;

        $originOfTheIntakeCall = $this->buildOrigin('Intake.Call', null);
        self::assertFalse($originOfTheIntakeCall->isHeldAsAPhoneCallByItsPurpose());
        self::assertTrue($originOfTheIntakeCall->readsTheLiveCallForOwnerPresence());
    }

    public function testASessionLineCarriesTheNoteTheCallerGivesForItsPurpose(): void
    {
        $readerTimezone = new \DateTimeZone(date_default_timezone_get());
        $aIConversations = new AIConversations();
        $intakeConversation = $this->buildConversationWithSummary(7, 'Intake.Chat', null, '2026-03-04 10:30:00', 'Intake', 'the form is filled');
        $scheduledConversation = $this->buildConversationWithSummary(8, null, AIConversation::INITIATOR_TYPE_SCHEDULE, '2026-03-03 09:00:00', 'Weekly report', 'sent');
        $aIConversations->add($intakeConversation);
        $aIConversations->add($scheduledConversation);
        $now = DateTime::fromString('2026-03-04 18:00:00');

        self::assertSame(
            "[2026-03-04 10:30, today] Intake — the form is filled (#7, started by the person · THE INTAKE — complete, never continued)\n"
            . '[2026-03-03 09:00] Weekly report — sent (#8, started by a scheduled task)',
            $aIConversations->buildSessionLinesText($readerTimezone, $now, ['Intake.Chat' => 'THE INTAKE — complete, never continued'])
        );
        self::assertSame(
            "[2026-03-04 10:30, today] Intake — the form is filled (#7, started by the person)\n"
            . '[2026-03-03 09:00] Weekly report — sent (#8, started by a scheduled task)',
            $aIConversations->buildSessionLinesText($readerTimezone, $now)
        );
    }

    protected function buildOrigin(?string $purposeName, ?string $initiatorType): AIConversationOrigin
    {
        $aIConversationOrigin = new AIConversationOrigin();
        $aIConversationOrigin->purposeName = $purposeName;
        $aIConversationOrigin->initiatorType = $initiatorType;
        return $aIConversationOrigin;
    }

    protected function buildConversationWithSummary(
        int $conversationId,
        ?string $purposeName,
        ?string $initiatorType,
        string $lastInboundMessageAt,
        string $title,
        string $outcome
    ): AIConversation {
        $aIConversation = new AIConversation();
        $aIConversation->id = $conversationId;
        $aIConversation->purposeName = $purposeName;
        $aIConversation->initiatorType = $initiatorType;
        $aIConversation->lastInboundMessageAt = DateTime::fromString($lastInboundMessageAt);
        $aIConversationSummary = new AIConversationSummary();
        $aIConversationSummary->title = $title;
        $aIConversationSummary->outcome = $outcome;
        $aIConversation->summary = $aIConversationSummary;
        return $aIConversation;
    }
}
