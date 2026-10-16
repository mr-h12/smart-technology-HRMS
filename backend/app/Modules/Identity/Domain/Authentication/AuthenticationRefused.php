<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use RuntimeException;

/** Rendered into the error envelope in bootstrap/app.php from its reason. */
final class AuthenticationRefused extends RuntimeException
{
    private function __construct(public readonly RefusalReason $reason)
    {
        parent::__construct('Authentication refused: '.$reason->value);
    }

    public static function because(RefusalReason $reason): self
    {
        return new self($reason);
    }
}
