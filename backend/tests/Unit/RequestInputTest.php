<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\CredentialsException;
use App\Service\Requests\RequestInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestInputTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function emptyReasons(): iterable
    {
        yield 'empty string' => [''];
        yield 'ordinary whitespace' => [" \n\t "];
        yield 'nonbreaking spaces' => ["\u{00A0}\u{00A0}"];
        yield 'Unicode separators' => ["\u{2003}\u{202F}\u{3000}"];
        yield 'invisible format controls' => ["\u{200B}\u{FEFF}"];
        yield 'null' => [null];
        yield 'integer' => [8];
    }

    #[DataProvider('emptyReasons')]
    public function testMandatoryReasonCannotBeVisuallyEmpty(mixed $reason): void
    {
        try {
            RequestInput::decision(['operationKey' => 'reject', 'expectedVersion' => 1, 'reason' => $reason], true);
            self::fail('A rejection needs a visible reason.');
        } catch (CredentialsException $exception) {
            self::assertSame('REASON_REQUIRED', $exception->errorCode);
        }
    }

    public function testReasonPreservesPolishTextAndInternalNewlineWhileTrimmingUnicodePadding(): void
    {
        $result = RequestInput::decision(['operationKey' => 'reject', 'expectedVersion' => 1,
            'reason' => "\u{00A0}Brak zastępstwa.\nUzgodnij inny termin.\u{2003}"], true);
        self::assertSame("Brak zastępstwa.\nUzgodnij inny termin.", $result['reason']);
    }
}
