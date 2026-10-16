<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use DateTimeInterface;

/** A session expires after 8 hours without a request; exactly 8 hours is still live. */
final class IdleTimeout
{
    public const HOURS = 8;

    public static function hasExpired(DateTimeInterface $lastActivity, DateTimeInterface $now): bool
    {
        return $lastActivity->getTimestamp() < $now->getTimestamp() - self::HOURS * 3600;
    }
}
