<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use DateTimeImmutable;
use DateTimeInterface;

/** A developer using the app as another account: who is really there, and until when. */
final readonly class ViewAs
{
    public const MINUTES = 30;

    /** Who may start one; a target holding it in any scope is refused. */
    public const ABILITY = 'user.impersonate';

    /** `resource.action` refused while viewing, whatever the target's role holds: no accounts, passwords, role grants or second view-as. */
    private const BLOCKED = ['user.manage', 'role.manage', self::ABILITY];

    public function __construct(public string $byId, public string $byName, public DateTimeImmutable $expiresAt) {}

    public static function expiresAt(DateTimeInterface $start): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($start)->modify('+'.self::MINUTES.' minutes');
    }

    /** Exactly MINUTES after the start is still live. */
    public function hasExpired(DateTimeInterface $now): bool
    {
        return $this->expiresAt->getTimestamp() < $now->getTimestamp();
    }

    /** @param string $triple `resource.action.scope` */
    public static function blocks(string $triple): bool
    {
        return in_array(implode('.', array_slice(explode('.', $triple), 0, 2)), self::BLOCKED, true);
    }
}
