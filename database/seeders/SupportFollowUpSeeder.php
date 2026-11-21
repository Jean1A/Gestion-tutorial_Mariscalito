<?php

namespace Database\Seeders;

use App\Models\SupportFollowUp;
use Illuminate\Database\Seeder;

class SupportFollowUpSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Solo se permiten datos ficticios en local o pruebas.');
        } SupportFollowUp::factory()->create();
    }
}
