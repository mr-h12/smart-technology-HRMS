<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Presentation\RequestScope;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `employee.scope` after `permission:`: an own-scope user reaches only employees assigned to them today; 404 so ids are not confirmed. */
final class EnsureEmployeeInScope
{
    public function __construct(private readonly EmployeeService $employees) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $ownerId = RequestScope::ownerId($request);
        if ($ownerId !== null && ! $this->employees->isManagedBy((string) $request->route('employee'), $ownerId)) {
            throw new ModelNotFoundException;
        }

        return $next($request);
    }
}
