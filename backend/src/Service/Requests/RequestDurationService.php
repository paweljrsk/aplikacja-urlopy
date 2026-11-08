<?php

declare(strict_types=1);

namespace App\Service\Requests;

use App\Service\Calendar\WorkingCalendar;

/** Calendar-only read: no balances, assignments, request versions or writes. */
final class RequestDurationService
{
    public function __construct(private WorkingCalendar $calendar)
    {
    }

    /** @param array<string, mixed> $data
     * @return array{workingDays: int, totalHours: int, hoursPerDay: int, timezone: string}
     */
    public function calculate(array $data): array
    {
        $input = RequestInput::duration($data);
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $days = $this->calendar->days(
            new \DateTimeImmutable($input['startDate'], $timezone),
            new \DateTimeImmutable($input['endDate'], $timezone),
            $input['hours'] ?? null,
        );
        return ['workingDays' => count($days), 'totalHours' => (int) array_sum($days),
            'hoursPerDay' => WorkingCalendar::HOURS_PER_DAY, 'timezone' => $timezone->getName()];
    }
}
