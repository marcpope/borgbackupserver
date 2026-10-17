<?php

namespace BBS\Services;

/**
 * When a restore check plan is next due.
 *
 * Stored like backup schedules (comma-separated "HH:MM" times, weekly days
 * 0=Sunday, monthly days 1-28 or "last") but computed here rather than with
 * SchedulerService::calculateNextRun(), which reads the wall clock and so
 * cannot be tested across daylight-saving changes. Backup scheduling is not
 * touched.
 *
 * Daylight saving, in the plan's own timezone:
 *  - a time that does not exist that day (clocks spring forward over it) is
 *    skipped, not moved to the next valid minute;
 *  - a time that happens twice (clocks fall back) runs once, at the first.
 */
class RestoreCheckSchedule
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /** Days searched ahead before giving up; a monthly "28" is never further. */
    private const HORIZON_DAYS = 400;

    /**
     * Times as sorted, de-duplicated "HH:MM". Accepts the stored comma list
     * or an array. Throws on anything that is not a time of day.
     *
     * @return string[]
     */
    public static function normalizeTimes(string|array $value): array
    {
        $parts = is_array($value) ? $value : explode(',', $value);
        $times = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $part, $m)) {
                throw new \InvalidArgumentException("'{$part}' is not a time of day (HH:MM).");
            }
            $times[sprintf('%02d:%02d', (int) $m[1], (int) $m[2])] = true;
        }
        if (!$times) {
            throw new \InvalidArgumentException('At least one time of day is required.');
        }
        $times = array_keys($times);
        sort($times);
        return $times;
    }

    /** Monthly days as sorted ints 1-28, or ['last']. Throws on anything else. */
    public static function normalizeDaysOfMonth(string|array $value): array
    {
        $parts = is_array($value) ? $value : explode(',', $value);
        $parts = array_values(array_filter(array_map(fn($p) => strtolower(trim((string) $p)), $parts), fn($p) => $p !== ''));
        if ($parts === ['last']) {
            return ['last'];
        }
        $days = [];
        foreach ($parts as $p) {
            if (!ctype_digit($p) || (int) $p < 1 || (int) $p > 28) {
                throw new \InvalidArgumentException('Days of the month must be 1-28, or "last".');
            }
            $days[(int) $p] = (int) $p;
        }
        if (!$days) {
            throw new \InvalidArgumentException('At least one day of the month is required.');
        }
        ksort($days);
        return array_values($days);
    }

    public static function isValidTimezone(string $tz): bool
    {
        return in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * The first occurrence strictly after $after, in UTC; null for a plan
     * that can never fire (unknown frequency, no days).
     *
     * @param array $plan frequency, times, day_of_week, day_of_month, timezone
     */
    public static function nextOccurrence(array $plan, \DateTimeImmutable $after): ?\DateTimeImmutable
    {
        $frequency = $plan['frequency'] ?? '';
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            return null;
        }
        $tz = new \DateTimeZone($plan['timezone'] ?? 'UTC');
        $utc = new \DateTimeZone('UTC');
        $times = self::normalizeTimes($plan['times'] ?? '');

        $weekDays = $frequency === 'weekly'
            ? (SchedulerService::parseDaysOfWeek($plan['day_of_week'] ?? '') ?: [1])
            : [];
        $monthDays = $frequency === 'monthly'
            ? self::normalizeDaysOfMonth((string) ($plan['day_of_month'] ?? '1'))
            : [];

        // Start a day early so a time just before local midnight on the
        // previous local date is not missed when $after is in another zone.
        $day = $after->setTimezone($tz)->setTime(0, 0)->modify('-1 day');
        for ($i = 0; $i <= self::HORIZON_DAYS; $i++, $day = $day->modify('+1 day')) {
            if (!self::dayMatches($day, $frequency, $weekDays, $monthDays)) {
                continue;
            }
            foreach ($times as $time) {
                $wall = $day->format('Y-m-d') . ' ' . $time;
                $candidate = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $wall, $tz);
                if ($candidate === false || $candidate->format('Y-m-d H:i') !== $wall) {
                    // PHP moved a non-existent local time forward; skip it.
                    continue;
                }
                // A repeated local time (clocks fell back) resolves to its
                // second instance in PHP; step back to the first, so the
                // check runs once and at the earlier of the two.
                foreach ([3600, 1800] as $shift) {
                    $earlier = $candidate->setTimestamp($candidate->getTimestamp() - $shift);
                    if ($earlier->format('Y-m-d H:i') === $wall) {
                        $candidate = $earlier;
                        break;
                    }
                }
                if ($candidate > $after) {
                    return $candidate->setTimezone($utc);
                }
            }
        }
        return null;
    }

    /**
     * Occurrences in ($from, $to], capped. Used to say how many runs a
     * stopped scheduler missed, without running each of them.
     */
    public static function countOccurrences(array $plan, \DateTimeImmutable $from, \DateTimeImmutable $to, int $cap = 1000): int
    {
        $n = 0;
        $cursor = $from;
        while ($n < $cap) {
            $next = self::nextOccurrence($plan, $cursor);
            if ($next === null || $next > $to) {
                break;
            }
            $n++;
            $cursor = $next;
        }
        return $n;
    }

    private static function dayMatches(\DateTimeImmutable $day, string $frequency, array $weekDays, array $monthDays): bool
    {
        return match ($frequency) {
            'daily' => true,
            'weekly' => in_array((int) $day->format('w'), $weekDays, true),
            'monthly' => $monthDays === ['last']
                ? $day->format('j') === $day->format('t')
                : in_array((int) $day->format('j'), $monthDays, true),
            default => false,
        };
    }

    /** "Weekly on Mon at 03:00 (Europe/Berlin)" for lists and reports. */
    public static function describe(array $plan): string
    {
        try {
            $times = implode(', ', self::normalizeTimes($plan['times'] ?? ''));
        } catch (\InvalidArgumentException) {
            $times = '?';
        }
        $tz = $plan['timezone'] ?? 'UTC';
        return match ($plan['frequency'] ?? '') {
            'daily' => "Daily at {$times} ({$tz})",
            'weekly' => 'Weekly on ' . SchedulerService::daysOfWeekLabel($plan['day_of_week'] ?? '1') . " at {$times} ({$tz})",
            'monthly' => 'Monthly on day ' . ($plan['day_of_month'] ?? '1') . " at {$times} ({$tz})",
            default => 'Not scheduled',
        };
    }
}
