<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckSchoolReadiness extends Command
{
    protected $signature = 'school:readiness';

    protected $description = 'Comprueba configuración operativa sin mostrar credenciales ni efectuar envíos';

    public function handle(): int
    {
        $checks = ['Base de datos' => false, 'Correo SMTP real' => config('mail.default') === 'smtp' && (bool) config('mail.mailers.smtp.host'), 'URL HTTPS' => str_starts_with(config('app.url'), 'https://'), 'Copias diarias' => config('school.backup_enabled') && is_file(config('school.backup_binary') ?? ''), 'Herramienta de restauración' => is_file(config('school-delivery.restore_binary') ?? ''), 'Adaptador WhatsApp o SMS' => config('school-delivery.webhook_enabled') && str_starts_with(config('school-delivery.webhook_url') ?? '', 'https://') && (bool) config('school-delivery.webhook_token')];
        try {
            DB::connection()->getPdo();
            $checks['Base de datos'] = true;
            $this->line('Trabajos fallidos: '.DB::table('failed_jobs')->count());
        } catch (\Throwable) {
            $this->error('No se pudo comprobar la conexión.');
        }
        foreach ($checks as $name => $ready) {
            $this->line($name.': '.($ready ? 'Configurado' : 'Pendiente'));
        }
        $this->line('Mantén activos queue:work y schedule:work en local. Un servicio configurado todavía requiere prueba de entrega, restauración y monitoreo.');

        return $checks['Base de datos'] ? self::SUCCESS : self::FAILURE;
    }
}
