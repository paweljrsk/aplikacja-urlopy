<?php

namespace App\Model;

use App\Entity\StaffAssignment;

final readonly class RequestedDay
{
    public \DateTimeImmutable $date;

    public function __construct(public StaffAssignment $assignment, \DateTimeImmutable $date, public int $hours)
    {
        $this->date = DateValue::local($date);
        if ($hours < 1 || $hours > 8) {
            throw new \InvalidArgumentException('A day must contain 1–8 whole hours.');
        }
    }
}
