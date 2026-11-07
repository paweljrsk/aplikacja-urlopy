<?php

namespace App\Controller\Access;

use App\Entity\User;
use App\Service\Admin\AdminPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AdminUserListController extends AbstractController
{
    #[Route('/api/admin/users', methods: ['GET'])]
    public function users(EntityManagerInterface $em, AdminPresenter $presenter): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $users = $em->getRepository(User::class)->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC', 'id' => 'ASC']);
        return new JsonResponse(['users' => array_map($presenter->user(...), $users)]);
    }
}
