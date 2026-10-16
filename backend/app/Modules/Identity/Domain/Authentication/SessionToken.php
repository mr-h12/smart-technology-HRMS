<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use InvalidArgumentException;
use SensitiveParameter;

/** The bearer credential a client presents; the server stores only its sha256 fingerprint. */
final readonly class SessionToken
{
    private function __construct(public string $value) {}

    public static function issue(): self
    {
        return new self(bin2hex(random_bytes(32)));
    }

    /** The shape is checked before the value reaches a query. */
    public static function fromPresented(#[SensitiveParameter] string $presented): self
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $presented) !== 1) {
            throw new InvalidArgumentException('A session token is 64 lowercase hex characters.');
        }

        return new self($presented);
    }

    /** What user_sessions.session_id stores; never the token itself. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->value);
    }
}
