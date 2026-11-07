<?php

namespace App\Service\Accounting;

use App\Service\CredentialsException;

final class Hours
{
    public static function daysToHours(int $days): int
    {
        if ($days < 0 || $days > 125000) {
            throw new CredentialsException('VALIDATION_FAILED', 'Podaj liczbę pełnych dni od 0 do 125000.');
        }
        return $days * 8;
    }

    public static function display(int $hours): string
    {
        $absolute = abs($hours);
        $duration = intdiv($absolute, 8).' dni i '.($absolute % 8).' h';
        return $hours < 0 ? '−'.$absolute.' h (niedobór: '.$duration.')' : $absolute.' h = '.$duration;
    }

    public static function nonnegative(mixed $value, string $field): int
    {
        if (!\is_int($value) || $value < 0 || $value > 1000000) {
            throw new CredentialsException('VALIDATION_FAILED', 'Pole '.$field.' musi zawierać pełne godziny od 0 do 1000000.');
        }
        return $value;
    }
}
