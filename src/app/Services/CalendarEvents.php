<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Node;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Settings;
use Throwable;

class CalendarEvents
{
    /**
     * @return list<array{title: string, start: string, end: string|null, allDay: bool}>
     */
    public function upcoming(?string $url, ?DateTimeImmutable $now = null, ?int $limit = null): array
    {
        $url = filled($url) ? preg_replace('#^webcals?://#i', 'https://', trim($url)) : null;

        if (! $url || ! preg_match('#^https?://#i', $url)) {
            return [];
        }

        $now ??= new DateTimeImmutable;
        $limit ??= (int) config('services.calendar.limit', 5);

        try {
            $body = Cache::remember(
                'calendar.ical.'.sha1($url),
                (int) config('services.calendar.cache_seconds', 300),
                fn () => Http::timeout(10)->get($url)->throw()->body(),
            );

            return $this->parse($body, $now, $limit);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @return list<array{title: string, start: string, end: string|null, allDay: bool}>
     */
    public function parse(string $ical, DateTimeImmutable $now, int $limit): array
    {
        $calendar = Reader::read($ical, Reader::OPTION_FORGIVING);

        if (! $calendar instanceof VCalendar) {
            return [];
        }

        $horizon = $now->modify('+60 days');
        $events = [];

        foreach ($calendar->getBaseComponents('VEVENT') as $vevent) {
            if (! $vevent instanceof VEvent) {
                continue;
            }

            $dtstart = $vevent->select('DTSTART')[0] ?? null;

            if (! $dtstart instanceof DateTimeProperty || $vevent->select('RECURRENCE-ID') !== []) {
                continue;
            }

            $dtend = $vevent->select('DTEND')[0] ?? null;
            $allDay = ! $dtstart->hasTime();
            $title = trim((string) ($vevent->select('SUMMARY')[0] ?? '')) ?: '(No title)';
            $duration = $dtend instanceof DateTimeProperty
                ? $dtend->getDateTime()->getTimestamp() - $dtstart->getDateTime()->getTimestamp()
                : null;

            $starts = $vevent->select('RRULE') !== []
                ? $this->occurrences($calendar, $vevent, $now, $horizon)
                : [$dtstart->getDateTime()];

            foreach ($starts as $start) {
                $end = $duration === null ? null : $start->modify("+{$duration} seconds");
                $endsAt = $end ?? $start;

                if ($endsAt <= $now || $start > $horizon) {
                    continue;
                }

                $events[] = [
                    'title' => $title,
                    'start' => $start->format(DATE_ATOM),
                    'end' => $end?->format(DATE_ATOM),
                    'allDay' => $allDay,
                ];
            }
        }

        usort($events, fn ($a, $b) => strtotime($a['start']) <=> strtotime($b['start']));

        return array_slice($events, 0, $limit);
    }

    /**
     * @param  VCalendar<int, Node>  $calendar
     * @param  VEvent<int, Node>  $vevent
     * @return list<DateTimeImmutable>
     */
    private function occurrences(VCalendar $calendar, VEvent $vevent, DateTimeImmutable $now, DateTimeImmutable $horizon): array
    {
        // Fast-forwarding from an old DTSTART counts every skipped instance, so the default limit (3500) breaks long-running daily events.
        Settings::$maxRecurrences = 50000;

        try {
            $iterator = new EventIterator($calendar, (string) ($vevent->select('UID')[0] ?? ''));
            $iterator->fastForward($now->setTimezone(new DateTimeZone('UTC'))->modify('-1 day'));
            $starts = [];

            while ($iterator->valid() && count($starts) < 50) {
                $start = DateTimeImmutable::createFromInterface($iterator->getDtStart());

                if ($start > $horizon) {
                    break;
                }

                $starts[] = $start;
                $iterator->next();
            }
        } catch (MaxInstancesExceededException) {
            return [];
        }

        return $starts;
    }
}
