<?php

namespace App\Controller\Requests;

use App\Entity\User;
use App\Security\ApiResponse;
use App\Security\SecurityIdentity;
use App\Service\CredentialsException;
use App\Service\Requests\TeamCalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Session/forced-password gates are global; the service refreshes and scopes every read. */
final class TeamCalendarController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private TeamCalendarService $calendar)
    {
    }

    #[Route('/api/team-calendar', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        try {
            $identity = $this->getUser();
            $actor = $identity instanceof SecurityIdentity ? $this->em->find(User::class, $identity->getId()) : null;
            if (null === $actor || !$actor->isActive()) {
                throw new CredentialsException('AUTH_REQUIRED', 'Zaloguj się, aby kontynuować.', 401);
            }
            return new JsonResponse($this->calendar->list($actor, $request->query->all()), 200, ['Cache-Control' => 'no-store, private']);
        } catch (CredentialsException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        } catch (\InvalidArgumentException|\DomainException) {
            return ApiResponse::error('VALIDATION_FAILED', 'Sprawdź zakres dat kalendarza.', 422);
        }
    }
}
