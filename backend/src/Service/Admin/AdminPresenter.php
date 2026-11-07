<?php

namespace App\Service\Admin;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\EmploymentType;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Security\CurrentAssignmentResolver;
use App\Security\IdentityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class AdminPresenter
{
    public function __construct(private IdentityFactory $identities, private CurrentAssignmentResolver $assignments, private ClockInterface $clock, private EntityManagerInterface $em)
    {
    }

    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        $assignment = $this->assignments->resolve($user, $this->clock->now());
        return $this->identities->create($user)->toArray() + [
            'active' => $user->isActive(), 'version' => $user->getVersion(),
            'employmentType' => null === $assignment ? null : $this->employmentType($assignment->getEmploymentType()),
            'assignments' => array_map($this->assignment(...), $user->getAssignments()),
        ];
    }

    /** @return array<string, mixed> */
    public function assignment(StaffAssignment $item): array
    {
        return ['id' => $item->getId(), 'team' => $this->team($item->getTeam()), 'employmentType' => $this->employmentType($item->getEmploymentType()), 'role' => $item->getRole()->value, 'validFrom' => $item->getValidFrom()->format('Y-m-d'), 'validUntil' => $item->getValidUntil()?->format('Y-m-d')];
    }

    /** @return array<string, mixed> */
    public function team(Team $item): array
    {
        return ['id' => $item->getId(), 'code' => $item->getCode(), 'name' => $item->getName(), 'active' => $item->isActive(), 'version' => $item->getVersion()];
    }

    /** @return array<string, mixed> */
    public function employmentType(EmploymentType $item): array
    {
        return ['id' => $item->getId(), 'code' => $item->getCode(), 'name' => $item->getName(), 'active' => $item->isActive(), 'version' => $item->getVersion(), 'carriesUnusedHours' => $item->carriesUnusedHours()];
    }

    /** @return array<string, mixed> */
    public function absenceType(AbsenceType $item): array
    {
        $version = $this->em->getRepository(AbsenceTypeVersion::class)->findOneBy(['type' => $item, 'revision' => $item->getLatestRevision()]);
        return ['id' => $item->getId(), 'code' => $item->getCode(), 'active' => $item->isActive(), 'version' => $item->getVersion(), 'revision' => $item->getLatestRevision(), 'name' => $version?->getName() ?? $item->getCode(), 'deductsBalance' => $version?->deductsBalance() ?? false, 'employmentTypeIds' => array_map(static fn (EmploymentType $type): ?int => $type->getId(), $version?->getEmploymentTypes() ?? []), 'namesByEmploymentType' => (object) ($version?->getNamesByEmploymentType() ?? [])];
    }

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        return ['teams' => array_map($this->team(...), $this->em->getRepository(Team::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC'])), 'employmentTypes' => array_map($this->employmentType(...), $this->em->getRepository(EmploymentType::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC'])), 'absenceTypes' => array_map($this->absenceType(...), $this->em->getRepository(AbsenceType::class)->findBy([], ['code' => 'ASC'])), 'roles' => [['value' => 'employee', 'label' => 'Pracownik'], ['value' => 'manager', 'label' => 'Kierownik'], ['value' => 'deputy', 'label' => 'Zastępca']]];
    }
}
