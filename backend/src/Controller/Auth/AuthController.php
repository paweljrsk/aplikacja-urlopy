<?php

namespace App\Controller\Auth;

use App\Security\ApiResponse;
use App\Security\SecurityIdentity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/auth')]
final class AuthController extends AbstractController
{
    #[Route('/csrf', methods: ['GET'])]
    public function csrf(CsrfTokenManagerInterface $csrf): JsonResponse
    {
        return new JsonResponse(['csrfToken' => $csrf->getToken('api')->getValue()]);
    }

    #[Route('/login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return ApiResponse::error('INVALID_CREDENTIALS', 'Nieprawidłowy e-mail lub hasło.', 401);
    }

    #[Route('/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $identity = $this->getUser();

        return $identity instanceof SecurityIdentity ? new JsonResponse(['user' => $identity->toArray()]) : ApiResponse::error('AUTH_REQUIRED', 'Zaloguj się, aby kontynuować.', 401);
    }

    #[Route('/logout', methods: ['POST'])]
    public function logout(Request $request, TokenStorageInterface $tokens): JsonResponse
    {
        $tokens->setToken(null);
        $request->getSession()->invalidate();

        return new JsonResponse(['status' => 'logged_out']);
    }
}
