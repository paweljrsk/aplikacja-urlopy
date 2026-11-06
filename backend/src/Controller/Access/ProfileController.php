<?php

namespace App\Controller\Access;

use App\Entity\User;
use App\Security\AccessPolicy;
use App\Security\ApiResponse;
use App\Security\IdentityFactory;
use App\Security\SecurityIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    #[Route('/api/users/{id}/profile', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function profile(int $id, EntityManagerInterface $em, AccessPolicy $policy, IdentityFactory $identities, ClockInterface $clock): JsonResponse
    {
        $identity = $this->getUser();
        $actor = $identity instanceof SecurityIdentity ? $em->find(User::class, $identity->getId()) : null;
        if (null === $actor) {
            return ApiResponse::error('AUTH_REQUIRED', 'Zaloguj się, aby kontynuować.', 401);
        }
        $subject = $em->find(User::class, $id);
        if (null === $subject) {
            return ApiResponse::error('NOT_FOUND', 'Nie znaleziono użytkownika.', 404);
        }
        if (!$policy->canViewProfile($actor, $subject, $clock->now())) {
            return ApiResponse::error('FORBIDDEN', 'Brak dostępu do tego profilu.', 403);
        }

        return new JsonResponse(['user' => $identities->create($subject)->toArray()]);
    }
}
