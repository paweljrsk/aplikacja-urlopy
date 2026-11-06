<?php

namespace App\Service;

final class CredentialsPolicy
{
    public static function validateNewPassword(#[\SensitiveParameter] string $password): void
    {
        if (!mb_check_encoding($password, 'UTF-8') || mb_strlen($password, 'UTF-8') < 15 || mb_strlen($password, 'UTF-8') > 128 || '' === trim($password) || str_contains($password, "\0")) {
            throw new CredentialsException('VALIDATION_FAILED', 'Hasło musi mieć od 15 do 128 znaków. Możesz użyć spacji.');
        }
    }
}
