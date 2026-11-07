<?php

namespace App\Service\Admin;

use App\Service\CredentialsException;

final class AdminInput
{
    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    public static function only(array $data, array $allowed): void
    {
        if ([] !== array_diff(array_keys($data), $allowed)) {
            throw new CredentialsException('VALIDATION_FAILED', 'Formularz zawiera nieobsługiwane pola.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key, int $max = 255): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || '' === trim($value) || mb_strlen($value) > $max) {
            throw new CredentialsException('VALIDATION_FAILED', 'Uzupełnij poprawnie pole '.$key.'.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key, ?bool $default = null): bool
    {
        $value = array_key_exists($key, $data) ? $data[$key] : $default;
        if (!\is_bool($value)) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowa wartość pola '.$key.'.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value) || $value < 1) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowa wartość pola '.$key.'.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function date(array $data, string $key): \DateTimeImmutable
    {
        $value = self::string($data, $key, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Warsaw'));
        if (false === $date || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 1000) {
            throw new CredentialsException('VALIDATION_FAILED', 'Podaj poprawną datę w formacie RRRR-MM-DD.');
        }
        return $date;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<int>
     */
    public static function ids(array $data, string $key): array
    {
        $values = $data[$key] ?? null;
        if (!\is_array($values) || !array_is_list($values) || [] === $values || count($values) > 200) {
            throw new CredentialsException('VALIDATION_FAILED', 'Wybierz co najmniej jedną formę zatrudnienia.');
        }
        foreach ($values as $value) {
            if (!\is_int($value) || $value < 1) {
                throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowa lista identyfikatorów.');
            }
        }
        return array_values(array_unique($values));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int|string, string>
     */
    public static function names(array $data): array
    {
        $values = array_key_exists('namesByEmploymentType', $data) ? $data['namesByEmploymentType'] : [];
        if (!\is_array($values) || count($values) > 200) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowe nazwy dla form zatrudnienia.');
        }
        $names = [];
        foreach ($values as $key => $value) {
            if (!ctype_digit((string) $key) || (int) $key < 1 || !\is_string($value) || '' === trim($value) || mb_strlen(trim($value)) > 255) {
                throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowa nazwa dla formy zatrudnienia.');
            }
            $names[(string) $key] = trim($value);
        }
        return $names;
    }
}
