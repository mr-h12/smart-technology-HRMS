<?php

declare(strict_types=1);

/*
 * One-off (payroll Task B9): reads the client's October 2026 payroll workbooks, as the formula results Excel cached when it
 * last saved them, into the anonymized fixtures of the October regression, and writes the label → name map (people and work
 * sites) outside the repository. The JSON carries labels only: Engineer-N, Worker-N (permanent workers, then day laborers),
 * Person-N (office), Site-N (work sites; leave day-types keep their name and carry their leave code).
 *
 *   php scripts/payroll-october-fixtures.php <workbook dir> <out dir> <name-map path>
 *
 * Exits 1 when Σ excel.net over the rows each المجمع total counts, rounded to the pound, is not the regression total
 * (CLAUDE.md: 315,451 field, 391,948 day laborers). The layouts and the known Excel errors: docs/excel-system-analysis.md.
 */

require __DIR__.'/../backend/vendor/autoload.php';

use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

ini_set('memory_limit', '2G');
ini_set('serialize_precision', '-1');   // var_export() prints the shortest decimal that reads back as the same double

const FIELD_BOOK = 'مهندسين و عمال مثبتين بالشركة شهر 10.xlsx';
const DAY_BOOK = 'مرتبات العمال باليومية من 1-10-2026 حتي 15-10-2026.xlsx';
const OFFICE_BOOK = 'مرتبات الموظفين مثبتين شركة شهر10.xlsx';
const SUMMARY = 'المجمع';
const TARGETS = ['field' => '315451', 'day_laborer' => '391948'];

[, $dir, $outDir, $mapPath] = $argv + [null, null, null, null];
if ($mapPath === null) {
    fwrite(STDERR, "usage: php scripts/payroll-october-fixtures.php <workbook dir> <out dir> <name-map path>\n");
    exit(2);
}

$names = [];       // person label => real name, for the map only
$siteNames = [];   // typed work-site name => Site-N, in order of first appearance (siteLabel), shared by both field fixtures
$field = field("$dir/".FIELD_BOOK, $names);
$workers = count(array_filter($field['people'], fn (array $p) => $p['type'] === 'permanent_worker'));
$dayLaborers = dayLaborers("$dir/".DAY_BOOK, $workers, $field['sites'], $names);
$office = office("$dir/".OFFICE_BOOK, $names);

@mkdir($outDir, 0777, true);
$json = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
file_put_contents("$outDir/field.json", json_encode($field, $json | JSON_PRETTY_PRINT)."\n");
file_put_contents("$outDir/day-laborers.json", json_encode($dayLaborers, $json)."\n");   // compact: 240 people, ≈ 1 MB
file_put_contents("$outDir/office.json", json_encode($office, $json | JSON_PRETTY_PRINT)."\n");
file_put_contents($mapPath, json_encode(['people' => $names, 'sites' => array_flip($siteNames)], $json | JSON_PRETTY_PRINT)."\n");
chmod($mapPath, 0600);

// Step 2: Σ excel.net over the rows المجمع's SUBTOTAL counts (its AutoFilter hides the others), rounded to the pound as
// المجمع shows it, against the regression totals; Σ over every row is printed beside it.
$failed = false;
foreach (['field' => $field, 'day_laborer' => $dayLaborers] as $group => $fixture) {
    $all = sum(array_column(array_column($fixture['people'], 'excel'), 'net'));
    $counted = sum(array_column(array_column(array_filter($fixture['people'], fn (array $p) => $p['in_excel_total']), 'excel'), 'net'));
    $ok = bcround($counted, 0) === TARGETS[$group];
    $failed = $failed || ! $ok;
    printf("%s: Σ excel.net over the rows %s!%s counts %s (rounded %s), target %s → %s; over all %d rows %s\n", $group, SUMMARY,
        $fixture['excel_total']['cell'], $counted, bcround($counted, 0), TARGETS[$group], $ok ? 'OK' : 'DIFFERS', count($fixture['people']), $all);
}
exit($failed ? 1 : 0);

// ---------------------------------------------------------------------------------------------------------------------

/**
 * The engineers and permanent workers of the 26 Sep → 25 Oct sheet: one person per المجمع row, read from the sheet its
 * الاساسي (C) cell reads. Labels follow the workbook's sheet order (Engineer-1 is the template sheet, analysis §3.2).
 *
 * @param  array<string, ?string>  $names
 */
