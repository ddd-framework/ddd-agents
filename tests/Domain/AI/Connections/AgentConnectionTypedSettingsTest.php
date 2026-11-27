<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendarEvent;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailDraft;
use DDD\Domain\AI\Entities\Connections\AgentConnectionCapabilityCeilings;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundaries;
use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundary;
use DDD\Domain\AI\Entities\Connections\AgentConnectionStepArguments;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The typed settings and step arguments survive their stored JSON form unchanged */
class AgentConnectionTypedSettingsTest extends UnitTestCase
{
    public function testBoundariesRoundTripAndNormalize(): void
    {
        $scopeBoundaries = AgentConnectionScopeBoundaries::ofBoundaries(
            AgentConnectionScopeBoundary::of('label', ' Team '),
            AgentConnectionScopeBoundary::of('LABEL', 'Team'),
            AgentConnectionScopeBoundary::of('UNKNOWN', 'x'),
            AgentConnectionScopeBoundary::of('FOLDER', '')
        )->normalized();
        self::assertSame(['Team'], $scopeBoundaries->getValuesOfKind(AgentConnectionScopeBoundary::KIND_LABEL));
        self::assertCount(1, $scopeBoundaries->getElements(), 'unknown kinds, empty values and repeats are dropped');

        $storedJson = (string)json_encode($scopeBoundaries->toObject(false));
        // the form the DDL conversion of the former text column writes (no object types): it must hydrate as well
        $convertedBoundaries = AgentConnectionScopeBoundaries::newInstance();
        $convertedBoundaries->setPropertiesFromObject(json_decode('{"elements":[{"kind":"FOLDER","value":"f9"}]}'), false);
        self::assertSame(['f9'], $convertedBoundaries->getValuesOfKind(AgentConnectionScopeBoundary::KIND_FOLDER));
        $restoredBoundaries = AgentConnectionScopeBoundaries::newInstance();
        $restoredBoundaries->setPropertiesFromObject(json_decode($storedJson), false);
        self::assertSame(['Team'], $restoredBoundaries->getValuesOfKind(AgentConnectionScopeBoundary::KIND_LABEL));

        $withFolder = $restoredBoundaries->withBoundary(AgentConnectionScopeBoundary::KIND_FOLDER, 'f1', true);
        self::assertSame(['f1'], $withFolder->getValuesOfKind(AgentConnectionScopeBoundary::KIND_FOLDER));
        self::assertSame([], $withFolder->withBoundary(AgentConnectionScopeBoundary::KIND_FOLDER, 'F1', false)->getValuesOfKind(AgentConnectionScopeBoundary::KIND_FOLDER));
    }

    public function testCeilingsDropUnknownAndOffAndRoundTrip(): void
    {
        $capabilityCeilings = AgentConnectionCapabilityCeilings::fromLevelsByCapability([
            AgentConnectionPermission::CAPABILITY_MAIL => AgentConnectionPermission::LEVEL_DRAFT,
            AgentConnectionPermission::CAPABILITY_FILES => AgentConnectionPermission::LEVEL_OFF,
            'NOT_A_CAPABILITY' => AgentConnectionPermission::LEVEL_READ,
        ]);
        self::assertCount(1, $capabilityCeilings->getElements());
        $restoredCeilings = AgentConnectionCapabilityCeilings::newInstance();
        $restoredCeilings->setPropertiesFromObject(json_decode((string)json_encode($capabilityCeilings->toObject(false))), false);
        self::assertSame(AgentConnectionPermission::LEVEL_DRAFT, $restoredCeilings->getLevelOf(AgentConnectionPermission::CAPABILITY_MAIL));
        self::assertSame(AgentConnectionPermission::LEVEL_OFF, $restoredCeilings->getLevelOf(AgentConnectionPermission::CAPABILITY_FILES));
    }

    public function testStepArgumentsRoundTripKeepTheHashBaseAndTheRecipients(): void
    {
        $mailDraft = AgentConnectionMailDraft::newInstance();
        $mailDraft->to = ['Anna@Example.org'];
        $mailDraft->subject = 'Hallo';
        $mailDraft->body = 'Text';
        $calendarEvent = AgentConnectionCalendarEvent::newInstance();
        $calendarEvent->title = 'Termin';
        $calendarEvent->startsAt = DateTime::fromString('2026-12-02T09:00:00+01:00');
        $calendarEvent->endsAt = DateTime::fromString('2026-12-02T10:00:00+01:00');
        $calendarEvent->attendees = ['bob@example.org'];
        $stepArguments = AgentConnectionStepArguments::newInstance();
        $stepArguments->mailDraft = $mailDraft;
        $stepArguments->calendarEvent = $calendarEvent;

        $restoredArguments = AgentConnectionStepArguments::fromStoredJson($stepArguments->toStoredJson());
        self::assertSame($stepArguments->toStoredJson(), $restoredArguments->toStoredJson(), 'the approved content hash is taken over this form: it must not drift');
        self::assertSame(['anna@example.org', 'bob@example.org'], $restoredArguments->getRecipients());
        self::assertSame($calendarEvent->startsAt->getTimestamp(), $restoredArguments->calendarEvent?->startsAt?->getTimestamp());
    }

    public function testADriveStepCarriesItsTypedFields(): void
    {
        $stepArguments = AgentConnectionStepArguments::forDriveStep('UPDATE_SHEET', range: 'A1:B2', rowsJson: '[["1","2"]]', readFingerprint: 'abc');
        $stepArguments->itemId = 'sheet-1';
        $restoredArguments = AgentConnectionStepArguments::fromStoredJson($stepArguments->toStoredJson());
        self::assertSame('UPDATE_SHEET', $restoredArguments->stepKind);
        self::assertSame('sheet-1', $restoredArguments->itemId);
        self::assertSame('abc', $restoredArguments->readFingerprint);
        self::assertSame([], $restoredArguments->getRecipients());
    }
}
