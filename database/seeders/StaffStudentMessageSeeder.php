<?php

namespace Database\Seeders;

use App\Models\StaffStudentMessage;
use Illuminate\Database\Seeder;

class StaffStudentMessageSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            StaffStudentMessage::factory()->create();
        }
    }
}
