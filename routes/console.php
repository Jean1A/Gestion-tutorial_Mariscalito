<?php

use App\Models\StudentImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('school:evaluate-alerts')->everyFifteenMinutes()->withoutOverlapping()->timezone('America/Lima');
Schedule::command('school:evaluate-course-alerts')->everyFifteenMinutes()->withoutOverlapping()->timezone('America/Lima');
Schedule::call(fn () => DB::table('student_import_rows')->whereNotNull('access_payload')
    ->whereIn('student_import_id', DB::table('student_imports')->select('id')
        ->whereIn('status', ['completed', 'failed'])->where('updated_at', '<=', now()->subHours(StudentImport::ACCESS_HOURS)))
    ->update(['access_payload' => null]))->hourly();
if (config('school.backup_enabled')) {
    Schedule::command('school:backup')->dailyAt('21:00')->withoutOverlapping()->timezone('America/Lima');
}
