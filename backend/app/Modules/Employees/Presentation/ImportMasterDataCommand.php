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
        // Opened before the import: a commit whose ref→code mapping cannot be saved must not happen.
        $reportPath = $text($this->option('report'));
        $report = $reportPath === '' ? null : $this->openReport($reportPath);
        if ($reportPath !== '' && $report === null) {
            return $this->refuse('The report file cannot be created at that path.');
        }
        Auth::setUser($actor);   // the audit log and created_by stamp this account

        try {
            return $this->import($import, $file, $from, $report);
        } finally {
            if ($report !== null) {
                fclose($report);
            }
        }
    }

    /** @param  resource|null  $report */
    private function import(MasterDataImport $import, string $file, string $from, $report): int
    {
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

        if ($report !== null) {
            fputcsv($report, ['ref', 'code'], escape: '');
            foreach ($result['codes'] as $c) {
                fputcsv($report, [$c['ref'], $c['code']], escape: '');
            }
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

    /** @return resource|null the report, emptied and readable by its owner only; null when it cannot be created */
    private function openReport(string $path)
    {
        // @: a missing directory is answered by the null, not by an ErrorException.
        if (! @touch($path) || ! @chmod($path, 0600)) {   // the mode is set before any content is written
            return null;
        }
        $out = @fopen($path, 'w');

        return $out === false ? null : $out;
    }
}
