<?php

namespace Database\Seeders;

use App\Models\FamilyMessage;
use Illuminate\Database\Seeder;

class FamilyMessageSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Solo se permiten datos ficticios en local o pruebas.');
        } FamilyMessage::factory()->create();
    }
}
