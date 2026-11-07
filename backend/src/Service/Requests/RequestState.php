<?php

declare(strict_types=1);

namespace App\Service\Requests;

use App\Entity\LeaveRequest;
use App\Entity\RequestRevision;
use App\Enum\DecisionOutcome;
use App\Enum\RequestChangeKind;

/** Presentation of immutable revisions; workflow transitions are checked separately. */
final class RequestState
{
    public static function current(LeaveRequest $request): ?RequestRevision
    {
        $revisions = $request->getRevisions();
        return $request->getPendingRevision() ?? $request->getEffectiveRevision() ?? ($revisions[array_key_last($revisions)] ?? null);
    }

    public static function status(LeaveRequest $request): string
    {
        $pending = $request->getPendingRevision();
        if (null !== $pending) {
            return match ($pending->getKind()) {
                RequestChangeKind::INITIAL => 'pending',
                RequestChangeKind::CHANGE => 'change_pending',
                RequestChangeKind::CANCELLATION => 'cancellation_pending',
            };
        }
        if (null !== $request->getEffectiveRevision()) { return 'approved'; }
        $current = self::current($request);
        if (RequestChangeKind::CANCELLATION === $current?->getKind() && DecisionOutcome::APPROVED === $current->getDecision()?->getOutcome()) {
            return 'cancelled';
        }
        return match ($current?->getDecision()?->getOutcome()) {
            DecisionOutcome::REJECTED => 'rejected',
            DecisionOutcome::WITHDRAWN => 'withdrawn',
            DecisionOutcome::SUPERSEDED => 'superseded',
            default => 'empty',
        };
    }
}
