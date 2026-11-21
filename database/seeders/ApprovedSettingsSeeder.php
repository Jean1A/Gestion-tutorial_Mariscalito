<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class ApprovedSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'security.login_attempts' => 5,
            'security.lock_minutes' => 1,
            'security.idle_minutes' => 30,
            'attendance.edit_hours' => 24,
            'attendance.percentage_thresholds' => [10, 20, 25],
            'attendance.consecutive_days' => 3,
        ] as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
