<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Accounting\Hours;
use App\Service\CredentialsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountingHoursTest extends TestCase
{
    public function testAnnualEntitlementAndHourlyRemainderHaveExactIntegerRepresentations(): void
    {
        self::assertSame(208, Hours::daysToHours(26));
        self::assertSame('68 h = 8 dni i 4 h', Hours::display(68));
        self::assertSame('0 h = 0 dni i 0 h', Hours::display(Hours::daysToHours(0)));
        self::assertSame('−4 h (niedobór: 0 dni i 4 h)', Hours::display(-4));
    }

    #[DataProvider('invalidHours')]
    public function testInputDoesNotCoerceJsonNumbersStringsOrBooleans(mixed $value): void
    {
        $this->expectException(CredentialsException::class);
        Hours::nonnegative($value, 'annualHours');
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidHours(): iterable
    {
        yield 'numeric string' => ['208'];
        yield 'fraction' => [0.5];
        yield 'float' => [208.0];
        yield 'boolean' => [true];
        yield 'negative' => [-1];
        yield 'oversized' => [1000001];
        yield 'missing' => [null];
    }
}
