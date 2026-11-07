<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\Holiday;
use App\Model\DateValue;
use App\Service\CredentialsException;
use Doctrine\ORM\EntityManagerInterface;

/** Local calendar rules; no internet dependency and no automatic substitute weekdays. */
final class WorkingCalendar
{
    public const MINIMUM_YEAR = 2025;
    public const MAXIMUM_YEAR = 2100;
    public const HOURS_PER_DAY = 8;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public static function assertYear(int $year): void
    {
        if ($year < self::MINIMUM_YEAR || $year > self::MAXIMUM_YEAR) {
            throw new CredentialsException('CALENDAR_YEAR_UNSUPPORTED', 'Kalendarz obsługuje lata 2025–2100. Starsze lata pochodzenia zaległych godzin są dozwolone.');
        }
    }

    /** @return array<string, string> Dates and statutory names under Dz.U. 2025 poz. 296. */
    public function statutoryHolidays(int $year): array
    {
        self::assertYear($year);
        $fixed = ['01-01' => 'Nowy Rok', '01-06' => 'Święto Trzech Króli', '05-01' => 'Święto Państwowe', '05-03' => 'Święto Narodowe Trzeciego Maja', '08-15' => 'Wniebowzięcie Najświętszej Maryi Panny', '11-01' => 'Wszystkich Świętych', '11-11' => 'Narodowe Święto Niepodległości', '12-24' => 'Wigilia Bożego Narodzenia', '12-25' => 'Pierwszy dzień Bożego Narodzenia', '12-26' => 'Drugi dzień Bożego Narodzenia'];
        $days = [];
        foreach ($fixed as $monthDay => $name) {
            $days[$year.'-'.$monthDay] = $name;
        }
        // Gregorian Meeus/Jones/Butcher algorithm; integer arithmetic, no timestamps.
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = ($h + $l - 7 * $m + 114) % 31 + 1;
        $easter = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new \DateTimeZone('Europe/Warsaw'));
        foreach ([0 => 'Pierwszy dzień Wielkiej Nocy', 1 => 'Drugi dzień Wielkiej Nocy', 49 => 'Pierwszy dzień Zielonych Świątek', 60 => 'Boże Ciało'] as $offset => $name) {
            $days[$easter->modify('+'.$offset.' days')->format('Y-m-d')] = $name;
        }
        ksort($days);
        return $days;
    }

    /** @param null|array{date: string, active: bool} $override A proposed company-calendar change, never a statutory override. */
    public function hoursOn(\DateTimeImmutable $date, ?array $override = null): int
    {
        $date = DateValue::local($date);
        return $this->days($date, $date, null, $override)[$date->format('Y-m-d')] ?? 0;
    }

    /**
     * @param null|array{date: string, active: bool} $override
     * @return array<string, int> Working dates only, each with a whole number of hours.
     */
    public function days(\DateTimeImmutable $start, \DateTimeImmutable $end, ?int $hourlyHours = null, ?array $override = null): array
    {
        $start = DateValue::local($start);
        $end = DateValue::local($end);
        self::assertYear((int) $start->format('Y'));
        self::assertYear((int) $end->format('Y'));
        if ($start > $end || (null !== $hourlyHours && ($start != $end || $hourlyHours < 1 || $hourlyHours > 7))) {
            throw new CredentialsException('VALIDATION_FAILED', 'Podaj uporządkowany zakres pełnych dni albo jeden dzień na 1–7 pełnych godzin.');
        }
        $excluded = [];
        for ($year = (int) $start->format('Y'); $year <= (int) $end->format('Y'); ++$year) {
            $excluded += $this->statutoryHolidays($year);
        }
        foreach ($this->em->getRepository(Holiday::class)->findBy(['active' => true]) as $holiday) {
            $key = $holiday->getDate()->format('Y-m-d');
            if (null !== $override && $override['date'] === $key && !$override['active']) {
                continue;
            }
            $excluded[$key] = $holiday->getName();
        }
        if (null !== $override && $override['active']) {
            $excluded[$override['date']] = 'Firmowy dzień wolny';
        }
        $result = [];
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            if ((int) $date->format('N') <= 5 && !isset($excluded[$key])) {
                $result[$key] = $hourlyHours ?? self::HOURS_PER_DAY;
            }
        }
        return $result;
    }
}
