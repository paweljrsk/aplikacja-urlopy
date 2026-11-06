<?php

namespace App\Enum;

enum RequestMode: string
{
    case FULL_DAYS = 'full_days';
    case HOURLY = 'hourly';
}
