<?php

namespace App\Tests\Unit;

use App\Security\ApiExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class ApiExceptionSubscriberTest extends TestCase
{
    public function testUnexpectedExceptionDoesNotExposeSecretsInResponseOrLogContext(): void
    {
        $secret = 'SYNTHETIC_PRIVATE_SQL_PASSWORD_BODY';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('API operation failed.', ['exception_class' => \RuntimeException::class]);
        $request = Request::create('/api/requests', 'POST', content: '{"password":"'.$secret.'"}');
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('SQL query with '.$secret));
        (new ApiExceptionSubscriber(new TokenStorage(), $logger))->onException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
        $content = (string) $response->getContent();
        self::assertSame(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Nie udało się wykonać operacji.']], json_decode($content, true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($secret, $content);
        self::assertStringNotContainsString('RuntimeException', $content);
        self::assertStringNotContainsString('trace', $content);
    }

    public function testMethodErrorPreservesAllowHeaderButDiscardsExceptionMessage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/api/requests', 'DELETE'),
            HttpKernelInterface::MAIN_REQUEST, new MethodNotAllowedHttpException(['GET', 'POST'], 'SYNTHETIC_INTERNAL_ROUTE_DETAIL'));
        (new ApiExceptionSubscriber(new TokenStorage(), $logger))->onException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->headers->get('Allow'));
        self::assertStringNotContainsString('SYNTHETIC_INTERNAL_ROUTE_DETAIL', (string) $response->getContent());
    }
}
