<?php

namespace Tests\Unit;

use App\Services\CalendarEvents;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class CalendarEventsTest extends TestCase
{
    public function test_it_returns_upcoming_events_including_recurring_ones_in_order(): void
    {
        $ical = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//test//EN
BEGIN:VEVENT
UID:past
DTSTART:20261101T100000Z
DTEND:20261101T110000Z
SUMMARY:Past
END:VEVENT
BEGIN:VEVENT
UID:later
DTSTART:20261125T100000Z
DTEND:20261125T110000Z
SUMMARY:Later
END:VEVENT
BEGIN:VEVENT
UID:daily
DTSTART:20261101T080000Z
DTEND:20261101T083000Z
RRULE:FREQ=DAILY
SUMMARY:Standup
END:VEVENT
BEGIN:VEVENT
UID:allday
DTSTART;VALUE=DATE:20261124
DTEND;VALUE=DATE:20261125
SUMMARY:Holiday
END:VEVENT
END:VCALENDAR
ICS;

        $events = (new CalendarEvents)->parse($ical, new DateTimeImmutable('2026-11-22T12:00:00Z'), 4);

        $this->assertSame(['Standup', 'Holiday', 'Standup', 'Standup'], array_column($events, 'title'));
        $this->assertTrue($events[1]['allDay']);
        $this->assertStringStartsWith('2026-11-23T08:00:00', $events[0]['start']);
    }
}
