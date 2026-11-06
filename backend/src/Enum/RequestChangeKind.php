<?php

namespace App\Enum;

enum RequestChangeKind: string
{
    case INITIAL = 'initial';
    case CHANGE = 'change';
    case CANCELLATION = 'cancellation';
}
