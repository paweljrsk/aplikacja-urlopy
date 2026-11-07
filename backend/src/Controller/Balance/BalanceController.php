<?php

namespace App\Controller\Balance;

use App\Entity\User;
use App\Security\ApiResponse;
use App\Security\BalanceAccessPolicy;
use App\Security\CurrentAssignmentResolver;
use App\Security\SecurityIdentity;
use App\Service\Accounting\AccountingService;
use App\Service\Calendar\CompanyCalendar;
use App\Service\CredentialsException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** HTTP boundary: session/CSRF apply globally; services recheck writes under locks. */
final class BalanceController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private BalanceAccessPolicy $access,
        private CurrentAssignmentResolver $assignments,
        private AccountingService $accounting,
        private CompanyCalendar $calendar,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/api/balances/people', methods: ['GET'])]
    public function people(): JsonResponse
    {
        return $this->execute(function (User $actor): JsonResponse {
            $now = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'));
            $people = [];
            foreach ($this->em->getRepository(User::class)->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC', 'id' => 'ASC']) as $subject) {
                if (!$this->access->canViewBalance($actor, $subject, $now)) {
                    continue;
                }
                $assignment = $this->assignments->resolve($subject, $now);
                $team = $assignment?->getTeam();
                $employment = $assignment?->getEmploymentType();
                $people[] = [
                    'id' => $subject->getId(), 'firstName' => $subject->getFirstName(), 'lastName' => $subject->getLastName(),
                    'active' => $subject->isActive(),
                    'team' => null === $team ? null : ['id' => $team->getId(), 'name' => $team->getName()],
                    'employmentType' => null === $employment ? null : ['id' => $employment->getId(), 'name' => $employment->getName(), 'carriesUnusedHours' => $employment->carriesUnusedHours()],
                    'role' => $assignment?->getRole()->value,
                    'canManage' => $this->access->canManageBalance($actor, $subject, $now),
                ];
            }

            return $this->json(['people' => $people, 'today' => $now->format('Y-m-d'), 'currentYear' => (int) $now->format('Y'), 'isAdministrator' => $actor->isAdministrator()]);
        });
    }

    #[Route('/api/balances/users/{id}/{year}', requirements: ['id' => '\\d+', 'year' => '\\d{4}'], methods: ['GET'])]
    public function balance(int $id, int $year): JsonResponse
    {
        return $this->execute(function (User $actor) use ($id, $year): JsonResponse {
            // Authorize the subject and assemble its financial projection from one read snapshot.
            return $this->em->getConnection()->transactional(function () use ($actor, $id, $year): JsonResponse {
                $subject = $this->subject($actor, $id);

                return $this->balanceResponse($actor, $subject, $this->accounting->getBalance($subject, $year));
            });
        });
    }

    #[Route('/api/balances/users/{id}/opening', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function opening(int $id, Request $request): JsonResponse
    {
        return $this->execute(function (User $actor) use ($id, $request): JsonResponse {
            $subject = $this->subject($actor, $id, true);

            return $this->balanceResponse($actor, $subject, $this->accounting->initializeOpening($actor, $subject, $this->read($request)));
        });
    }

    #[Route('/api/balances/users/{id}/opening-preview', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function previewOpening(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['preview' => $this->accounting->previewAdjustment($actor, $this->subject($actor, $id, true), 'opening', null, $this->read($request))]));
    }

    #[Route('/api/balances/users/{id}/{year}/limit-preview', requirements: ['id' => '\\d+', 'year' => '\\d{4}'], methods: ['POST'])]
    public function previewLimit(int $id, int $year, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['preview' => $this->accounting->previewAdjustment($actor, $this->subject($actor, $id, true), 'limit', $year, $this->read($request))]));
    }

    #[Route('/api/balances/users/{id}/opening/corrections-preview', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function previewOpeningCorrection(int $id, Request $request): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json(['preview' => $this->accounting->previewAdjustment($actor, $this->subject($actor, $id, true), 'correction', null, $this->read($request))]));
    }

    #[Route('/api/balances/users/{id}/{year}/limit', requirements: ['id' => '\\d+', 'year' => '\\d{4}'], methods: ['POST'])]
    public function limit(int $id, int $year, Request $request): JsonResponse
    {
        return $this->execute(function (User $actor) use ($id, $year, $request): JsonResponse {
            $subject = $this->subject($actor, $id, true);

            return $this->balanceResponse($actor, $subject, $this->accounting->setAnnualLimit($actor, $subject, $year, $this->read($request)));
        });
    }

    #[Route('/api/balances/users/{id}/opening/corrections', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function correctOpening(int $id, Request $request): JsonResponse
    {
        return $this->execute(function (User $actor) use ($id, $request): JsonResponse {
            $subject = $this->subject($actor, $id, true);

            return $this->balanceResponse($actor, $subject, $this->accounting->correctOpening($actor, $subject, $this->read($request)));
        });
    }

    #[Route('/api/balances/users/{id}/{year}/settle', requirements: ['id' => '\\d+', 'year' => '\\d{4}'], methods: ['POST'])]
    public function settle(int $id, int $year, Request $request): JsonResponse
    {
        return $this->execute(function (User $actor) use ($id, $year, $request): JsonResponse {
            $subject = $this->subject($actor, $id, true);

            return $this->balanceResponse($actor, $subject, $this->accounting->settleYear($actor, $subject, $year, $this->read($request)));
        });
    }

    #[Route('/api/calendar/{year}', requirements: ['year' => '\\d{4}'], methods: ['GET'])]
    public function calendar(int $year): JsonResponse
    {
        return $this->execute(fn (User $actor): JsonResponse => $this->json($this->calendar->list($year)));
    }

    #[Route('/api/calendar/preview', methods: ['POST'])]
    public function previewCalendar(Request $request): JsonResponse
    {
        return $this->execute(function (User $actor) use ($request): JsonResponse {
            $this->requireAdministrator($actor);

            return $this->json($this->calendar->preview($actor, $this->read($request)));
        });
    }

    #[Route('/api/calendar/apply', methods: ['POST'])]
    public function applyCalendar(Request $request): JsonResponse
    {
        return $this->execute(function (User $actor) use ($request): JsonResponse {
            $this->requireAdministrator($actor);

            return $this->json($this->calendar->apply($actor, $this->read($request)));
        });
    }

    /** @param array<string, mixed> $balance */
    private function balanceResponse(User $actor, User $subject, array $balance): JsonResponse
    {
        if (!$actor->isAdministrator() && $actor->getId() !== $subject->getId()) {
            // Financial supervision does not grant old-team absence details (B22).
            // Apply the same projection after writes, not only to the GET response.
            /** @var list<array<string, mixed>> $history */
            $history = $balance['history'];
            $balance['history'] = array_values(array_filter($history, static function (array $item): bool {
                $action = (string) $item['action'];
                return str_starts_with($action, 'balance.') || str_starts_with($action, 'opening.');
            }));
        }

        return $this->json(['balance' => $balance]);
    }

    private function subject(User $actor, int $id, bool $write = false): User
    {
        $subject = $this->em->find(User::class, $id) ?? throw new CredentialsException('NOT_FOUND', 'Nie znaleziono pracownika.', 404);
        $allowed = $write
            ? $this->access->canManageBalance($actor, $subject, $this->clock->now())
            : $this->access->canViewBalance($actor, $subject, $this->clock->now());
        if (!$allowed) {
            throw new CredentialsException('FORBIDDEN', $write ? 'Nie masz uprawnień do zmiany limitów tej osoby.' : 'Nie masz dostępu do salda tej osoby.', 403);
        }

        return $subject;
    }

    private function requireAdministrator(User $actor): void
    {
        if (!$actor->isAdministrator()) {
            throw new CredentialsException('FORBIDDEN', 'Kalendarzem dni firmowych zarządza administrator.', 403);
        }
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
            return ApiResponse::error('CONFLICT', 'Dane zmieniły się równocześnie. Odśwież widok i ponów operację.', 409);
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
