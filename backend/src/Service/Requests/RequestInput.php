<?php

declare(strict_types=1);

namespace App\Service\Requests;

use App\Enum\RequestMode;
use App\Service\Admin\AdminInput;
use App\Service\Calendar\WorkingCalendar;
use App\Service\CredentialsException;

/** Strict JSON-domain input; calendar and authorization checks belong inside the transaction. */
final class RequestInput
{
    /** @param array<string, mixed> $data
     * @return array{operationKey: string, absenceTypeId: int, startDate: string, endDate: string, mode: string, hours?: int, expectedVersion?: int}
     */
    public static function application(array $data, bool $editing = false): array
    {
        AdminInput::only($data, $editing
            ? ['operationKey', 'expectedVersion', 'absenceTypeId', 'startDate', 'endDate', 'mode', 'hours']
            : ['operationKey', 'absenceTypeId', 'startDate', 'endDate', 'mode', 'hours']);
        $start = AdminInput::date($data, 'startDate');
        $end = AdminInput::date($data, 'endDate');
        WorkingCalendar::assertYear((int) $start->format('Y'));
        WorkingCalendar::assertYear((int) $end->format('Y'));
        $modeValue = array_key_exists('mode', $data) ? $data['mode'] : RequestMode::FULL_DAYS->value;
        $mode = \is_string($modeValue) ? RequestMode::tryFrom($modeValue) : null;
        if (null === $mode || $start > $end) {
            throw new CredentialsException('VALIDATION_FAILED', 'Podaj prawidłowy tryb i uporządkowany zakres dat.');
        }
        $result = ['operationKey' => self::key($data), 'absenceTypeId' => AdminInput::int($data, 'absenceTypeId'),
            'startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d'), 'mode' => $mode->value];
        if (RequestMode::HOURLY === $mode) {
            $hours = $data['hours'] ?? null;
            if ($start != $end || !\is_int($hours) || $hours < 1 || $hours > 7) {
                throw new CredentialsException('VALIDATION_FAILED', 'Wniosek godzinowy obejmuje jeden dzień na 1–7 pełnych godzin.');
            }
            $result['hours'] = $hours;
        } elseif (array_key_exists('hours', $data)) {
            throw new CredentialsException('VALIDATION_FAILED', 'Zakres pełnych dni nie przyjmuje osobnego pola hours.');
        }
        if ($editing) { $result['expectedVersion'] = AdminInput::int($data, 'expectedVersion'); }
        return $result;
    }

    /** @param array<string, mixed> $data
     * @return array{operationKey: string, expectedVersion: int, reason?: string}
     */
    public static function decision(array $data, bool $rejection = false): array
    {
        AdminInput::only($data, $rejection ? ['operationKey', 'expectedVersion', 'reason'] : ['operationKey', 'expectedVersion']);
        $result = ['operationKey' => self::key($data), 'expectedVersion' => AdminInput::int($data, 'expectedVersion')];
        if ($rejection) {
            $reason = $data['reason'] ?? null;
            $reason = \is_string($reason) ? preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $reason) : null;
            if (null === $reason || 1 !== preg_match('/[^\s\p{Z}\p{C}]/u', $reason) || mb_strlen($reason) > 1000) {
                throw new CredentialsException('REASON_REQUIRED', 'Podaj powód odrzucenia (1–1000 znaków).');
            }
            $result['reason'] = $reason;
        }
        return $result;
    }

    /** @param array<string, mixed> $data */
    private static function key(array $data): string
    {
        $key = $data['operationKey'] ?? null;
        if (!\is_string($key) || 1 !== preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $key)) {
            throw new CredentialsException('VALIDATION_FAILED', 'Podaj klucz operacji: 1–64 znaki ASCII, cyfry lub . _ : -.');
        }
        return $key;
    }
}
