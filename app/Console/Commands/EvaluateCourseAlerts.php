<?php

namespace App\Console\Commands;

use App\Services\CourseAlertService;
use Illuminate\Console\Command;

class EvaluateCourseAlerts extends Command
{
    protected $signature = 'school:evaluate-course-alerts';

    protected $description = 'Evaluar inasistencias y sesiones consecutivas por curso';

    public function handle(CourseAlertService $alerts): int
    {
        $this->info('Alertas nuevas: '.$alerts->evaluate());

        return self::SUCCESS;
    }
}