function field(string $path, array &$names): array
{
    $book = book($path);
    $sum = $book[SUMMARY];
    $hidden = hiddenRows($path, SUMMARY);
    $own = [];
    foreach ($sum['rows'] as $r => $cells) {
        if ($r >= 5 && is_string($cells['B'][0] ?? null) && ($ref = refs($cells['C'][1] ?? null)) !== []) {
            $own[$r] = $ref[0][0];
        }
    }

    $order = array_flip(array_keys($book));
    $bySheet = $own;
    uasort($bySheet, fn (string $a, string $b) => $order[$a] <=> $order[$b]);
    $labels = [];
    $n = ['engineer' => 0, 'permanent_worker' => 0];
    foreach ($bySheet as $sheet) {
        $type = isset(headers($book[$sheet], 'التاريخ')[1]['in_actual']) ? 'permanent_worker' : 'engineer';
        $labels[$sheet] = [($type === 'engineer' ? 'Engineer-' : 'Worker-').++$n[$type], $type];
    }

    $people = [];
    $sites = [];
    foreach ($own as $r => $sheet) {
        [$label, $type] = $labels[$sheet];
        $names[$label] = trim($sum['rows'][$r]['B'][0]);
        [$days, $undated] = fieldDays($book[$sheet], $type === 'permanent_worker', $label);
        // الخصم (F) is HR's input on the person's sheet; one sheet labels it social insurance (analysis §3.4).
        [$entryKey, $entryValue] = entryFrom($book, $sheet, $sum['rows'][$r]['F'] ?? [null, null], 'deduction');
        $people[] = [
            'label' => $label,
            'type' => $type,
            'salary' => dec($sum['rows'][$r]['C'][0] ?? null),
            'raise' => null,
            'insured_basic' => null,
            'excel_row' => $r,
            'in_excel_total' => ! in_array($r, $hidden, true),
            'entry' => [$entryKey => $entryValue],
            'days' => $days,
            'undated_days' => $undated,
            'excel' => excelRow($sum['rows'][$r], ['C' => 'salary', 'D' => 'extra', 'E' => 'advances', 'F' => $entryKey, 'G' => 'lateness', 'H' => 'absence', 'I' => 'net']),
            'excel_other_sheet' => otherSheet($sum['rows'][$r], $sheet, ['C' => 'salary', 'D' => 'extra', 'E' => 'advances', 'F' => $entryKey, 'G' => 'lateness', 'H' => 'absence'], $labels),
        ];
        // A work site is outside Alexandria when a dated day there carries outside-Alexandria minutes or money: the attendance
        // rules accept those fields only at such a site (DayRules). A leave day-type never is one.
        foreach ([...$days, ...$undated] as $d) {
            if ($d['site'] !== null) {
                $outside = isset($d['date']) && $d['date'] !== null && (($d['outside_minutes'] ?? 0) > 0 || bccomp($d['outside_amount'] ?? '0', '0', 12) > 0);
                $sites[$d['site']] = ($sites[$d['site']] ?? false) || $outside;
                if ($outside && leaveCode($d['site']) !== null) {
                    echo "$label: row {$d['row']} has outside-Alexandria minutes or money on a leave day\n";
                }
            }
        }
        check($label, 'extra', $people[array_key_last($people)]['excel']['extra'], array_column(array_column([...$days, ...$undated], 'excel'), 'extra'));
        check($label, 'advances', $people[array_key_last($people)]['excel']['advances'], array_column([...$days, ...$undated], 'advance'));
        if ($type === 'permanent_worker') {
            check($label, 'lateness', $people[array_key_last($people)]['excel']['lateness'], array_column(array_column([...$days, ...$undated], 'excel'), 'lateness'));
        }
    }
    foreach (array_diff(array_keys($book), $own) as $sheet) {
        if (headers($book[$sheet], 'التاريخ') !== [null, []]) {
            printf("field: sheet #%d (%s) has days but no المجمع row; not extracted\n", $order[$sheet] + 1, $book[$sheet]['visible'] ? 'visible' : 'hidden');
        }
    }

    ksort($sites, SORT_NATURAL);

    return [
        'workbook' => FIELD_BOOK,
        'period' => ['group' => 'field', 'start' => '2026-09-26', 'end' => '2026-10-25'],
        'excel_total' => ['cell' => 'I27', 'net' => dec($sum['rows'][27]['I'][0])],
        'sites' => array_map(fn (string $s, bool $outside) => site($s, $outside, ['mode' => 'none', 'value' => '0']), array_keys($sites), $sites),
        'people' => $people,
    ];
}

/**
 * One field sheet's day rows (header row: A = التاريخ). Engineer template (analysis §3.2): in B, out D, minutes typed. Worker
 * template (§3.3): scheduled and actual times; late and OT minutes are the sheet's own results (late hours K, OT hours M).
 *
 * @return array{list<array>, list<array>} dated days in the period, and rows with no date that carry a site or an amount
 */
