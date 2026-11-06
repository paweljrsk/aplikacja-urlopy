<?php

namespace App\Security;

use App\Entity\StaffAssignment;
use App\Entity\User;

final class CurrentAssignmentResolver
{
    public function resolve(User $user, \DateTimeImmutable $date): ?StaffAssignment
    {
        $day = $date->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
        foreach ($user->getAssignments() as $assignment) {
            if ($assignment->getValidFrom()->format('Y-m-d') <= $day
                && (null === $assignment->getValidUntil() || $assignment->getValidUntil()->format('Y-m-d') > $day)) {
                return $assignment;
            }
        }

        return null;
    }
}
