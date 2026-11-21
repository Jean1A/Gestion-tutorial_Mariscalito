<?php

namespace Database\Seeders;

use App\Models\SupportRecord;
use Illuminate\Database\Seeder;

class SupportRecordSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Solo se permiten datos ficticios en local o pruebas.');
        } SupportRecord::factory()->create();
    }
}
