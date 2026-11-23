<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Pdf\Application\Types\LeaveRequestPdf;
use App\Modules\Pdf\Domain\ValueSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfValueSizeTest extends TestCase
{
    /** The leave's steps of spec section 3 (size). */
    private const LEAVE_STEPS = [[0.45, 13], [0.6, 11], [0.65, 10], [1.0, 9]];

    /** The certificate's steps of spec section 3 (size and line spacing). */
    private const CERTIFICATE_STEPS = [[0.25, 15, 2.0], [0.6, 13, 1.75], [1.0, 11, 1.5]];

    public function test_the_leave_steps_are_the_specs(): void
    {
        self::assertSame(self::LEAVE_STEPS, LeaveRequestPdf::SIZES);
    }

    /** @return iterable<string, array{0: non-empty-list<array{0: float, 1: int, 2?: float}>, 1: int, 2: array{0: float, 1: int, 2?: float}}> */
    public static function bounds(): iterable
    {
        foreach (['leave' => self::LEAVE_STEPS, 'certificate' => self::CERTIFICATE_STEPS] as $form => $steps) {
            foreach ($steps as $i => $step) {
                $at = (int) round($step[0] * 100);
                yield "{$form} at {$step[0]}" => [$steps, $at, $step];
                if (isset($steps[$i + 1])) {
                    yield "{$form} just past {$step[0]}" => [$steps, $at + 1, $steps[$i + 1]];
                }
            }
        }
    }

    /** @param non-empty-list<array{0: float, 1: int, 2?: float}> $steps */
    #[DataProvider('bounds')]
    public function test_each_bound_picks_its_step(array $steps, int $length, array $expected): void
    {
        self::assertSame($expected, ValueSize::byShare(['fullest' => $length, 'other' => 1], ['fullest' => 100, 'other' => 100], $steps));
    }

    public function test_past_the_last_bound_picks_the_last_step(): void
    {
        self::assertSame([1.0, 9], ValueSize::byShare(['name_ar' => 91], ['name_ar' => 90], self::LEAVE_STEPS));
        self::assertSame([1.0, 11, 1.5], ValueSize::byShare(['name_ar' => 300, 'job_title' => 0], ['name_ar' => 255, 'job_title' => 160], self::CERTIFICATE_STEPS));
    }
}
