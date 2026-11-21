<?php

namespace App\Console\Commands;

use App\Services\AttendanceAlertService;
use Illuminate\Console\Command;

class EvaluateAttendanceAlerts extends Command
{
    protected $signature = 'school:evaluate-alerts';

    protected $description = 'Evalúa porcentajes y faltas consecutivas con umbrales de la base de datos';

    public function handle(AttendanceAlertService $service): int
    {
        $this->info('Alertas activadas: '.$service->evaluate());

        return self::SUCCESS;
    }
}
