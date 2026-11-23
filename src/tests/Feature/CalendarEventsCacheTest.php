<?php

namespace Tests\Feature;

use App\Services\CalendarEvents;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarEventsCacheTest extends TestCase
{
    private function ics(string $title, string $start = '20261125T100000Z', ?string $name = null): string
    {
        $calendarName = $name === null ? '' : "X-WR-CALNAME:{$name}\r\n";

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n{$calendarName}BEGIN:VEVENT\r\nUID:{$title}\r\n"
            ."DTSTART:{$start}\r\nDTEND:".(new DateTimeImmutable(str_replace('Z', '', $start)))->modify('+1 hour')->format('Ymd\THis\Z')."\r\n"
            ."SUMMARY:{$title}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    public function test_each_calendar_url_is_cached_separately(): void
    {
        Cache::flush();
        Http::fake([
            'https://a.example.com/*' => Http::response($this->ics('Alpha')),
            'https://b.example.com/*' => Http::response($this->ics('Beta')),
        ]);

        $now = new DateTimeImmutable('2026-11-22T12:00:00Z');
        $service = new CalendarEvents;

        $this->assertSame(['Alpha'], array_column($service->upcoming('https://a.example.com/x.ics', $now), 'title'));
        $this->assertSame(['Beta'], array_column($service->upcoming('https://b.example.com/x.ics', $now), 'title'));
        $this->assertSame(['Alpha'], array_column($service->upcoming('https://a.example.com/x.ics', $now), 'title'));

        Http::assertSentCount(2);
    }

    public function test_it_ignores_missing_or_non_http_urls(): void
    {
        Http::fake();
        $service = new CalendarEvents;

        $this->assertSame([], $service->upcoming(null));
        $this->assertSame([], $service->upcoming(''));
        $this->assertSame([], $service->upcoming('file:///etc/passwd'));
        Http::assertNothingSent();
    }

    public function test_overview_reports_calendar_name_and_configured_state(): void
    {
        Cache::flush();
        Http::fake(['https://d.example.com/*' => Http::response(str_replace("VERSION:2.0\r\n", "VERSION:2.0\r\nX-WR-CALNAME:Family\r\n", $this->ics('Delta')))]);
        $service = new CalendarEvents;

        $overview = $service->overview('https://d.example.com/x.ics', new DateTimeImmutable('2026-11-22T12:00:00Z'));

        $this->assertTrue($overview['configured']);
        $this->assertSame('Family', $overview['name']);
        $this->assertSame(['Delta'], array_column($overview['events'], 'title'));
        $this->assertFalse($service->overview(null)['configured']);
    }

    public function test_webcal_urls_are_fetched_over_https(): void
    {
        Cache::flush();
        Http::fake(['https://c.example.com/*' => Http::response($this->ics('Gamma'))]);

        $events = (new CalendarEvents)->upcoming('webcal://c.example.com/x.ics', new DateTimeImmutable('2026-11-22T12:00:00Z'));

        $this->assertSame(['Gamma'], array_column($events, 'title'));
    }

    public function test_it_merges_multiple_calendars_and_keeps_each_calendar_name(): void
    {
        Cache::flush();
        Http::fake([
            'https://a.example.com/*' => Http::response($this->ics('Alpha later', '20261125T120000Z', 'Work')),
            'https://b.example.com/*' => Http::response($this->ics('Beta first', '20261125T090000Z', 'Family')),
        ]);

        $overview = (new CalendarEvents)->overview(
            ['https://a.example.com/x.ics', 'https://b.example.com/x.ics'],
            new DateTimeImmutable('2026-11-22T12:00:00Z'),
        );

        $this->assertTrue($overview['configured']);
        $this->assertNull($overview['name']);
        $this->assertSame(['Beta first', 'Alpha later'], array_column($overview['events'], 'title'));
        $this->assertSame(['Family', 'Work'], array_column($overview['events'], 'calendarName'));
        Http::assertSentCount(2);
    }

    public function test_dashboard_route_loads_multiple_calendar_parameters(): void
    {
        Cache::flush();
        Http::fake([
            'https://a.example.com/*' => Http::response($this->ics('Alpha', '20261125T100000Z', 'Work')),
            'https://b.example.com/*' => Http::response($this->ics('Beta', '20261125T090000Z', 'Family')),
        ]);

        $this->get(
            '/dashboard?calendar%5B%5D='.rawurlencode('https://a.example.com/x.ics')
            .'&calendar%5B%5D='.rawurlencode('https://b.example.com/x.ics'),
        )->assertInertia(fn (Assert $page) => $page
            ->component('weather-dashboard')
            ->has('events', 2)
            ->where('events.0.title', 'Beta')
            ->where('events.0.calendarName', 'Family')
            ->where('events.1.title', 'Alpha')
            ->where('events.1.calendarName', 'Work'));

        Http::assertSentCount(2);
    }
}
