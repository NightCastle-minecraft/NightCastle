<?php

declare(strict_types=1);

namespace SiteGate;

/** Лимит неудачных попыток входа по IP: не больше $max за $window секунд. */
final class RateLimit
{
    public function __construct(
        private AttemptStore $store,
        private int $max = 5,
        private int $window = 900,
    ) {
    }

    public function isBlocked(string $ip, ?int $now = null): bool
    {
        $now ??= time();
        return $this->store->countSince($ip, self::fmt($now - $this->window)) >= $this->max;
    }

    public function recordFailure(string $ip, ?int $now = null): void
    {
        $now ??= time();
        $this->store->purgeBefore(self::fmt($now - $this->window));
        $this->store->add($ip, self::fmt($now));
    }

    /** Успешный вход чистит только записи своего IP. */
    public function clear(string $ip): void
    {
        $this->store->clear($ip);
    }

    public function windowMinutes(): int
    {
        return (int) ceil($this->window / 60);
    }

    private static function fmt(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }
}
