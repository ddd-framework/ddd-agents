<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One calendar event. Its title and description are written by others: untrusted content.
 */
class AgentConnectionCalendarEvent extends ValueObject
{
    public string $eventId = '';
    public string $calendarId = '';
    public string $title = '';
    /** @var DateTime|null The start; for an all-day event the day (its time is ignored) */
    public ?DateTime $startsAt = null;
    /** @var DateTime|null The end; for an all-day event the day after the last day (exclusive, as providers store it) */
    public ?DateTime $endsAt = null;
    public bool $isAllDay = false;
    public ?string $location = null;
    public ?string $description = null;
    /** @var string[] attendee addresses */
    public array $attendees = [];
    public ?string $organizer = null;
    public ?string $status = null;

    public function describeForApproval(): string
    {
        $lines = ['Event: ' . $this->title, 'Calendar: ' . $this->calendarId, 'From: ' . $this->describeTime($this->startsAt), 'To: ' . $this->describeTime($this->endsAt)];
        if ($this->location !== null && $this->location !== '') {
            $lines[] = 'Location: ' . $this->location;
        }
        if ($this->attendees !== []) {
            $lines[] = 'Attendees (receive an invitation): ' . implode(', ', $this->attendees);
        }
        if ($this->description !== null && $this->description !== '') {
            $lines[] = '';
            $lines[] = $this->description;
        }
        return implode("\n", $lines);
    }

    /** A date for an all-day event, else the time with its offset */
    public function describeTime(?DateTime $time): string
    {
        if ($time === null) {
            return '';
        }
        return $this->isAllDay ? $time->format('Y-m-d') : $time->format(DATE_ATOM);
    }
}
