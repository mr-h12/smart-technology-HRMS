<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/** Five consecutive failed logins lock the account for a configured number of minutes. */
final class LockoutPolicy
{
    public const MAX_ATTEMPTS = 5;

    /** Takes the failure count after the current failure is added. */
    public static function shouldLock(int $failures): bool
    {
        return $failures >= self::MAX_ATTEMPTS;
    }

    /** A lock ending exactly now is over. */
    public static function isLocked(?DateTimeInterface $until, DateTimeInterface $now): bool
    {
        return $until !== null && $until->getTimestamp() > $now->getTimestamp();
    }

    public static function lockedUntil(DateTimeInterface $now, int $minutes): DateTimeImmutable
    {
        // A zero duration would silently disable the lockout.
        if ($minutes < 1) {
            throw new InvalidArgumentException('A lockout lasts at least one minute.');
        }

        return DateTimeImmutable::createFromInterface($now)->modify("+{$minutes} minutes");
    }
}
