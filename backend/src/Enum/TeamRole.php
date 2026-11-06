<?php

namespace App\Enum;

enum TeamRole: string
{
    case EMPLOYEE = 'employee';
    case MANAGER = 'manager';
    case DEPUTY = 'deputy';
}
