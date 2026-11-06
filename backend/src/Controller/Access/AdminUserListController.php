<?php

namespace App\Controller\Access;

use App\Entity\User;
use App\Security\IdentityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AdminUserListController extends AbstractController
{
    #[Route('/api/admin/users', methods: ['GET'])]
    public function users(EntityManagerInterface $em, IdentityFactory $identities): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $users = $em->getRepository(User::class)->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC', 'id' => 'ASC']);

        return new JsonResponse(['users' => array_map(static fn (User $user): array => $identities->create($user)->toArray(), $users)]);
    }
}
