<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use DateTimeInterface;

/** A session expires after 8 hours without a request; exactly 8 hours is still live. */
final class IdleTimeout
{
    public const HOURS = 8;

    /** A request writes last_activity_at only when it is older than this, so a session may expire up to a minute early. */
    public const TOUCH_AFTER_SECONDS = 60;

    public static function hasExpired(DateTimeInterface $lastActivity, DateTimeInterface $now): bool
    {
        return $lastActivity->getTimestamp() < $now->getTimestamp() - self::HOURS * 3600;
    }

    public static function needsTouch(DateTimeInterface $lastActivity, DateTimeInterface $now): bool
    {
        return $lastActivity->getTimestamp() < $now->getTimestamp() - self::TOUCH_AFTER_SECONDS;
    }
}
