<?php

namespace App\Controller\Requests;

use App\Entity\User;
use App\Security\ApiResponse;
use App\Security\SecurityIdentity;
use App\Service\CredentialsException;
use App\Service\Requests\RequestQueryService;
use App\Service\Requests\RequestWorkflowService;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Session and CSRF are enforced globally; workflow authorization is repeated under locks. */
final class RequestController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private RequestQueryService $queries,
        private RequestWorkflowService $workflow,
    ) {
    }

    #[Route('/api/requests/options', methods: ['GET'])]
    public function options(): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json($this->queries->options($actor)));
    }

    #[Route('/api/requests', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json($this->queries->list($actor, $request->query->all())));
    }

    #[Route('/api/requests/{id}', requirements: ['id' => '[1-9]\\d{0,17}'], methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['request' => $this->queries->detail($actor, $id)]));
    }

    #[Route('/api/requests', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['operation' => $this->workflow->create($actor, $this->read($request))], 201));
    }

    #[Route('/api/requests/preview', methods: ['POST'])]
    public function previewCreate(Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['preview' => $this->workflow->previewCreate($actor, $this->read($request))]));
    }

    #[Route('/api/requests/{id}/edit-preview', requirements: ['id' => '[1-9]\\d{0,17}'], methods: ['POST'])]
    public function previewEdit(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['preview' => $this->workflow->previewEdit($actor, $id, $this->read($request))]));
    }

    #[Route('/api/requests/{id}/change-preview', requirements: ['id' => '[1-9]\\d{0,17}'], methods: ['POST'])]
    public function previewChange(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['preview' => $this->workflow->previewChange($actor, $id, $this->read($request))]));
    }

    #[Route('/api/requests/{id}/edit', requirements: ['id' => '[1-9]\\d{0,17}'], methods: ['POST'])]
    public function edit(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['operation' => $this->workflow->edit($actor, $id, $this->read($request))]));
    }

    #[Route('/api/requests/{id}/change', requirements: ['id' => '[1-9]\\d{0,17}'], methods: ['POST'])]
    public function change(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['operation' => $this->workflow->proposeChange($actor, $id, $this->read($request))]));
    }

    #[Route('/api/requests/{id}/cancel', requirements: ['id' => '[1-9]\\d{0,17}'], methods: ['POST'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['operation' => $this->workflow->proposeCancellation($actor, $id, $this->read($request))]));
    }

    #[Route('/api/requests/{id}/correction-preview', requirements: ['id' => '[1-9]\\d{0,17}'], methods: ['POST'])]
    public function correctionPreview(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['preview' => $this->workflow->previewCorrection($actor, $id, $this->read($request))]));
    }

    #[Route('/api/requests/{id}/correct', requirements: ['id' => '[1-9]\\d{0,17}'], methods: ['POST'])]
    public function correct(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['operation' => $this->workflow->correct($actor, $id, $this->read($request))]));
    }

    #[Route('/api/requests/{id}/withdraw', requirements: ['id' => '[1-9]\\d{0,17}'], methods: ['POST'])]
    public function withdraw(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['operation' => $this->workflow->withdraw($actor, $id, $this->read($request))]));
    }

    #[Route('/api/requests/{id}/approve', requirements: ['id' => '[1-9]\\d{0,17}'], methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['operation' => $this->workflow->approve($actor, $id, $this->read($request))]));
    }

    #[Route('/api/requests/{id}/reject', requirements: ['id' => '[1-9]\\d{0,17}'], methods: ['POST'])]
    public function reject(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['operation' => $this->workflow->reject($actor, $id, $this->read($request))]));
    }

    /** @param callable(User): JsonResponse $operation */
    private function execute(callable $operation): JsonResponse
    {
        try {
            $identity = $this->getUser();
            $actor = $identity instanceof SecurityIdentity ? $this->em->find(User::class, $identity->getId()) : null;
            if (null === $actor || !$actor->isActive()) {
                throw new CredentialsException('AUTH_REQUIRED', 'Zaloguj się, aby kontynuować.', 401);
            }

            return $operation($actor);
        } catch (CredentialsException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        } catch (OptimisticLockException|RetryableException|UniqueConstraintViolationException) {
            return ApiResponse::error('CONFLICT', 'Wniosek lub rozliczenia zmieniły się. Odśwież dane i ponów operację.', 409);
        } catch (\InvalidArgumentException|\DomainException) {
            return ApiResponse::error('VALIDATION_FAILED', 'Sprawdź daty, pełne godziny i wymagane uzasadnienie.', 422);
        }
    }

    /** @return array<string, mixed> */
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
}
