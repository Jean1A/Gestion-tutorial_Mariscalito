<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardian_consents', function (Blueprint $table) {
            $table->json('student_ids')->nullable();
            $table->string('policy_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guardian_consents', fn (Blueprint $table) => $table->dropColumn(['student_ids', 'policy_hash']));
    }
};