function fieldDays(array $sheet, bool $worker, string $label): array
{
    [$hr, $c] = headers($sheet, 'التاريخ');
    $transport = $worker ? transportColumn($sheet, $hr) : null;
    $days = $undated = [];
    $notTimes = 0;
    foreach ($sheet['rows'] as $r => $cells) {
        if ($r <= $hr) {
            continue;
        }
        if (arKey(text($cells['A'][0] ?? null)) === 'الاجمالي') {
            break;
        }
        $at = fn (string $role) => isset($c[$role]) ? ($cells[$c[$role]][0] ?? null) : null;
        foreach (['in', 'out', 'in_actual', 'out_actual'] as $t) {
            $notTimes += (int) ($at($t) !== null && ! $at($t) instanceof DateTimeImmutable);
        }
        $day = [
            'row' => $r,
            'date' => dateOf($cells['A'][0] ?? null),
            'site' => siteLabel($at('site')),
            'in' => hm($worker ? $at('in_actual') : $at('in')),
            'out' => hm($worker ? $at('out_actual') : $at('out')),
            'scheduled_in' => $worker ? hm($at('in')) : null,
            'scheduled_out' => $worker ? hm($at('out')) : null,
            'late' => minutes($at('late')),
            'ot' => minutes($at('ot')),
            'outside_minutes' => $worker ? null : minutes($at('outside')),
            'outside_amount' => $worker ? dec($at('outside')) : null,
            'transport' => $transport === null ? null : dec($cells[$transport][0] ?? null),
            'advance' => dec($at('advance')),
            'excel' => $worker ? ['extra' => dec($at('extra')), 'lateness' => dec($at('late_money'))] : ['extra' => dec($at('extra'))],
        ];
        place($day, $days, $undated, '2026-09-26', '2026-10-25');
    }
    if ($notTimes > 0) {
        echo "$label: $notTimes in/out cells hold a typed number, not a time; read as null\n";
    }

    return [$days, $undated];
}

/**
 * The day laborers of 1–15 Oct: one person per المجمع row 5–244 (row 245 holds the totals), read from the sheet its اليومية
 * (E) cell reads. Each is Worker-N after the field's permanent workers, in row order.
 *
 * @param  list<array>  $fieldSites  field.json's sites
 * @param  array<string, ?string>  $names
 */
