<?php

namespace Database\Seeders;

use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Database\Seeder;

class SitesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['اجازة الجمعة' => 'friday', 'أجازة رسمية' => 'official_holiday', 'اجازة مرضي' => 'sick', 'راحة' => 'rest', 'غ' => 'absent'] as $name => $code) {
            Site::firstOrCreate(['name_ar' => $name], ['kind' => 'leave', 'leave_code' => $code]);
        }
        foreach (['كفر الدوار', 'الاسكندرية', 'العامرية', 'دمنهور'] as $name) {
            Residence::firstOrCreate(['name_ar' => $name]);
        }
    }
}
