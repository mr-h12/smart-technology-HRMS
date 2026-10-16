<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

/** Audit event names Identity writes explicitly (User, Role and UserSession are not Audited models). */
final class IdentityAuditEvents
{
    public const LOGIN_SUCCEEDED = 'LOGIN_SUCCEEDED';

    public const LOGIN_FAILED = 'LOGIN_FAILED';

    public const ACCOUNT_LOCKED = 'ACCOUNT_LOCKED';

    public const LOGIN_BLOCKED = 'LOGIN_BLOCKED';

    public const LOGOUT = 'LOGOUT';

    public const USER_CREATED = 'USER_CREATED';

    public const USER_UPDATED = 'USER_UPDATED';

    public const USER_ACTIVATED = 'USER_ACTIVATED';

    public const USER_DEACTIVATED = 'USER_DEACTIVATED';

    public const PASSWORD_SET = 'PASSWORD_SET';

    public const ADMIN_SESSION_TERMINATED = 'ADMIN_SESSION_TERMINATED';

    public const ROLE_CREATED = 'ROLE_CREATED';

    public const ROLE_UPDATED = 'ROLE_UPDATED';

    public const ROLE_PERMISSIONS_UPDATED = 'ROLE_PERMISSIONS_UPDATED';
}
