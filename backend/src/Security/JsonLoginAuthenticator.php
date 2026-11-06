<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class JsonLoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private SecuritySession $sessions)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && '/api/auth/login' === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        if ('json' !== $request->getContentTypeFormat() || \strlen($request->getContent()) > 8192) {
            throw new BadCredentialsException('Invalid credentials.');
        }
        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            throw new BadRequestHttpException('Invalid JSON payload.');
        }
        $email = $payload['email'] ?? null;
        $password = $payload['password'] ?? null;
        if (!\is_string($email) || !\is_string($password) || '' === trim($email)
            || \strlen($email) > 254 || '' === $password || \strlen($password) > 4096) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        return new Passport(new UserBadge(strtolower(trim($email))), new PasswordCredentials($password), [new CsrfTokenBadge('api', $request->headers->get('X-CSRF-Token', ''))]);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $identity = $token->getUser();
        if (!$identity instanceof SecurityIdentity) {
            throw new \LogicException('Unexpected security identity.');
        }
        $this->sessions->start($request->getSession());

        return new JsonResponse(['user' => $identity->toArray()]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof InvalidCsrfTokenException) {
            return ApiResponse::error('CSRF_INVALID', 'Nieprawidłowy token zabezpieczający. Odśwież stronę.', 403);
        }
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $response = ApiResponse::error('LOGIN_THROTTLED', 'Zbyt wiele prób logowania. Spróbuj ponownie za chwilę.', 429);
            $response->headers->set('Retry-After', '60');

            return $response;
        }

        return ApiResponse::error('INVALID_CREDENTIALS', 'Nieprawidłowy e-mail lub hasło.', 401);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ApiResponse::error('AUTH_REQUIRED', 'Zaloguj się, aby kontynuować.', 401);
    }
}
