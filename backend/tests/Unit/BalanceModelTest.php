<?php

namespace App\Tests\Unit;

use App\Entity\AbsenceType;
use App\Entity\AuditEvent;
use App\Entity\EmploymentType;
use App\Entity\HourPool;
use App\Entity\LeaveAccount;
use App\Entity\LeaveRequest;
use App\Entity\LedgerEntry;
use App\Entity\OpeningBalance;
use App\Entity\OpeningCarry;
use App\Entity\RequestDay;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\LedgerEntryKind;
use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Enum\TeamRole;
use App\Model\RequestedDay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BalanceModelTest extends TestCase
{
    public function testOpeningSeparatesCurrentPoolUsageFromRemainingOlderPools(): void
    {
        $user = $this->user();
        $opening = new OpeningBalance($user, new \DateTimeImmutable('2026-11-06'), 208, 37, $this->event($user));
        $oldest = new OpeningCarry($opening, 2024, 5);
        $previous = new OpeningCarry($opening, 2025, 11);

        self::assertSame('2026-11-06', $opening->getEffectiveDate()->format('Y-m-d'));
        self::assertSame('Europe/Warsaw', $opening->getEffectiveDate()->getTimezone()->getName());
        self::assertSame(208, $opening->getAnnualHours());
        self::assertSame(37, $opening->getCurrentYearUsedHours());
        self::assertSame(5, $oldest->getRemainingHours());
        self::assertSame(11, $previous->getRemainingHours());
        self::assertSame([$oldest, $previous], $opening->getCarries());
        self::assertSame($user, $oldest->getUser());
    }

    public function testOpeningSnapshotDoesNotChooseAnUnresolvedDeficitPolicy(): void
    {
        $user = $this->user();
        $opening = new OpeningBalance($user, new \DateTimeImmutable('2026-11-06'), 80, 88, $this->event($user));

        self::assertSame(80, $opening->getAnnualHours());
        self::assertSame(88, $opening->getCurrentYearUsedHours());
    }

    public function testOpeningRejectsRepeatedOriginYearBeforeDatabaseWrite(): void
    {
        $user = $this->user();
        $opening = new OpeningBalance($user, new \DateTimeImmutable('2026-11-06'), 208, 37, $this->event($user));
        new OpeningCarry($opening, 2025, 11);

        $this->expectException(\InvalidArgumentException::class);
        new OpeningCarry($opening, 2025, 11);
    }

    public function testOpeningCarryCannotRepresentTheCurrentAnnualPool(): void
    {
        $user = $this->user();
        $opening = new OpeningBalance($user, new \DateTimeImmutable('2026-11-06'), 208, 37, $this->event($user));

        $this->expectException(\InvalidArgumentException::class);
        new OpeningCarry($opening, 2026, 11);
    }

    public function testOpeningRejectsAuditSourceForAnotherEmployee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpeningBalance($this->user(), new \DateTimeImmutable('2026-11-06'), 208, 37, $this->event($this->user('other@example.test')));
    }

    public function testOpeningRejectsTimeOfDayInsteadOfSilentlyChangingTheCutoff(): void
    {
        $user = $this->user();

        $this->expectException(\InvalidArgumentException::class);
        new OpeningBalance($user, new \DateTimeImmutable('2026-11-06 12:30:00'), 208, 37, $this->event($user));
    }

    public function testImmutableGrantMarksBothConcurrencyBoundaries(): void
    {
        $user = $this->user();
        $account = new LeaveAccount($user, 2026);
        $pool = new HourPool($user, 2025);
        $event = $this->event($user);
        $entry = new LedgerEntry($account, $pool, $event, 1, LedgerEntryKind::GRANT, 16, 0, 0);

        self::assertSame($account, $entry->getAccount());
        self::assertSame($pool, $entry->getPool());
        self::assertSame($event, $entry->getEvent());
        self::assertSame(16, $entry->getAllocatedDelta());
        self::assertSame(0, $entry->getReservedDelta());
        self::assertSame(0, $entry->getChargedDelta());
        self::assertSame(1, $account->getMovementRevision());
        self::assertSame(1, $pool->getMovementRevision());
        self::assertSame(2026, $account->getYear());
        self::assertSame(2025, $pool->getOriginYear());
    }

    public function testInvalidMovementLeavesConcurrencyBoundariesUntouched(): void
    {
        $user = $this->user();
        $account = new LeaveAccount($user, 2026);
        $pool = new HourPool($user, 2026);

        try {
            new LedgerEntry($account, $pool, $this->event($user), 1, LedgerEntryKind::GRANT, -16, 0, 0);
            self::fail('A negative grant must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $account->getMovementRevision());
            self::assertSame(0, $pool->getMovementRevision());
        }
    }

    public function testLedgerCannotSpendAnotherEmployeesPool(): void
    {
        $user = $this->user();
        $account = new LeaveAccount($user, 2026);
        $pool = new HourPool($this->user('other@example.test'), 2026);

        $this->expectException(\InvalidArgumentException::class);
        new LedgerEntry($account, $pool, $this->event($user), 1, LedgerEntryKind::GRANT, 80, 0, 0);
    }

    public function testLedgerRejectsAuditSourceForAnotherEmployee(): void
    {
        $user = $this->user();
        $account = new LeaveAccount($user, 2026);
        $pool = new HourPool($user, 2026);

        $this->expectException(\InvalidArgumentException::class);
        new LedgerEntry($account, $pool, $this->event($this->user('other@example.test')), 1, LedgerEntryKind::GRANT, 80, 0, 0);
    }

    public function testFutureOriginCannotFundAnEarlierLeaveYear(): void
    {
        $user = $this->user();

        $this->expectException(\InvalidArgumentException::class);
        new LedgerEntry(new LeaveAccount($user, 2026), new HourPool($user, 2027), $this->event($user), 1, LedgerEntryKind::GRANT, 80, 0, 0);
    }

    public function testHistoricalUsageIsRecordedWithoutFabricatingRequestDates(): void
    {
        $user = $this->user();
        $entry = new LedgerEntry(new LeaveAccount($user, 2026), new HourPool($user, 2026), $this->event($user), 1, LedgerEntryKind::OPENING_USED, 0, 0, 37);

        self::assertNull($entry->getDay());
        self::assertSame(37, $entry->getChargedDelta());
        self::assertSame(0, $entry->getAllocatedDelta());
    }

    public function testOpeningUsageCannotDebitTheAlreadyRemainingCarryAgain(): void
    {
        $user = $this->user();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('current annual pool');
        new LedgerEntry(new LeaveAccount($user, 2026), new HourPool($user, 2025), $this->event($user), 1, LedgerEntryKind::OPENING_USED, 0, 0, 37);
    }

    #[DataProvider('dayMovementKinds')]
    public function testRequestMovementsCannotLoseTheirDayReference(LedgerEntryKind $kind, int $reservedDelta, int $chargedDelta): void
    {
        $user = $this->user();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('specific request day');
        new LedgerEntry(new LeaveAccount($user, 2026), new HourPool($user, 2026), $this->event($user), 1, $kind, 0, $reservedDelta, $chargedDelta);
    }

    /** @return iterable<string, array{LedgerEntryKind, int, int}> */
    public static function dayMovementKinds(): iterable
    {
        yield 'reservation' => [LedgerEntryKind::RESERVE, 3, 0];
        yield 'release' => [LedgerEntryKind::RELEASE, -3, 0];
        yield 'approval' => [LedgerEntryKind::APPROVE, -3, 3];
        yield 'cancellation' => [LedgerEntryKind::CANCEL, 0, -3];
    }

    public function testApprovalCannotChargeASecondCopyOfTheReservation(): void
    {
        $user = $this->user();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('movement kind');
        new LedgerEntry(new LeaveAccount($user, 2026), new HourPool($user, 2026), $this->event($user), 1, LedgerEntryKind::APPROVE, 0, -3, 6);
    }

    public function testAZeroCorrectionCannotProduceMeaninglessHistory(): void
    {
        $user = $this->user();

        $this->expectException(\InvalidArgumentException::class);
        new LedgerEntry(new LeaveAccount($user, 2026), new HourPool($user, 2026), $this->event($user), 1, LedgerEntryKind::ADJUSTMENT, 0, 0, 0);
    }

    public function testApprovalMovesTheSameHoursFromReservationToCharge(): void
    {
        $user = $this->user();
        $account = new LeaveAccount($user, 2026);
        $pool = new HourPool($user, 2025);
        $day = $this->day($user);
        $reservation = new LedgerEntry($account, $pool, $this->event($user, 'unit-reserve', 'request.submit'), 1, LedgerEntryKind::RESERVE, 0, 3, 0, $day);
        $approval = new LedgerEntry($account, $pool, $this->event($user, 'unit-approve', 'request.approve'), 1, LedgerEntryKind::APPROVE, 0, -3, 3, $day);

        self::assertSame($day, $approval->getDay());
        self::assertSame(0, $approval->getAllocatedDelta());
        self::assertSame(0, $reservation->getReservedDelta() + $approval->getReservedDelta());
        self::assertSame(3, $reservation->getChargedDelta() + $approval->getChargedDelta());
        self::assertSame(2, $account->getMovementRevision());
        self::assertSame(2, $pool->getMovementRevision());
    }

    public function testRequestDayOwnerCannotDifferFromPoolOwner(): void
    {
        $user = $this->user();
        $day = $this->day($this->user('other@example.test'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ledger employee');
        new LedgerEntry(new LeaveAccount($user, 2026), new HourPool($user, 2026), $this->event($user), 1, LedgerEntryKind::RESERVE, 0, 3, 0, $day);
    }

    public function testRequestDayUsesItsAbsenceYearRatherThanItsPoolOriginYear(): void
    {
        $user = $this->user();
        $day = $this->day($user, '2027-01-04');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same year');
        new LedgerEntry(new LeaveAccount($user, 2026), new HourPool($user, 2025), $this->event($user), 1, LedgerEntryKind::RESERVE, 0, 3, 0, $day);
    }

    public function testMovementCannotExceedItsRecordedDailyHours(): void
    {
        $user = $this->user();
        $day = $this->day($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed');
        new LedgerEntry(new LeaveAccount($user, 2026), new HourPool($user, 2026), $this->event($user), 1, LedgerEntryKind::RESERVE, 0, 4, 0, $day);
    }

    public function testNonDeductingAbsenceCannotChargeThePaidLeavePool(): void
    {
        $user = $this->user();
        $day = $this->day($user, '2026-11-09', false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-deducting');
        new LedgerEntry(new LeaveAccount($user, 2026), new HourPool($user, 2026), $this->event($user), 1, LedgerEntryKind::RESERVE, 0, 3, 0, $day);
    }

    public function testOpeningUsageCannotInventASpecificAbsenceDate(): void
    {
        $user = $this->user();
        $day = $this->day($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('aggregate');
        new LedgerEntry(new LeaveAccount($user, 2026), new HourPool($user, 2026), $this->event($user), 1, LedgerEntryKind::OPENING_USED, 0, 0, 3, $day);
    }

    private function day(User $user, string $date = '2026-11-09', bool $deductsBalance = true): RequestDay
    {
        $dateValue = new \DateTimeImmutable($date);
        $employment = new EmploymentType('uop', 'Employment contract', true);
        $assignment = new StaffAssignment($user, new Team('test_team', 'Test team'), $employment, TeamRole::EMPLOYEE, new \DateTimeImmutable('2026-01-01'));
        $policy = (new AbsenceType('test_leave'))->createVersion('Test leave', $deductsBalance, [$employment]);
        $request = new LeaveRequest($user, new \DateTimeImmutable('2026-11-06T08:00:00Z'));
        $revision = new RequestRevision($request, RequestChangeKind::INITIAL, $policy, RequestMode::HOURLY, $dateValue, $dateValue, [new RequestedDay($assignment, $dateValue, 3)], $this->event($user));

        return $revision->getDays()[0];
    }

    private function user(string $email = 'employee@example.test'): User
    {
        return new User('Test', 'Employee', $email);
    }

    private function event(User $user, string $operationKey = 'unit-opening', string $action = 'opening.create'): AuditEvent
    {
        return new AuditEvent($operationKey, $action, null, $user, new \DateTimeImmutable('2026-11-06T08:00:00Z'));
    }
}
