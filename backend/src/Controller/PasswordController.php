<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\SecurityIdentity;
use App\Service\CredentialsException;
use App\Service\CredentialsService;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class PasswordController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CredentialsService $credentials,
        private readonly TokenStorageInterface $tokens,
        #[Autowire(service: 'limiter.credential_action')]
        private readonly RateLimiterFactory $confirmationLimiter,
    ) {
    }

    #[Route('/api/auth/change-password', name: 'api_auth_change_password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $identity = $this->getUser();
        if (!$identity instanceof SecurityIdentity) {
            return $this->failure('AUTH_REQUIRED', 'Zaloguj się.', 401);
        }

        try {
            $data = $this->readPasswords($request, ['currentPassword', 'newPassword']);
            $this->consumeConfirmationAttempt($identity);
            $user = $this->em->find(User::class, $identity->getId());
            if (null === $user) {
                return $this->failure('AUTH_REQUIRED', 'Zaloguj się.', 401);
            }
            $this->credentials->changePassword($user, $data['currentPassword'], $data['newPassword']);
            $this->confirmationLimiter->create('credentials:'.$identity->getId())->reset();

            // The updated credential revision invalidates other sessions at their next request.
            $this->tokens->setToken(null);
            $request->getSession()->invalidate();

            return $this->json(['status' => 'password_changed']);
        } catch (CredentialsException $exception) {
            return $this->failure($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        } catch (OptimisticLockException|RetryableException) {
            return $this->failure('CONFLICT', 'Stan konta zmienił się. Zaloguj się ponownie.', 409);
        }
    }

    #[Route('/api/admin/users/{id}/reset-password', name: 'api_admin_reset_password', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function resetPassword(int $id, Request $request): JsonResponse
    {
        $identity = $this->getUser();
        if (!$identity instanceof SecurityIdentity) {
            return $this->failure('AUTH_REQUIRED', 'Zaloguj się.', 401);
        }
        if (!$identity->isAdministrator()) {
            return $this->failure('FORBIDDEN', 'Brak uprawnień.', 403);
        }
        if ($identity->getId() === $id) {
            return $this->failure('VALIDATION_FAILED', 'Własne hasło zmień w formularzu zmiany hasła.', 422);
        }

        try {
            $data = $this->readPasswords($request, ['adminPassword']);
            $this->consumeConfirmationAttempt($identity);
            $actor = $this->em->find(User::class, $identity->getId());
            $target = $this->em->find(User::class, $id);
            if (null === $actor) {
                return $this->failure('AUTH_REQUIRED', 'Zaloguj się.', 401);
            }
            if (null === $target) {
                return $this->failure('NOT_FOUND', 'Nie znaleziono konta.', 404);
            }
            $result = $this->credentials->resetPassword($actor, $target, $data['adminPassword']);
            $this->confirmationLimiter->create('credentials:'.$identity->getId())->reset();

            return $this->json(['temporaryPassword' => $result['temporaryPassword'], 'expiresAt' => $result['expiresAt']->format(\DATE_ATOM)]);
        } catch (CredentialsException $exception) {
            return $this->failure($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        } catch (OptimisticLockException|RetryableException) {
            return $this->failure('CONFLICT', 'Stan konta zmienił się. Spróbuj ponownie.', 409);
        }
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, string>
     */
    private function readPasswords(Request $request, array $fields): array
    {
        if (strlen($request->getContent()) > 8192) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowe dane formularza.', 400);
        }
        try {
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowy format danych.', 400);
        }
        if (!\is_array($data)) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowe dane formularza.', 400);
        }
        $result = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || !\is_string($data[$field]) || strlen($data[$field]) > 1024) {
                throw new CredentialsException('VALIDATION_FAILED', 'Uzupełnij poprawnie pola hasła.');
            }
            $result[$field] = $data[$field];
        }

        return $result;
    }

    private function consumeConfirmationAttempt(SecurityIdentity $identity): void
    {
        // Shared across reset targets and password changes; target IDs cannot bypass throttling.
        $limit = $this->confirmationLimiter->create('credentials:'.$identity->getId())->consume(1);
        if (!$limit->isAccepted()) {
            throw new CredentialsException('CREDENTIAL_THROTTLED', 'Zbyt wiele prób potwierdzenia hasła. Spróbuj ponownie za minutę.', 429);
        }
    }

    private function failure(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
