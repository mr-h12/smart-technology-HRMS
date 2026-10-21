<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\MasterDataImport;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/** How the company's master data reaches an empty server: a dry run first, then the same file again with --commit. */
final class ImportMasterDataCommand extends Command
{
    protected $signature = 'hrms:import-master
        {file : the reviewed staging workbook (master-data.xlsx)}
        {--as= : email of the acting HR account}
        {--from= : Y-m-d, the effective date of every type, pay, rule and fare row}
        {--commit : save the result; without it nothing is saved}
        {--report= : CSV path for ref,code}';

    protected $description = 'Import employees, sites, residences and fares into an empty system (a dry run unless --commit)';

    public function handle(MasterDataImport $import, AuthorizeAction $authorize): int
    {
        $text = fn (mixed $v): string => is_string($v) ? $v : '';
        $file = $text($this->argument('file'));
        $from = $text($this->option('from'));
        if (! is_file($file) || ! is_readable($file)) {
            return $this->refuse('The file does not exist or cannot be read.');
        }
        if (Validator::make(['from' => $from], ['from' => ['required', 'date_format:Y-m-d']])->fails()) {
            return $this->refuse('--from must be a Y-m-d date.');
        }
        $actor = $import->actor($text($this->option('as')));
        if ($actor === null) {
            return $this->refuse('--as must be the email of an active account.');
        }
        foreach (MasterDataImport::ABILITIES as $ability) {
            if (! $authorize->decide($actor, $ability)->granted()) {
                return $this->refuse("That account lacks the permission {$ability}.");
            }
        }
        Auth::setUser($actor);   // the audit log and created_by stamp this account

        $commit = (bool) $this->option('commit');
        try {
            $result = $import->run($file, $from, $commit);
        } catch (DomainException $e) {
            return $this->refuse($e->getMessage());
        }

        $this->table(['Created', 'Rows'], array_map(null, array_keys($result['counts']), array_values($result['counts'])));
        foreach ($result['errors'] as $e) {
            $this->error("{$e['sheet']} row {$e['row']} {$e['field']}: {$e['message']}");
        }
        if ($result['errors'] !== []) {
            return $this->refuse(count($result['errors']).' errors.');
        }

        $report = $text($this->option('report'));
        if ($report !== '') {
            $this->writeReport($report, $result['codes']);
        }
        $this->info($commit ? 'COMMITTED' : 'DRY RUN: nothing was saved');

        return self::SUCCESS;
    }

    private function refuse(string $why): int
    {
        $this->error($why);
        $this->error('FAILED: nothing was saved');

        return self::FAILURE;
    }

    /** @param  list<array{ref: string, code: int}>  $codes */
    private function writeReport(string $path, array $codes): void
    {
        touch($path);
        chmod($path, 0600);   // before any content is written: only the owner reads it
        $out = fopen($path, 'w');
        if ($out === false) {
            $this->error('The report could not be written.');

            return;
        }
        fputcsv($out, ['ref', 'code'], escape: '');
        foreach ($codes as $c) {
            fputcsv($out, [$c['ref'], $c['code']], escape: '');
        }
        fclose($out);
    }
}
