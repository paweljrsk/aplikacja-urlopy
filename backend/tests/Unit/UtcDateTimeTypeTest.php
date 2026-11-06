<?php

namespace App\Tests\Unit;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use PHPUnit\Framework\TestCase;

final class UtcDateTimeTypeTest extends TestCase
{
    public function testUtcStorageAndHydrationAreIndependentOfDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $type = new UtcDateTimeImmutableType();
            $platform = new MySQLPlatform();
            $input = new \DateTimeImmutable('2026-07-01 12:34:56.123456', new \DateTimeZone('Europe/Warsaw'));
            $stored = $type->convertToDatabaseValue($input, $platform);
            self::assertSame('2026-07-01 10:34:56', $stored);
            $restored = $type->convertToPHPValue($stored, $platform);
            self::assertInstanceOf(\DateTimeImmutable::class, $restored);
            self::assertSame($input->getTimestamp(), $restored->getTimestamp());
            self::assertSame('UTC', $restored->getTimezone()->getName());
            self::assertSame('000000', $restored->format('u'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testAnImpossibleDatabaseDateIsRejected(): void
    {
        $this->expectException(InvalidFormat::class);
        (new UtcDateTimeImmutableType())->convertToPHPValue('2026-02-30 12:00:00', new MySQLPlatform());
    }

    public function testNonDatetimeDatabaseValueIsRejected(): void
    {
        $this->expectException(InvalidType::class);
        (new UtcDateTimeImmutableType())->convertToPHPValue(123, new MySQLPlatform());
    }
}
