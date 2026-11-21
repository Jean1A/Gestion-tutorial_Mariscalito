<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['security.login_attempts' => 5, 'security.lock_minutes' => 1] as $key => $value) {
            DB::table('settings')->where('key', $key)->update([
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Preserve any security settings saved after this migration.
    }
};