function dayLaborers(string $path, int $after, array $fieldSites, array &$names): array
{
    $book = book($path);
    $sum = $book[SUMMARY];
    $hidden = hiddenRows($path, SUMMARY);
    $components = ['E' => 'wages', 'F' => 'allowance', 'G' => 'transport', 'H' => 'overtime', 'I' => 'bonus', 'J' => 'gross', 'K' => 'deduction', 'L' => 'advances', 'M' => 'lateness', 'N' => 'net'];
    $own = $rowLabel = $labels = [];
    for ($r = 5; arKey(text($sum['rows'][$r]['A'][0] ?? null)) !== 'الاجمالي'; $r++) {
        foreach (['E', 'F', 'H', 'K', 'L', 'M'] as $col) {
            if (($ref = refs($sum['rows'][$r][$col][1] ?? null)) !== []) {
                $own[$r] = $ref[0][0];
                break;
            }
        }
        $own[$r] ??= null;   // row 119: every cell is #REF! (analysis §6 #14), so it has no sheet
        $rowLabel[$r] = 'Worker-'.($after + count($rowLabel) + 1);
        if ($own[$r] !== null && isset($labels[$own[$r]])) {
            echo "$rowLabel[$r]: reads the same sheet as {$labels[$own[$r]][0]}\n";
        }
        $labels[$own[$r] ?? "#$r"] ??= [$rowLabel[$r], 'day_laborer'];
        $names[$rowLabel[$r]] = is_string($sum['rows'][$r]['C'][0] ?? null) ? trim($sum['rows'][$r]['C'][0]) : null;
    }
    $totalRow = $r;

    $people = [];
    $priced = [];   // site => allowance rule => worker-days, over the October days the Excel priced (wage > 0)
    foreach ($own as $r => $sheet) {
        [$days, $undated] = $sheet === null ? [[], []] : laborerDays($book[$sheet], $rowLabel[$r]);
        // One daily wage per worker: the wage on most of their priced October days (design, same section as the sites).
        $wages = array_count_values(array_filter(array_column(array_column($days, 'excel'), 'wages'), fn (?string $w) => $w !== null && bccomp($w, '0', 12) > 0));
        arsort($wages);   // stable: on a tie the wage seen first wins
        if (count($wages) > 1 && array_values($wages)[0] === array_values($wages)[1]) {
            echo $rowLabel[$r].': two daily wages on the same number of days; took '.array_key_first($wages)."\n";
        }
        foreach ($days as $d) {
            if ($d['site'] !== null && bccomp($d['excel']['wages'] ?? '0', '0', 12) > 0) {
                $a = $d['excel']['allowance'] ?? '0';
                $rules = bccomp($a, '0', 12) === 0 ? ['none:0'] : ['share_of_wage:'.trimZeros(bcdiv($a, $d['excel']['wages'], 12)), "fixed:$a"];
                foreach ($rules as $rule) {
                    $priced[$d['site']][$rule] = ($priced[$d['site']][$rule] ?? 0) + 1;
                }
            }
        }
        [, $deduction] = entryFrom($book, $sheet, $sum['rows'][$r]['K'] ?? [null, null], 'deduction');
        $people[] = [
            'label' => $rowLabel[$r],
            'type' => 'day_laborer',
            'daily_wage' => $wages === [] ? null : (string) array_key_first($wages),
            'raise' => null,
            'insured_basic' => null,
            'excel_row' => $r,
            'in_excel_total' => ! in_array($r, $hidden, true),
            'entry' => ['bonus' => dec($sum['rows'][$r]['I'][0] ?? null), 'deduction' => $deduction],
            'days' => $days,
            'undated_days' => $undated,
            'excel' => excelRow($sum['rows'][$r], $components),
            'excel_other_sheet' => $sheet === null ? [] : otherSheet($sum['rows'][$r], $sheet, $components, $labels),
        ];
        $p = $people[array_key_last($people)];
        foreach (['wages' => 'wages', 'allowance' => 'allowance', 'overtime' => 'overtime', 'lateness' => 'lateness'] as $component => $part) {
            check($p['label'], $component, $p['excel'][$component], array_column(array_column([...$days, ...$undated], 'excel'), $part));
        }
        check($p['label'], 'advances', $p['excel']['advances'], array_column([...$days, ...$undated], 'advance'));
    }

    $sites = [];
    foreach ($people as $p) {
        foreach ([...$p['days'], ...$p['undated_days']] as $d) {
            if ($d['site'] !== null) {
                $sites[$d['site']] = true;
            }
        }
    }
    ksort($sites, SORT_NATURAL);
    // One rule per site: the rule that prices the most of the site's priced worker-days (design, "Day-laborer wage and
    // allowance in the fixture"), a share of the wage or a fixed amount (one site pays 300 on every wage); on a tie a share
    // (analysis §8), then the shorter number (the rate tables use round factors: × 0.25, × 0.5, × 1, × 2). A site the field
    // sheet also uses keeps its outside-Alexandria flag (day laborers record no outside minutes).
    $outside = array_column($fieldSites, 'outside_alexandria', 'label');
    $sites = array_map(function (string $s) use ($priced, $outside) {
        $counts = $priced[$s] ?? ['none:0' => 0];
        $rules = array_keys($counts);
        $rank = fn (string $r) => [-$counts[$r], (int) ! str_starts_with($r, 'share'), strlen($r)];
        usort($rules, fn (string $x, string $y) => $rank($x) <=> $rank($y));
        if (count($rules) > 1 && $counts[$rules[0]] === $counts[$rules[1]]) {
            echo "site $s: allowance rules $rules[0] and $rules[1] price the same number of days; took the first\n";
        }
        [$mode, $value] = explode(':', $rules[0], 2);

        return site($s, $outside[$s] ?? false, ['mode' => $mode, 'value' => $value]);
    }, array_keys($sites));

    // What the one wage and the one site rule change: October work days the Excel priced otherwise (B10's per_worker_table).
    $bySite = array_column($sites, null, 'label');
    $count = ['other wage' => [], 'other allowance' => [], 'no wage at a work site' => []];
    foreach ($people as $p) {
        foreach ($p['days'] as $d) {
            if ($d['site'] === null || $bySite[$d['site']]['kind'] === 'leave') {
                continue;
            }
            $wage = $d['excel']['wages'] ?? '0';
            $rule = $bySite[$d['site']]['allowance'];
            $allowance = match ($rule['mode']) {
                'none' => '0',
                'fixed' => $rule['value'],
                'share_of_wage' => bcmul($wage, $rule['value'], 12),
            };
            $issue = match (true) {
                bccomp($wage, '0', 12) === 0 => 'no wage at a work site',
                $wage !== $p['daily_wage'] => 'other wage',
                bccomp($allowance, $d['excel']['allowance'] ?? '0', 6) !== 0 => 'other allowance',
                default => null,
            };
            if ($issue !== null) {
                $count[$issue][$p['label']] = ($count[$issue][$p['label']] ?? 0) + 1;
            }
        }
    }
    foreach ($count as $issue => $byLabel) {
        printf("day laborers: %d October work days with %s, %d people\n", array_sum($byLabel), $issue, count($byLabel));
    }

    return [
        'workbook' => DAY_BOOK,
        'period' => ['group' => 'day_laborer', 'start' => '2026-10-01', 'end' => '2026-10-15'],
        'excel_total' => ['cell' => "N$totalRow", 'net' => dec($sum['rows'][$totalRow]['N'][0])],
        'sites' => $sites,
        'people' => $people,
    ];
}

