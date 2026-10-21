<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

/** The scopes a user's role holds for one `resource.action`; none means refused. */
final readonly class PermissionDecision
{
    /** @param list<Scope> $scopes */
    public function __construct(public array $scopes) {}

    public function granted(): bool
    {
        return $this->scopes !== [];
    }

    public function allows(Scope $required): bool
    {
        foreach ($this->scopes as $held) {
            if ($held->includes($required)) {
                return true;
            }
        }

        return false;
    }
}
