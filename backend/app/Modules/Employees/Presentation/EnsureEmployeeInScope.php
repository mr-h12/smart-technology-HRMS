<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Domain\EmployeeArchived;
use App\Modules\Identity\Presentation\RequestScope;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `employee.scope` after `permission:`: an own-scope user reaches only employees in their reach on the route's date (else today); 404 so ids are not confirmed.
 * Then a write (any unsafe method) to an archived employee is refused with `employee_archived` (owner, 2026-11-11), unless the route
 * says `employee.scope:archived_ok` because it judges an archived employee itself. A GET loads nothing.
 */
final class EnsureEmployeeInScope
{
    public function __construct(private readonly EmployeeService $employees) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, ?string $archived = null): Response
    {
        $employeeId = (string) $request->route('employee');
        $ownerId = RequestScope::ownerId($request);
        if ($ownerId !== null && ! $this->employees->isManagedBy($employeeId, $ownerId, self::date($request))) {
            throw new ModelNotFoundException;
        }
        if (! $request->isMethodSafe() && $archived !== 'archived_ok' && $this->employees->isArchived($employeeId)) {
            throw new EmployeeArchived;
        }

        return $next($request);
    }

    /** The route only checks the date's shape; an impossible one (2026-02-30) reaches as today, and the controller refuses it. */
    private static function date(Request $request): ?string
    {
        $date = $request->route('date');
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
    }
}
