<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Blueprint macros so every business table gets the same id/audit columns and the
 * money/percentage/quantity precisions (Laravel's decimal() defaults to 8,2).
 * created_by/updated_by have no FK yet; the users table is replaced later.
 */
final class StandardColumns
{
    public static function register(): void
    {
        Blueprint::macro('standardId', function (): void {
            /** @var Blueprint $this */
            $this->uuid('id')->primary();
        });

        Blueprint::macro('standardAudit', function (): void {
            /** @var Blueprint $this */
            $this->uuid('created_by')->nullable()->index();
            $this->uuid('updated_by')->nullable();
            $this->timestampsTz();
            $this->softDeletesTz();
        });

        Blueprint::macro('standardColumns', function (): void {
            /** @var Blueprint $this */
            $this->standardId();
            $this->standardAudit();
        });

        Blueprint::macro('money', function (string $column, bool $nullable = false) {
            /** @var Blueprint $this */
            $col = $this->decimal($column, Precision::MONEY_TOTAL, Precision::MONEY_SCALE);

            return $nullable ? $col->nullable() : $col;
        });

        Blueprint::macro('percentage', function (string $column, bool $nullable = false) {
            /** @var Blueprint $this */
            $col = $this->decimal($column, Precision::PERCENT_TOTAL, Precision::PERCENT_SCALE);

            return $nullable ? $col->nullable() : $col;
        });

        Blueprint::macro('quantity', function (string $column, bool $nullable = false) {
            /** @var Blueprint $this */
            $col = $this->decimal($column, Precision::QUANTITY_TOTAL, Precision::QUANTITY_SCALE);

            return $nullable ? $col->nullable() : $col;
        });
    }
}
