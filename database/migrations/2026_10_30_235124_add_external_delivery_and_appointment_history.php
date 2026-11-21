<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('external_notifications_enabled')->default(false));
        Schema::table('communications', fn (Blueprint $t) => $t->unsignedInteger('revision')->default(1));
        Schema::create('communication_schedule_changes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('communication_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamp('previous_at');
            $t->timestamp('scheduled_at');
            $t->string('location', 160);
            $t->string('reason', 500);
            $t->json('previous_responses');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_schedule_changes');
        Schema::table('communications', fn (Blueprint $t) => $t->dropColumn('revision'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('external_notifications_enabled'));
    }
};