/**
 * One day laborer's day rows (template analysis §4.2; header row: A = اليوم). Columns are found by their header, since
 * some sheets lack البدل, المواصلات or السلف, or are shifted by one.
 *
 * @return array{list<array>, list<array>}
 */
function laborerDays(array $sheet, string $label): array
{
    [$hr, $c] = headers($sheet, 'اليوم');
    $days = $undated = [];
    foreach ($sheet['rows'] as $r => $cells) {
        if ($r <= $hr) {
            continue;
        }
        if (arKey(text($cells['A'][0] ?? null)) === 'الاجمالي') {
            break;
        }
        $at = fn (string $role) => isset($c[$role]) ? ($cells[$c[$role]][0] ?? null) : null;
        $day = [
            'row' => $r,
            'date' => dateOf($cells['A'][0] ?? null),
            'site' => siteLabel($at('site')),
            'in' => null, 'out' => null, 'scheduled_in' => null, 'scheduled_out' => null,
            'late' => minutes($at('late')),
            'ot' => minutes($at('ot')),
            'outside_minutes' => null, 'outside_amount' => null, 'transport' => null,
            'advance' => dec($at('advance')),
            'excel' => ['wages' => dec($at('wage')), 'allowance' => dec($at('allowance')), 'transport' => dec($at('transport')),
                'overtime' => dec($at('ot_money')), 'lateness' => dec($at('late_money'))],
        ];
        place($day, $days, $undated, '2026-10-01', '2026-10-15');
    }
    if (count($days) !== 15) {
        echo "$label: ".count($days)." dated days\n";
    }

    return [$days, $undated];
}

/**
 * The office staff of the October salary sheet (analysis §2.3): what the sheet holds per person, its period totals and its
 * results. It has no days: the office regression runs at engine level on these totals (design, "Office").
 *
 * @param  array<string, ?string>  $names
 */
function office(string $path, array &$names): array
{
    $sheet = array_values(book($path))[0];
    $people = [];
    foreach ($sheet['rows'] as $r => $cells) {
        $name = is_string($cells['A'][0] ?? null) ? trim($cells['A'][0]) : '';
        if ($r < 2 || $name === '' || arKey($name) === 'الاجمالي') {
            continue;
        }
        $label = 'Person-'.(count($people) + 1);
        $names[$label] = $name;
        $at = fn (string $col) => $cells[$col][0] ?? null;
        $people[] = [
            'label' => $label,
            'type' => 'office',
            'salary' => dec($at('B')),
            'raise' => dec($at('C')),
            'insured_basic' => dec($at('E')),
            'excel_row' => $r,
            // HR's inputs as the sheet holds them. late_minutes (P) and absence_days (Y) have no engine input: the engine
            // derives lateness from the days and absence from absent days.
            'entry' => [
                'late_minutes' => minutes($at('P')), 'lateness_exemption_minutes' => minutes($at('Q')), 'ot_minutes' => minutes($at('W')),
                'travel_days' => minutes($at('F')), 'tower_days' => minutes($at('H')), 'absence_days' => minutes($at('Y')),
                'bonus' => dec($at('AB')), 'negligence' => dec($at('O')), 'advances' => dec($at('M')),
                'insurance_employee' => dec($at('K')), 'income_tax' => dec($at('L')),
            ],
            'excel_note' => is_string($at('AA')) ? trim($at('AA')) : null,
            'excel' => excelRow($cells, ['B' => 'salary', 'E' => 'insured_basic', 'J' => 'incentives', 'G' => 'travel', 'I' => 'tower',
                'X' => 'overtime', 'AB' => 'bonus', 'V' => 'lateness', 'Z' => 'absence', 'O' => 'negligence', 'K' => 'insurance_employee',
                'L' => 'income_tax', 'M' => 'advances', 'AD' => 'net']),
        ];
    }

    return [
        'workbook' => OFFICE_BOOK,
        'period' => ['group' => 'office', 'start' => '2026-10-01', 'end' => '2026-10-31'],
        'excel_total' => ['cell' => 'AD29', 'net' => dec($sheet['rows'][29]['AD'][0] ?? null)],
        'people' => $people,
    ];
}

