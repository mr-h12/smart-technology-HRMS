<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Presentation\Console;

use App\Modules\Payroll\Domain\Component;
use App\Support\Money\Decimal;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The October 2026 Excel-difference report the client signs (design 2026-11-09, question 13), in Arabic. Its data are the B9 fixtures
 * (the Excel's values) and expected-differences.json, which PayrollOctoberTest proves equal to the system's results, so no database
 * is read: system = Excel + the differences. Labels only; --names writes the client copy with names beside the (private) name map.
 */
final class ExcelDifferencesCommand extends Command
{
    protected $signature = 'payroll:excel-differences
        {--names= : the label → name map (JSON {people, sites}) outside the repository; writes the client copy, with names, beside it}';

    protected $description = 'Print the October 2026 Excel-difference report (labels only), or write the client copy with names';

    private const FIXTURES = 'tests/Fixtures/payroll/2026-10/';

    private const WORKBOOKS = [
        'field' => 'المهندسون والعمال المثبتون (26 سبتمبر – 25 أكتوبر 2026)',
        'day-laborers' => 'عمال اليومية (1 – 15 أكتوبر 2026)',
        'office' => 'الموظفون المثبتون بالشركة (أكتوبر 2026)',
    ];

    /** The Excel's columns as the report names them; `extra` is the field المجمع D (OT + outside money). */
    private const COMPONENTS = [
        'salary' => 'المرتب', 'extra' => 'الإضافي', 'lateness' => 'التأخير', 'absence' => 'الغياب', 'transport' => 'المواصلات',
        'wages' => 'اليومية', 'allowance' => 'البدل', 'net' => 'الصافي',
    ];

    /** Each reason code: its title and what moves the money (docs/excel-system-analysis.md §6, docs/calculations.md). */
    private const REASONS = [
        'excel_6_bonus_in_minutes' => ['مكافأة في عمود الدقائق (خطأ الإكسل 6)', 'مبلغ مكافأة مكتوب في عمود دقائق خارج الإسكندرية في صف بلا تاريخ، فحسبه الإكسل دقائق بسعر الدقيقة. النظام لا يحتسبه.'],
        'excel_7_pasted_values' => ['قيم مكتوبة بدل المعادلة (خطأ الإكسل 7)', 'خلايا مال اليوم (J) قيم مكتوبة مقرّبة بدل المعادلة؛ النظام يحسب من الدقائق.'],
        'grace_field' => ['سماح 15 دقيقة للتأخير (§0a)', 'النظام يخصم التأخير بعد سماح 15 دقيقة في اليوم لكل الأنواع (قرار 2026-11-03)؛ الإكسل يخصمه من أول دقيقة.'],
        'ot_shift_end' => ['الإضافي من نهاية الموعد (سؤال 20)', 'النظام يحسب الإضافي من الانصراف بعد نهاية الموعد (المالك، 2026-11-09)؛ الإكسل يحسبه ساعات العمل ناقص 8 ساعات.'],
        'absence_monthly_field' => ['خصم الغياب (R21)', 'النظام يخصم يوم أجر عن كل يوم غياب (غ) للمهندسين والعمال المثبتين (المالك، 2026-11-09)؛ عمود الغياب في المجمع صفر.'],
        'transport_permanent' => ['مواصلات العمال المثبتين (§3)', 'النظام يصرف الأجرة كاملة أو نصفها عن كل يوم عمل بموقع؛ الإكسل يكتب مبلغًا يوميًا بجوار الجدول ولا يضيفه إلى الصافي. الأجرة لكل عامل وموقع هي المبلغ الأكثر تكرارًا في ذلك العمود.'],
        'per_worker_table' => ['تسعير من جدول العامل (§8)', 'النظام يحمل يومية واحدة لكل عامل وقاعدة بدل واحدة لكل موقع؛ الإكسل سعّر هذا اليوم بغيرهما من جداول المواصلات.'],
        'excel_filtered_row' => ['صف يخفيه فلتر المجمع (خطأ الإكسل 17)', 'إجمالي المجمع SUBTOTAL(9) لا يعدّ الصفوف التي يخفيها الفلتر، والنظام يصرف صاحب الصف. (حكم مؤقت حتى يؤكد المالك أنه صُرف.)'],
        'excel_18_lateness_twice' => ['التأخير مخصوم مرتين (خطأ الإكسل 18)', 'عمود O في ورقة العامل يطرح غرامة التأخير L، ثم يطرحها المجمع مرة ثانية في عمود التأخير.'],
        'excel_19_next_row' => ['معادلة تقرأ الصف التالي (خطأ الإكسل 19)', 'عمود O في ورقة العامل يجمع مبلغ خارج الإسكندرية من الصف التالي، فيُحسب المبلغ مرتين.'],
        'excel_20_undated_row' => ['صف بلا تاريخ (خطأ الإكسل 20)', 'صف بعد آخر يوم في الفترة يحمل مبلغًا يدخل في مجموع الإضافي؛ لا يوم في النظام يقابله.'],
        'excel_21_no_site_day' => ['يوم بلا موقع (خطأ الإكسل 21)', 'الإكسل يصرف إضافيًا أو مبلغ خارج الإسكندرية، أو يخصم تأخيرًا، عن يوم خانة موقعه فارغة (الملف حُفظ في منتصف الفترة). النظام لا يسجل يوم عمل بلا موقع: اليوم ناقص، يُصرف أجره ضمن المرتب بلا إضافي حتى تسجّل الموارد البشرية موقعه.'],
        'excel_22_empty_out_time' => ['خانة انصراف فارغة (خطأ الإكسل 22)', 'معادلة ساعات العمل في ورقة العامل تعدّ الانصراف الفارغ منتصف الليل، فتعطي حضور 08:00 ست عشرة ساعة عمل منها 8 ساعات إضافي.'],
        'excel_23_shifted_sheet' => ['ورقة مزاحة (خطأ الإكسل 23)', 'المجمع يقرأ خلية فارغة لأن أعمدة ورقة العامل مزاحة، فيصرف 0 عن أيام مسجلة.'],
        'excel_24_previous_period_sheet' => ['ورقة الفترة السابقة (خطأ الإكسل 24)', 'ورقة العامل ما زالت تحمل أيام الفترة السابقة، وصرفها المجمع في هذه الفترة.'],
        'money_six_decimals' => ['المبالغ بست خانات عشرية', 'النظام يخزن كل مبلغ بست خانات عشرية (NUMERIC(18,6))؛ المبلغ في الإكسل معادلة بكسور أطول.'],
    ];

    public function handle(): int
    {
        $report = self::render();
        $map = $this->option('names');
        if (! is_string($map) || $map === '') {
            $this->output->write($report);

            return self::SUCCESS;
        }

        $dir = realpath(dirname($map));
        $repo = (string) realpath(base_path('..'));
        if (! is_file($map) || $dir === false || str_starts_with($dir.'/', $repo.'/')) {
            $this->error('The name map must be a readable file outside the repository: the client copy, with names, is written beside it.');

            return self::FAILURE;
        }
        $names = json_decode((string) file_get_contents($map), true, flags: JSON_THROW_ON_ERROR);
        $copy = (string) preg_replace_callback('/(?<![\w-])(Engineer|Worker|Person|Site)-\d+(?!\d)/', function (array $m) use ($names): string {
            $name = $names[$m[1] === 'Site' ? 'sites' : 'people'][$m[0]] ?? '';

            return is_string($name) && $name !== '' ? $name : $m[0];
        }, $report);
        $out = $dir.'/2026-10-excel-differences-client.md';
        file_put_contents($out, $copy);
        $this->info("Wrote the client copy to {$out}.");

        return self::SUCCESS;
    }

    /** The report: per workbook the totals (Excel, Σ differences by reason, system), then one row per difference; then the reasons. */
    public static function render(): string
    {
        $differences = self::json('expected-differences');
        $md = ['# فروق مرتبات أكتوبر 2026 بين النظام وملفات الإكسل', '',
            'كل فرق بين ما يحسبه النظام وما صرفه الإكسل لكل شخص، بسببه. «الفرق» هو أثره على الصافي: النظام ناقص الإكسل. الأسماء رموز (Engineer-N، Worker-N، Person-N، Site-N)؛ نسخة العميل بالأسماء خارج المستودع.',
            '', 'يُولَّد من ملفات `backend/tests/Fixtures/payroll/2026-10/` بالأمر `php artisan payroll:excel-differences > docs/reports/2026-10-excel-differences.md`، ويثبت `PayrollOctoberTest` أن النظام يعطي هذه الفروق بالضبط.', ''];
        $used = [];
        foreach (self::WORKBOOKS as $file => $title) {
            $f = self::json($file);
            $people = array_column($f['people'], null, 'label');
            $rows = array_values(array_filter($differences, fn (array $d) => isset($people[$d['label']])));

            $excelTotal = '0';
            foreach ($people as $p) {
                if ($p['in_excel_total'] ?? true) {
                    $excelTotal = bcadd($excelTotal, self::six($p['excel']['net'] ?? null), 6);
                }
            }
            $byReason = [];
            $byComponent = [];
            foreach ($rows as $d) {
                $amount = Decimal::of($d['amount'], 'amount');
                $byReason[$d['reason']] = [($byReason[$d['reason']][0] ?? 0) + 1, bcadd($byReason[$d['reason']][1] ?? '0', $amount, 6)];
                $key = "{$d['label']}|{$d['component']}";
                $byComponent[$key] = bcadd($byComponent[$key] ?? '0', $amount, 6);
                $used[$d['reason']] = true;
            }
            $systemTotal = array_reduce(array_column($byReason, 1), fn (string $t, string $a) => bcadd($t, $a, 6), $excelTotal);

            array_push($md, "## {$title}", '', "الملف: {$f['workbook']}", '', '| | عدد الفروق | المبلغ |', '|---|---:|---:|',
                "| إجمالي الإكسل (الخلية {$f['excel_total']['cell']}) | | ".self::money($excelTotal).' |');
            foreach ($byReason as $reason => [$count, $sum]) {
                $md[] = '| '.self::reason($reason)[0]." | {$count} | ".self::difference($sum).' |';
            }
            array_push($md, '| **إجمالي النظام** | | **'.self::money($systemTotal).'** |', '');
            if ($rows === []) {
                array_push($md, 'لا فروق: النظام يعطي ما صرفه الإكسل لكل شخص.', '');

                continue;
            }
            array_push($md, '| الشخص | البند | الإكسل | النظام | الفرق | السبب |', '|---|---|---:|---:|---:|---|');
            foreach ($rows as $d) {
                [$excel, $system] = self::values($people[$d['label']], $d['component'], $byComponent["{$d['label']}|{$d['component']}"]);
                $md[] = "| {$d['label']} | ".(self::COMPONENTS[$d['component']] ?? $d['component']).' | '.self::money($excel).' | '.self::money($system)
                    .' | '.self::difference(Decimal::of($d['amount'], 'amount')).' | '.self::reason($d['reason'])[0].': '.$d['note'].' |';
            }
            $md[] = '';
        }

        array_push($md, '## الأسباب', '');
        foreach (array_keys($used) as $reason) {
            [$title, $why] = self::reason($reason);
            $md[] = "- **{$title}** (`{$reason}`): {$why}";
        }

        return implode("\n", $md)."\n";
    }

    /**
     * The Excel's value of the person's component and the system's (= Excel + the differences, by the component's sign in the net).
     * A row the AutoFilter hides counts 0 in the Excel total; the system pays its net.
     *
     * @param  array<string, mixed>  $person
     * @param  numeric-string  $difference
     * @return array{numeric-string, numeric-string}
     */
    private static function values(array $person, string $component, string $difference): array
    {
        if ($component === 'net') {
            return ['0', $difference];
        }
        $excel = self::six($person['excel'][$component] ?? null);
        $sign = $component === 'extra' ? 1 : Component::from($component)->sign();

        return [$excel, bcadd($excel, bcmul((string) $sign, $difference, 6), 6)];
    }

    /** @return array{string, string} */
    private static function reason(string $code): array
    {
        return self::REASONS[$code] ?? throw new RuntimeException("No Arabic text for the reason {$code}.");
    }

    /**
     * 2 decimals, half-up, with thousands separators: the display rule for money.
     *
     * @param  numeric-string  $x
     */
    private static function money(string $x): string
    {
        return self::grouped(bcadd(bcround($x, 2), '0', 2));
    }

    /**
     * A difference as it is: 2 decimals, or up to 6 when it has them (a difference below a piastre still shows).
     *
     * @param  numeric-string  $x
     */
    private static function difference(string $x): string
    {
        $six = bcadd($x, '0', 6);
        $decimals = max(2, strlen(rtrim(explode('.', $six)[1], '0')));

        return self::grouped(bcadd($six, '0', $decimals));
    }

    private static function grouped(string $x): string
    {
        [$int, $dec] = explode('.', ltrim($x, '-'));
        $sign = str_starts_with($x, '-') && trim($x, '-0.') !== '' ? '-' : '';

        return $sign.strrev(implode(',', str_split(strrev($int), 3))).'.'.$dec;
    }

    /** @return numeric-string an Excel amount at 6 decimals, half-up (its float noise dropped); blank is 0 */
    private static function six(?string $v): string
    {
        return bcadd(bcround(Decimal::of($v ?? '0', 'amount'), 6), '0', 6);
    }

    /** @return array<mixed> */
    private static function json(string $name): array
    {
        return json_decode((string) file_get_contents(base_path(self::FIXTURES."{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }
}
