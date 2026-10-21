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
        if ($reportPath !== '' && file_exists($reportPath)) {   // it may hold an earlier run's only ref→code map
            return $this->refuse('The report file already exists; give a new path.');
        }
        $report = $reportPath === '' ? null : $this->openReport($reportPath);
        if ($reportPath !== '' && $report === null) {
            return $this->refuse('The report file cannot be created at that path.');
        }
        Auth::setUser($actor);   // the audit log and created_by stamp this account

        $exit = self::FAILURE;
        try {
            return $exit = $this->import($import, $file, $from, $report);
        } finally {
            if ($report !== null) {
                $closed = fclose($report);
                if ($exit !== self::SUCCESS) {
                    @unlink($reportPath);   // a refused or failed run leaves no file, so the retry may use the same path
                } elseif (! $closed) {
                    $this->error('WARNING: the report could not be closed. If this run committed, the import IS saved: regenerate the ref,code list.');
                }
            }
        }
    }

    /** @param  resource|null  $report */
    private function import(MasterDataImport $import, string $file, string $from, $report): int
    {
        $commit = (bool) $this->option('commit');
        try {
            // The report is written and flushed before the commit: a mapping that cannot be saved stops the import.
            $result = $import->run($file, $from, $commit, $report === null ? null : fn ($codes) => $this->writeReport($report, $codes));
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

        $this->info($commit ? 'COMMITTED' : 'DRY RUN: nothing was saved');

        return self::SUCCESS;
    }

    private function refuse(string $why): int
    {
        $this->error($why);
        $this->error('FAILED: nothing was saved');

        return self::FAILURE;
    }

    /**
     * @param  resource  $report
     * @param  list<array{ref: string, code: int}>  $codes
     */
    private function writeReport($report, array $codes): void
    {
        $ok = true;
        foreach ([['ref', 'code'], ...array_map(fn (array $c) => [$c['ref'], $c['code']], $codes)] as $line) {
            $written = @fputcsv($report, $line, escape: '');
            $ok = $ok && is_int($written) && $written > 0;
        }
        if (! $ok || ! @fflush($report)) {
            throw new DomainException('The report could not be written.');
        }
    }

    /** @return resource|null a new report readable by its owner only; null when it cannot be created */
    private function openReport(string $path)
    {
        // 'x' creates or fails, so an existing file is never emptied; @: a failure is answered by the null, not an ErrorException.
        $out = @fopen($path, 'x');
        if ($out === false) {
            return null;
        }
        chmod($path, 0600);   // still empty: the mode is set before any content is written

        return $out;
    }
}
