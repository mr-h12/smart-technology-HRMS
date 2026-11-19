<?php

namespace Tests\Feature;

use App\Modules\Pdf\Domain\PdfType;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HrFormViewsTest extends TestCase
{
    /**
     * The views of the types on the page shell (layout version 2 and later); a type joins when its version moves.
     *
     * @return array<string, array{0: string}>
     */
    public static function formViews(): array
    {
        $views = [];
        foreach (PdfType::cases() as $type) {
            if ($type->templateVersion() >= 2) {
                $views[$type->value] = [dirname(__DIR__, 2).'/resources/views/pdf/'.str_replace('_', '-', $type->value).'.blade.php'];
            }
        }

        return $views;
    }

    #[DataProvider('formViews')]
    public function test_the_form_views_hold_no_run_of_three_periods(string $view): void
    {
        $this->assertDoesNotMatchRegularExpression('/\.{3}/', (string) file_get_contents($view), 'a dotted run comes from a component');
    }

    #[DataProvider('formViews')]
    public function test_every_snapshot_value_prints_inside_a_bdi_or_a_component_attribute(string $view): void
    {
        $source = (string) file_get_contents($view);
        // A variable the view assigns from the snapshot (`@php($reason = $snapshot['reason'])`) is a snapshot value too.
        preg_match_all('/\$(\w+)\s*=[^;)]*\$snapshot\[/', $source, $aliases);
        $value = implode('|', ['snapshot\[', ...array_map(fn (string $alias): string => $alias.'\b', $aliases[1])]);
        preg_match_all('/(.{0,5})\{\{([^}]*\$(?:'.$value.')[^}]*)\}\}(.{0,6})/u', $source, $echoes, PREG_SET_ORDER);
        $this->assertNotEmpty($echoes);
        foreach ($echoes as [$echo, $before, , $after]) {
            $this->assertTrue(str_ends_with($before, '<bdi>') && str_starts_with($after, '</bdi>'), "not in a <bdi>: {$echo}");
        }
    }

    #[DataProvider('formViews')]
    public function test_both_form_views_extend_the_page_shell(string $view): void
    {
        $this->assertStringStartsWith("@extends('pdf.page')", (string) file_get_contents($view));
    }

    public function test_an_empty_field_prints_its_dots_and_no_bdi(): void
    {
        $html = Blade::render('<x-pdf.field :at="148.32" label="القسم/" value="" :dots="25" />');

        $this->assertStringContainsString(str_repeat('.', 25), $html);
        $this->assertStringNotContainsString(str_repeat('.', 26), $html);
        $this->assertStringNotContainsString('<bdi', $html);
        $this->assertStringNotContainsString('fill', $html);
    }

    public function test_a_filled_field_prints_the_value_then_a_leader_whose_first_five_periods_are_one_nowrap_unit(): void
    {
        $html = Blade::render('<x-pdf.field :at="148.32" label="القسم/" value="اختبار" :dots="25" />');

        $this->assertMatchesRegularExpression('#<bdi>اختبار</bdi></span><span class="fill">&\#8203;<span>\.{5}</span>\.+</span></p>#u', $html);
    }

    public function test_a_pair_always_carries_the_gutter(): void
    {
        foreach (['', '<bdi>X</bdi>'] as $right) {
            $html = Blade::render('<x-pdf.pair :split="327.1"><x-slot:right>'.$right.'</x-slot:right><x-slot:left>Y</x-slot:left></x-pdf.pair>');
            $this->assertMatchesRegularExpression('#grid-template-columns: 327.1pt 1fr"><p style="padding-left: 7pt">'.preg_quote($right, '#').'</p><p>Y</p>#u', $html);
        }
    }

    public function test_a_choice_renders_marked_and_unmarked_circles(): void
    {
        $choice = fn (string $marked): string => Blade::render('<x-pdf.choice :width="118.32" :circle="[29, 26.2]" :gap="23.7" '.$marked.'>- عارضة</x-pdf.choice>');

        $this->assertStringContainsString('- عارضة <span class="circle marked" style="width: 29pt; height: 26.2pt; margin-inline-start: 23.7pt"></span>', $choice(':marked="true"'));
        $this->assertStringContainsString('- عارضة <span class="circle" style="width: 29pt; height: 26.2pt; margin-inline-start: 23.7pt"></span>', $choice(''));
    }
}
