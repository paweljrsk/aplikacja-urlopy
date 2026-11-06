<?php

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** Never render debug exception pages or log request bodies for API failures. */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private TokenStorageInterface $tokens, private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 256]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $exception = $event->getThrowable();
        $status = 500;
        $code = 'INTERNAL_ERROR';
        $message = 'Nie udało się wykonać operacji.';
        if ($exception instanceof AuthenticationException || ($exception instanceof AccessDeniedException && !$this->tokens->getToken()?->getUser() instanceof SecurityIdentity)) {
            $status = 401;
            $code = 'AUTH_REQUIRED';
            $message = 'Zaloguj się, aby kontynuować.';
        } elseif ($exception instanceof AccessDeniedException) {
            $status = 403;
            $code = 'FORBIDDEN';
            $message = 'Brak uprawnień do tej operacji.';
        } elseif ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $code = match ($status) {
                404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 403 => 'FORBIDDEN', default => 'INVALID_REQUEST'
            };
            $message = 'Nie można wykonać tego żądania.';
        }
        if ($status >= 500) {
            $this->logger->error('API operation failed.', ['exception_class' => $exception::class]);
        }
        $response = ApiResponse::error($code, $message, $status);
        if ($exception instanceof HttpExceptionInterface && 405 === $status) {
            $response->headers->add($exception->getHeaders());
        }
        $event->setResponse($response);
    }
}
