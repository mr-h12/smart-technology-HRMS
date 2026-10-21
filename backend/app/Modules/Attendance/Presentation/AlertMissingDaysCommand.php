<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\EffectiveDays;
use App\Modules\Attendance\Application\SendMissingDaysAlert;
use DateTimeImmutable;
use Illuminate\Console\Command;

/** Scheduled daily at 21:00 Cairo (routes/console.php); run by hand to replay a date. */
final class AlertMissingDaysCommand extends Command
{
    protected $signature = 'attendance:alert-missing {date? : Y-m-d, defaults to today in Cairo}';

    protected $description = 'Queue the missing-days notification for HR';

    public function handle(): int
    {
        $date = $this->argument('date') ?? EffectiveDays::today();
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $this->error('The date must be Y-m-d.');

            return self::FAILURE;
        }
        SendMissingDaysAlert::dispatch($date);
        $this->info("Queued the missing-days alert for {$date}.");

        return self::SUCCESS;
    }
}