// --- reading ---------------------------------------------------------------------------------------------------------

/** @return array<string, array{visible: bool, rows: array<int, array<string, array{mixed, ?string}>>}> sheet → row → column → [value, formula] */
function book(string $path): array
{
    $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
    $reader->open($path);
    $book = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        $r = 0;
        foreach ($sheet->getRowIterator() as $row) {
            $r++;
            foreach ($row->cells as $i => $cell) {
                $formula = $cell instanceof FormulaCell ? $cell->getValue() : null;
                $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();   // an error (#REF!) reads as null
                if ($formula !== null || ($value !== null && $value !== '')) {
                    $rows[$r][letters($i)] = [$value, $formula];
                }
            }
        }
        $book[$sheet->getName()] = ['visible' => $sheet->isVisible(), 'rows' => $rows];
    }
    $reader->close();

    return $book;
}

/**
 * Rows hidden in a sheet. المجمع's totals are SUBTOTAL(9, …), which leaves out rows its AutoFilter hides; OpenSpout does not
 * read row attributes, so this reads them from the sheet's XML.
 *
 * @return list<int>
 */
function hiddenRows(string $path, string $sheetName): array
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::RDONLY);
    preg_match('/<sheet [^>]*name="'.preg_quote($sheetName, '/').'"[^>]*r:id="([^"]+)"/u', $zip->getFromName('xl/workbook.xml'), $id);
    preg_match('/<Relationship [^>]*Id="'.$id[1].'"[^>]*Target="([^"]+)"/', $zip->getFromName('xl/_rels/workbook.xml.rels'), $target);
    preg_match_all('/<row r="(\d+)"[^>]*\bhidden="1"/', $zip->getFromName('xl/'.$target[1]), $rows);
    $zip->close();

    return array_map('intval', $rows[1]);
}

/**
 * The header row (the first whose column A reads $first) and the column of each role found in it.
 *
 * @return array{?int, array<string, string>}
 */
function headers(array $sheet, string $first): array
{
    foreach ($sheet['rows'] as $r => $cells) {
        if (! is_string($cells['A'][0] ?? null) || arKey($cells['A'][0]) !== $first) {
            continue;
        }
        $c = [];
        foreach ($cells as $col => [$text]) {
            $k = str_replace(' ', '', arKey(text($text)));
            $role = match (true) {
                $k === 'حضور' => 'in',
                $k === 'حضورفعلي' => 'in_actual',
                $k === 'انصراف' => 'out',
                $k === 'انصراففعلي' => 'out_actual',
                $k === 'ملاحظات' || $k === 'المكان' => 'site',
                str_starts_with($k, 'اضافيخارجالاسكندريه') => 'outside',
                str_starts_with($k, 'التاخيربال'), str_contains($k, 'وقت') && str_contains($k, 'تاخير') => 'late',
                str_contains($k, 'تاخير') => 'late_money',
                $k === 'اضافيدقائق', $k === 'الساعاتالاضافيه', $k === 'الوقتالاضافي' => 'ot',
                $k === 'الاضافي' && isset($c['ot']) => 'ot_money',
                $k === 'السلف', $k === 'سلفه' => 'advance',
                $k === 'الاجماليبالجنيه' => 'extra',
                $k === 'اليوميه' => 'wage',
                $k === 'البدل' => 'allowance',
                $k === 'المواصلات' => 'transport',
                default => null,
            };
            if ($role !== null) {
                $c[$role] ??= $col;   // the first of two ملاحظات columns is the site
            }
        }

        return [$r, $c];
    }

    return [null, []];
}

/**
 * A worker sheet's per-day transport amount: typed in the column right after the الموقع column (the rightmost one whose
 * first day cell repeats a cell of its row, =E7). No formula reads it; its header, where there is one, does not sit above it.
 */
function transportColumn(array $sheet, int $hr): ?string
{
    $siteEcho = null;
    foreach ($sheet['rows'][$hr + 1] ?? [] as $col => [, $f]) {
        if ($f !== null && preg_match('/^=\$?[A-Z]+\$?'.($hr + 1).'$/', $f)) {
            $siteEcho = $col;
        }
    }
    if ($siteEcho === null) {
        return null;
    }
    $next = letters(index($siteEcho) + 1);
    foreach ($sheet['rows'] as $r => $cells) {
        if ($r > $hr && is_int($cells[$next][0] ?? null) && ($cells[$next][1] ?? null) === null) {
            return $next;
        }
    }

    return null;
}

