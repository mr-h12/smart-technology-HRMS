<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait Paginates
{
    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    private function page(Builder $query, int|string $perPage): array
    {
        // Validated query-string values arrive as strings.
        $page = $query->paginate(min(max((int) $perPage, 1), 100));

        return [
            'data' => array_values($page->getCollection()->map(fn (Model $m) => $this->row($m))->all()),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'total_pages' => $page->lastPage(),
                'has_next_page' => $page->hasMorePages(),
                'has_previous_page' => $page->currentPage() > 1,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function row(Model $m): array
    {
        return array_diff_key($m->toArray(), array_flip(['created_by', 'updated_by', 'deleted_at']));
    }
}
