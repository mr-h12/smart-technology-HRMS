<?php

declare(strict_types=1);

namespace App\Modules\Sites\Application;

use App\Modules\Sites\Domain\AllowanceMode;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Http\Paginates;
use Illuminate\Validation\ValidationException;

/** Sites (work places and leave "sites") and their dated allowance rules. */
final class SiteService
{
    use Paginates;

    /**
     * @param  array{kind?: string, active?: string, per_page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(array $filters): array
    {
        $query = Site::query()->orderBy('name_ar');
        if (isset($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if (isset($filters['active'])) {
            $query->where('active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $this->page($query, $filters['per_page'] ?? 20);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $site = new Site($data);
        $this->assertLeaveCode($site);
        $site->save();

        return $this->show((string) $site->getKey());
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        $site = Site::with('allowanceRules')->findOrFail($id);

        return [
            ...$this->row($site),
            'allowance_rules' => $site->allowanceRules->sortBy('effective_from')->values()->map(fn ($r) => $this->row($r))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $site = Site::findOrFail($id);
        $site->fill($data);
        $this->assertLeaveCode($site);
        $site->save();

        return $this->show($id);
    }

    public function delete(string $id): void
    {
        Site::findOrFail($id)->delete();
    }

    /**
     * @param  array{effective_from: string, mode: string, value?: ?string}  $data
     * @return array<string, mixed>
     */
    public function addRule(string $id, array $data): array
    {
        $site = Site::findOrFail($id);
        if ($site->allowanceRules()->where('effective_from', $data['effective_from'])->exists()) {
            throw ValidationException::withMessages(['effective_from' => 'A rule already exists on this date.']);
        }
        $none = $data['mode'] === AllowanceMode::None->value;
        if (! $none && blank($data['value'] ?? null)) {
            throw ValidationException::withMessages(['value' => 'Value is required unless mode is none.']);
        }
        $site->allowanceRules()->create([...$data, 'value' => $none ? '0' : $data['value']]);

        return $this->show($id);
    }

    private function assertLeaveCode(Site $site): void
    {
        $leave = $site->kind === SiteKind::Leave->value;
        if ($leave && blank($site->leave_code)) {
            throw ValidationException::withMessages(['leave_code' => 'Leave code is required for leave sites.']);
        }
        if (! $leave && filled($site->leave_code)) {
            throw ValidationException::withMessages(['leave_code' => 'Leave code is only for leave sites.']);
        }
    }
}
