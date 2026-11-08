<?php

namespace App\Tests\Unit;

use App\Security\ApiSecuritySubscriber;
use App\Security\SecuritySession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ApiSecurityHeadersTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function apiResponses(): iterable
    {
        yield 'public csrf' => ['/api/auth/csrf', 200];
        yield 'business response' => ['/api/requests', 200];
        yield 'not authenticated' => ['/api/auth/me', 401];
        yield 'forbidden' => ['/api/admin/users', 403];
        yield 'not found' => ['/api/missing', 404];
        yield 'failed request' => ['/api/requests', 500];
    }

    #[DataProvider('apiResponses')]
    public function testEveryApiResponseIncludesDefensiveHeaders(string $path, int $status): void
    {
        $clock = new MockClock('2026-11-08T12:00:00Z');
        $subscriber = new ApiSecuritySubscriber(new TokenStorage(), $this->createStub(CsrfTokenManagerInterface::class), new SecuritySession($clock), $clock);
        $response = new Response('{}', $status, ['Cache-Control' => 'public, max-age=3600']);
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create('http://localhost'.$path), HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onResponse($event);

        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame("frame-ancestors 'none'; object-src 'none'; base-uri 'self'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }
}