/**
 * A day dated in the period goes to $days. Any other row (no date, or a date outside the period) is kept apart, with the
 * date it holds, when it has a site or an amount.
 */
function place(array $day, array &$days, array &$undated, string $start, string $end): void
{
    if ($day['date'] !== null && $day['date'] >= $start && $day['date'] <= $end) {
        $days[] = $day;

        return;
    }
    $values = array_filter([$day['late'], $day['ot'], $day['outside_minutes'], $day['outside_amount'], $day['transport'], $day['advance'], ...array_values($day['excel'])], fn ($v) => $v !== null && (string) $v !== '0');
    if ($day['site'] !== null || $values !== []) {
        $undated[] = $day;
    }
}

// --- values ----------------------------------------------------------------------------------------------------------

/** A numeric cell as a decimal string: the shortest one that reads back as the double Excel stored, exponent expanded. */
function dec(mixed $v): ?string
{
    if (is_int($v)) {
        return (string) $v;
    }
    if (! is_float($v)) {
        return null;
    }
    $s = var_export($v, true);
    if (preg_match('/^(-?)(\d)\.(\d+)E([+-]\d+)$/', $s, $m)) {
        $digits = $m[2].$m[3];
        $point = 1 + (int) $m[4];
        $s = $m[1].match (true) {
            $point <= 0 => '0.'.str_repeat('0', -$point).$digits,
            $point >= strlen($digits) => $digits.str_repeat('0', $point - strlen($digits)),
            default => substr($digits, 0, $point).'.'.substr($digits, $point),
        };
    }
    $s = trimZeros($s);

    return $s === '-0' ? '0' : $s;
}

/** Minutes (or a day count) from a typed number, a duration (late hours, analysis §3.3) or a time-formatted result (OT hours). */
function minutes(mixed $v): int|string|null
{
    if ($v instanceof DateInterval) {
        $m = ($v->invert ? -1 : 1) * ($v->h * 60 + $v->i);

        return $v->s === 0 ? $m : throw new RuntimeException('duration with seconds');
    }
    if ($v instanceof DateTimeImmutable) {
        $days = (int) (new DateTimeImmutable('1899-12-30'))->diff($v->setTime(0, 0))->format('%a');

        return $v->format('s') === '00' ? $days * 1440 + (int) $v->format('G') * 60 + (int) $v->format('i') : throw new RuntimeException('time with seconds');
    }
    if (is_float($v) && floor($v) === $v) {
        return (int) $v;
    }

    return is_int($v) ? $v : dec($v);
}

/** A time of day as HH:MM; a typed number (8 for 08:00) is not a time and reads as null. */
function hm(mixed $v): ?string
{
    if (! $v instanceof DateTimeImmutable) {
        return null;
    }
    if ($v->format('Y-m-d s') !== '1899-12-30 00') {
        throw new RuntimeException('time cell is not a time of day: '.$v->format('c'));
    }

    return $v->format('H:i');
}

/** A day cell's date. 1–12 Oct are stored as Jan–Dec 10 (analysis §6 #9) and read back as October; Excel's day 0 is no date. */
function dateOf(mixed $v): ?string
{
    if ($v instanceof DateTimeImmutable) {
        if ($v->format('Y') === '1899') {
            return null;
        }

        return $v->format('d') === '10' && $v->format('m') !== '10' ? $v->format('Y').'-10-'.$v->format('m') : $v->format('Y-m-d');
    }
    if (is_string($v) && preg_match('#^(\d{1,2})[/-](\d{1,2})(?:/(\d{4}))?$#', trim($v), $m)) {
        return sprintf('%s-%02d-%02d', ($m[3] ?? '') !== '' ? $m[3] : '2026', $m[2], $m[1]);
    }

    return null;
}

/**
 * A day's site cell, its spaces trimmed and collapsed (as the attendance import reads it, §14). A leave day-type keeps its
 * name; a work site becomes its label, Site-N in order of first appearance, one per typed spelling (Excel error #15 stays
 * visible), its name kept for the map only.
 */
function siteLabel(mixed $v): ?string
{
    $s = is_string($v) ? preg_replace('/\s+/u', ' ', trim($v)) : '';
    if ($s === '' || leaveCode($s) !== null) {
        return $s === '' ? null : $s;
    }

    return $GLOBALS['siteNames'][$s] ??= 'Site-'.(count($GLOBALS['siteNames']) + 1);
}

