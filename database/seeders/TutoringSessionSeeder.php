<?php

namespace Database\Seeders;

use App\Models\TutoringSession;
use Illuminate\Database\Seeder;

class TutoringSessionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Solo se permiten datos ficticios en local o pruebas.');
        } TutoringSession::factory()->create();
    }
}
