<?php

namespace App\Enum;

enum LedgerEntryKind: string
{
    case GRANT = 'grant';
    case OPENING_USED = 'opening_used';
    case RESERVE = 'reserve';
    case RELEASE = 'release';
    case APPROVE = 'approve';
    case CANCEL = 'cancel';
    case ADJUSTMENT = 'adjustment';
}
