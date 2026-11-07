<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Holiday;
use App\Service\Calendar\WorkingCalendar;
use App\Service\CredentialsException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class WorkingCalendarTest extends TestCase
{
    public function testOfficial2026MovableDatesAndChristmasEve(): void
    {
        $calendar = $this->calendar();
        $holidays = $calendar->statutoryHolidays(2026);
        self::assertCount(14, $holidays);
        foreach (['2026-04-05', '2026-04-06', '2026-05-24', '2026-06-04', '2026-12-24'] as $date) {
            self::assertArrayHasKey($date, $holidays);
            self::assertSame(0, $calendar->hoursOn($this->date($date)));
        }
    }

    public function testWeekendAndHolidayAreNotDeductedTwiceOrShiftedToMonday(): void
    {
        $calendar = $this->calendar();
        self::assertSame(0, $calendar->hoursOn($this->date('2026-08-15')));
        self::assertSame(0, $calendar->hoursOn($this->date('2026-08-16')));
        self::assertSame(8, $calendar->hoursOn($this->date('2026-08-17')));
    }

    public function testLeapDayAndYearBoundaryStayLocalDates(): void
    {
        $calendar = $this->calendar();
        self::assertSame(8, $calendar->hoursOn($this->date('2028-02-29')));
        self::assertSame(['2026-12-31' => 8, '2027-01-04' => 8], $calendar->days($this->date('2026-12-31'), $this->date('2027-01-04')));
    }

    public function testDaylightSavingDoesNotSkipOrDuplicateDays(): void
    {
        $calendar = $this->calendar();
        self::assertSame(['2026-03-27' => 8, '2026-03-30' => 8, '2026-03-31' => 8], $calendar->days($this->date('2026-03-27'), $this->date('2026-03-31')));
        self::assertSame(['2026-10-23' => 8, '2026-10-26' => 8], $calendar->days($this->date('2026-10-23'), $this->date('2026-10-26')));
    }

    public function testCompanyOverrideDoesNotRemoveStatutoryHoliday(): void
    {
        $calendar = $this->calendar([new Holiday($this->date('2026-12-24'), 'Company also closed')]);
        self::assertSame(0, $calendar->hoursOn($this->date('2026-12-24'), ['date' => '2026-12-24', 'active' => false]));
    }

    public function testCompanyHolidayCanBePreviewedWithoutPersisting(): void
    {
        $calendar = $this->calendar();
        self::assertSame(0, $calendar->hoursOn($this->date('2026-12-18'), ['date' => '2026-12-18', 'active' => true]));
        self::assertSame(8, $calendar->hoursOn($this->date('2026-12-18')));
    }

    public function testHourlyRequestAcceptsWholeHoursForOneWorkingDay(): void
    {
        self::assertSame(['2026-12-18' => 3], $this->calendar()->days($this->date('2026-12-18'), $this->date('2026-12-18'), 3));
        $this->expectException(CredentialsException::class);
        $this->calendar()->days($this->date('2026-12-18'), $this->date('2026-12-21'), 3);
    }

    public function testHourlyRequestRejectsZeroAndFullDayAmount(): void
    {
        foreach ([0, -1, 8, 9] as $hours) {
            try {
                $this->calendar()->days($this->date('2026-12-18'), $this->date('2026-12-18'), $hours);
                self::fail('Hourly amounts must be between one and seven.');
            } catch (CredentialsException $exception) {
                self::assertSame('VALIDATION_FAILED', $exception->errorCode);
            }
        }
    }

    public function testUnsupportedHistoricalDatesAreExplicit(): void
    {
        $this->expectException(CredentialsException::class);
        $this->calendar()->statutoryHolidays(2024);
    }

    /** @param list<Holiday> $holidays */
    private function calendar(array $holidays = []): WorkingCalendar
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn($holidays);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        return new WorkingCalendar($em);
    }

    private function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw'));
    }
}
