<?php

namespace App\Security;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class SecuritySession
{
    public const STARTED_AT = 'auth_started_at';
    public const LAST_SEEN_AT = 'auth_last_seen_at';
    public const IDLE_SECONDS = 3600;
    public const ABSOLUTE_SECONDS = 28800;

    public function __construct(private ClockInterface $clock)
    {
    }

    public function start(SessionInterface $session): void
    {
        $session->migrate(true);
        $now = $this->clock->now()->getTimestamp();
        $session->set(self::STARTED_AT, $now);
        $session->set(self::LAST_SEEN_AT, $now);
    }

    public function hasExpired(SessionInterface $session): bool
    {
        $started = $session->get(self::STARTED_AT);
        $lastSeen = $session->get(self::LAST_SEEN_AT);
        $now = $this->clock->now()->getTimestamp();

        return !\is_int($started) || !\is_int($lastSeen)
            || $started > $now || $lastSeen > $now
            || $now - $started >= self::ABSOLUTE_SECONDS
            || $now - $lastSeen >= self::IDLE_SECONDS;
    }

    public function touch(SessionInterface $session): void
    {
        $session->set(self::LAST_SEEN_AT, $this->clock->now()->getTimestamp());
    }
}
