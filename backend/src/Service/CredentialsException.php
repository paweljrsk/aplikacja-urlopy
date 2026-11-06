<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class CredentialsException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
