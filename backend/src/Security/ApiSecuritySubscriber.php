<?php

namespace App\Security;

use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ApiSecuritySubscriber implements EventSubscriberInterface
{
    public function __construct(private TokenStorageInterface $tokens, private CsrfTokenManagerInterface $csrf, private SecuritySession $sessions, private ClockInterface $clock)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', -10], KernelEvents::RESPONSE => ['onResponse', -255]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/') || '/api/health' === $request->getPathInfo()) {
            return;
        }
        if (!$request->isMethodSafe() && !$this->csrf->isTokenValid(new CsrfToken('api', $request->headers->get('X-CSRF-Token', '')))) {
            $event->setResponse(ApiResponse::error('CSRF_INVALID', 'Nieprawidłowy token zabezpieczający. Odśwież stronę.', 403));

            return;
        }
        $identity = $this->tokens->getToken()?->getUser();
        if (!$identity instanceof SecurityIdentity) {
            return;
        }
        $session = $request->getSession();
        $temporaryExpired = $identity->mustChangePassword() && (null === $identity->getTemporaryPasswordExpiresAt() || $identity->getTemporaryPasswordExpiresAt() <= $this->clock->now()->getTimestamp());
        if (!$identity->isActive() || $temporaryExpired || $this->sessions->hasExpired($session)) {
            $this->tokens->setToken(null);
            $session->invalidate();
            $event->setResponse(ApiResponse::error('AUTH_REQUIRED', 'Sesja wygasła. Zaloguj się ponownie.', 401));

            return;
        }
        if ($identity->mustChangePassword() && !\in_array($request->getPathInfo(), ['/api/auth/csrf', '/api/auth/me', '/api/auth/change-password', '/api/auth/logout'], true)) {
            $event->setResponse(ApiResponse::error('PASSWORD_CHANGE_REQUIRED', 'Zmień hasło tymczasowe przed rozpoczęciem pracy.', 403));

            return;
        }
        $this->sessions->touch($session);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
            $event->getResponse()->headers->set('X-Content-Type-Options', 'nosniff');
            $event->getResponse()->headers->set('X-Frame-Options', 'DENY');
            $event->getResponse()->headers->set('Content-Security-Policy', "frame-ancestors 'none'; object-src 'none'; base-uri 'self'");
            $event->getResponse()->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
            $event->getResponse()->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        }
    }
}
