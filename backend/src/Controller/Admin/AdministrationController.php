<?php

namespace App\Controller\Admin;

use App\Entity\AbsenceType;
use App\Entity\EmploymentType;
use App\Entity\Team;
use App\Entity\User;
use App\Security\ApiResponse;
use App\Security\SecurityIdentity;
use App\Service\Admin\AdminInput;
use App\Service\Admin\AdminHistory;
use App\Service\Admin\AdminPresenter;
use App\Service\Admin\AdministrationService;
use App\Service\CredentialsException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class AdministrationController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private AdministrationService $administration, private AdminPresenter $presenter, #[Autowire(service: 'limiter.credential_action')] private RateLimiterFactory $confirmationLimiter)
    {
    }

    #[Route('/api/admin/catalog', methods: ['GET'])]
    public function catalog(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return $this->json($this->presenter->catalog());
    }

    #[Route('/api/admin/history', methods: ['GET'])]
    public function history(Request $request, AdminHistory $history): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        try {
            return $this->json($history->page($request->query->all()));
        } catch (CredentialsException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }
    }

    #[Route('/api/admin/users/{id}', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function user(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = $this->em->find(User::class, $id);
        return null === $user ? ApiResponse::error('NOT_FOUND', 'Nie znaleziono konta.', 404) : $this->json(['user' => $this->presenter->user($user)]);
    }

    #[Route('/api/admin/users', methods: ['POST'])]
    public function createUser(Request $request): JsonResponse
    {
        return $this->execute($request, function (User $actor, array $data): JsonResponse {
            $result = $this->administration->createUser($actor, $data);
            return $this->json(['user' => $this->presenter->user($result['user']), 'temporaryPassword' => $result['temporaryPassword'], 'expiresAt' => $result['expiresAt']->format(\DATE_ATOM)], 201);
        }, true);
    }

    #[Route('/api/admin/users/{id}', requirements: ['id' => '\\d+'], methods: ['PATCH'])]
    public function updateUser(int $id, Request $request): JsonResponse
    {
        return $this->execute($request, function (User $actor, array $data) use ($id): JsonResponse {
            $user = $this->find(User::class, $id);
            return $this->json(['user' => $this->presenter->user($this->administration->updateUser($actor, $user, $data))]);
        }, true);
    }

    #[Route('/api/admin/users/{id}/assignments', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function assignUser(int $id, Request $request): JsonResponse
    {
        return $this->execute($request, function (User $actor, array $data) use ($id): JsonResponse {
            $user = $this->find(User::class, $id);
            return $this->json(['user' => $this->presenter->user($this->administration->assignUser($actor, $user, $data))]);
        }, true);
    }

    #[Route('/api/admin/{kind}', requirements: ['kind' => 'teams|employment-types|absence-types'], methods: ['POST'])]
    public function createDictionary(string $kind, Request $request): JsonResponse
    {
        return $this->execute($request, function (User $actor, array $data) use ($kind): JsonResponse {
            $item = match ($kind) {
                'teams' => $this->presenter->team($this->administration->saveTeam($actor, null, $data)),
                'employment-types' => $this->presenter->employmentType($this->administration->saveEmploymentType($actor, null, $data)),
                default => $this->presenter->absenceType($this->administration->saveAbsenceType($actor, null, $data)),
            };
            return $this->json(['item' => $item], 201);
        });
    }

    #[Route('/api/admin/{kind}/{id}', requirements: ['kind' => 'teams|employment-types|absence-types', 'id' => '\\d+'], methods: ['PATCH'])]
    public function updateDictionary(string $kind, int $id, Request $request): JsonResponse
    {
        return $this->execute($request, function (User $actor, array $data) use ($kind, $id): JsonResponse {
            $item = match ($kind) {
                'teams' => $this->presenter->team($this->administration->saveTeam($actor, $this->find(Team::class, $id), $data)),
                'employment-types' => $this->presenter->employmentType($this->administration->saveEmploymentType($actor, $this->find(EmploymentType::class, $id), $data)),
                default => $this->presenter->absenceType($this->administration->saveAbsenceType($actor, $this->find(AbsenceType::class, $id), $data)),
            };
            return $this->json(['item' => $item]);
        });
    }

    #[Route('/api/admin/catalog/initialize', methods: ['POST'])]
    public function initialize(Request $request): JsonResponse
    {
        return $this->execute($request, function (User $actor, array $data): JsonResponse {
            AdminInput::only($data, []);
            $this->administration->initializeCatalog($actor);
            return $this->json($this->presenter->catalog());
        });
    }

    /**
     * @param callable(User, array<string, mixed>): JsonResponse $operation
     */
    private function execute(Request $request, callable $operation, bool $confirmation = false): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $identity = $this->getUser();
        if (!$identity instanceof SecurityIdentity) {
            return ApiResponse::error('AUTH_REQUIRED', 'Zaloguj się.', 401);
        }
        try {
            $data = $this->read($request);
            if ($confirmation) {
                $limit = $this->confirmationLimiter->create('credentials:'.$identity->getId())->consume(1);
                if (!$limit->isAccepted()) {
                    throw new CredentialsException('CREDENTIAL_THROTTLED', 'Zbyt wiele prób potwierdzenia hasła. Spróbuj ponownie za minutę.', 429);
                }
            }
            $actor = $this->find(User::class, $identity->getId());
            $response = $operation($actor, $data);
            if ($confirmation) {
                $this->confirmationLimiter->create('credentials:'.$identity->getId())->reset();
            }
            return $response;
        } catch (CredentialsException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        } catch (OptimisticLockException|RetryableException) {
            return ApiResponse::error('CONFLICT', 'Dane zmieniły się równocześnie. Odśwież widok i ponów operację.', 409);
        } catch (UniqueConstraintViolationException) {
            return ApiResponse::error('DUPLICATE_VALUE', 'Adres e-mail lub kod jest już używany.', 409);
        } catch (\InvalidArgumentException|\DomainException) {
            return ApiResponse::error('VALIDATION_FAILED', 'Sprawdź dane formularza, dostępność wybranych pozycji i datę przypisania.', 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(Request $request): array
    {
        $content = $request->getContent();
        if (strlen($content) > 65536 || !str_starts_with(ltrim($content), '{')) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowy format danych.', 400);
        }
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowy format danych.', 400);
        }
        if (!\is_array($data)) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowy format danych.', 400);
        }
        return $data;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function find(string $class, int $id): object
    {
        return $this->em->find($class, $id) ?? throw new CredentialsException('NOT_FOUND', 'Nie znaleziono wskazanej pozycji.', 404);
    }
}
