<?php

namespace App\Model;

final class DateValue
{
    public static function local(\DateTimeImmutable $value): \DateTimeImmutable
    {
        if ('00:00:00.000000' !== $value->format('H:i:s.u')) {
            throw new \InvalidArgumentException('A calendar date must not contain a time of day.');
        }
        $year = (int) $value->format('Y');
        if ($year < 1000 || $year > 9999) {
            throw new \InvalidArgumentException('The year must fit the supported MySQL DATE range.');
        }

        return new \DateTimeImmutable($value->format('Y-m-d'), new \DateTimeZone('Europe/Warsaw'));
    }

    public static function utc(\DateTimeImmutable $value): \DateTimeImmutable
    {
        $utc = $value->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
    }
}
