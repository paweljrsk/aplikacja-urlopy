<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiResponse
{
    public static function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status, ['Cache-Control' => 'no-store, private']);
    }
}