/** The Sites LeaveCode of a leave day-type's name; null for a work site (a Site-N label included). */
function leaveCode(string $name): ?string
{
    $k = str_replace(' ', '', arKey($name));

    return match (true) {
        $k === 'غ' => 'absent',
        str_contains($k, 'جمعه') => 'friday',
        str_contains($k, 'رسميه') => 'official_holiday',
        str_contains($k, 'مرضي') => 'sick',
        str_contains($k, 'راحه') => 'rest',
        str_contains($k, 'اجازه') => 'other',
        default => null,
    };
}

/** A sites entry; a leave day-type carries its leave code. */
function site(string $label, bool $outside, array $allowance): array
{
    $code = leaveCode($label);

    return ['label' => $label, 'kind' => $code === null ? 'work' : 'leave', 'leave_code' => $code, 'outside_alexandria' => $code === null && $outside,
        'allowance' => $code === null ? $allowance : ['mode' => 'none', 'value' => '0']];
}

/** Matching key (as the master-data import): trimmed, one space, alef/ya/ta marbuta unified, no tatweel or diacritics. */
function arKey(string $s): string
{
    $s = preg_replace(['/[\x{064B}-\x{0652}\x{0640}]/u', '/\s+/u'], ['', ' '], trim($s));

    return strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
}

// --- المجمع helpers --------------------------------------------------------------------------------------------------

/** @return list<array{string, string, int}> [sheet, column, row] of every reference in a formula */
function refs(?string $formula): array
{
    preg_match_all("/(?:'((?:[^']|'')+)'|([^!'=+\\-*\\/(),:&<>\\s]+))!\\$?([A-Z]{1,3})\\$?(\\d+)/u", (string) $formula, $m, PREG_SET_ORDER);

    return array_map(fn (array $x) => [str_replace("''", "'", $x[1] !== '' ? $x[1] : $x[2]), $x[3], (int) $x[4]], $m);
}

/** @param  array<string, string>  $components  column → component; a cell Excel holds as an error reads as null */
function excelRow(array $cells, array $components): array
{
    return array_combine(array_values($components), array_map(fn (string $col) => dec($cells[$col][0] ?? null), array_keys($components)));
}

/**
 * The components whose المجمع cell reads another sheet than the person's own (analysis §6 #5, #14), with that sheet's label.
 *
 * @param  array<string, array{string, string}>  $labels  sheet → [label, type]
 * @return array<string, string>
 */
function otherSheet(array $cells, string $own, array $components, array $labels): array
{
    $other = [];
    foreach ($components as $col => $component) {
        foreach (refs($cells[$col][1] ?? null) as [$sheet]) {
            if ($sheet !== $own) {
                $other[$component] = $labels[$sheet][0] ?? 'a sheet with no المجمع row';
            }
        }
    }

    return $other;
}

/**
 * HR's input behind an المجمع cell, read on the person's own sheet at the address the cell reads (or the المجمع value when it
 * is typed there). A row labelled social insurance (تأمينات) is the employee's insurance share, not a deduction.
 *
 * @return array{string, ?string}
 */
function entryFrom(array $book, ?string $own, array $cell, string $default): array
{
    $ref = refs($cell[1])[0] ?? null;
    if ($own === null || $ref === null) {
        return [$default, dec($cell[0])];
    }
    $sheet = $book[$own];
    $label = arKey(text($sheet['rows'][$ref[2]]['A'][0] ?? null));

    return [str_contains($label, 'تامين') ? 'insurance_employee' : $default, dec($sheet['rows'][$ref[2]][$ref[1]][0] ?? null) ?? '0'];
}

/** Prints a person whose day values do not add up to the المجمع component (a wrong reference or a cell outside the days). */
function check(string $label, string $component, ?string $excel, array $parts): void
{
    $total = sum($parts);
    if (bccomp($total, $excel ?? '0', 6) !== 0) {
        echo "$label: Σ days $component $total ≠ المجمع ".($excel ?? 'null')."\n";
    }
}

// --- small helpers ---------------------------------------------------------------------------------------------------

function text(mixed $v): string
{
    return is_string($v) ? $v : '';
}

/** @param  list<?string>  $xs */
function sum(array $xs): string
{
    return trimZeros(array_reduce($xs, fn (string $a, ?string $x) => bcadd($a, $x ?? '0', 20), '0'));
}

function trimZeros(string $s): string
{
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

function letters(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26).$s;
    }

    return $s;
}

function index(string $letters): int
{
    $i = 0;
    foreach (str_split($letters) as $ch) {
        $i = $i * 26 + ord($ch) - 64;
    }

    return $i - 1;
}
