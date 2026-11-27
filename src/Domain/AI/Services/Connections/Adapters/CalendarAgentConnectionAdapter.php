<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionBusyPeriods;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendarEvent;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendarEvents;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionCalendars;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;

/** Capability CALENDAR. Times are ISO 8601 with offset. */
interface CalendarAgentConnectionAdapter
{
    public function listCalendars(): AgentConnectionCalendars;

    /** @param string[] $calendarIds */
    public function getFreeBusy(array $calendarIds, DateTime $fromTime, DateTime $toTime): AgentConnectionBusyPeriods;

    public function listCalendarEvents(string $calendarId, DateTime $fromTime, DateTime $toTime, ?string $query, int $maximumResults): AgentConnectionCalendarEvents;

    /** An event with attendees sends invitations: an outward step */
    public function createCalendarEvent(AgentConnectionCalendarEvent $calendarEvent): AgentConnectionSendResult;
}
