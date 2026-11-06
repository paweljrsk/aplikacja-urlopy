<?php

namespace App\Enum;

enum DecisionOutcome: string
{
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case WITHDRAWN = 'withdrawn';
    case SUPERSEDED = 'superseded';
}
