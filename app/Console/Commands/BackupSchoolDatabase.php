<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupSchoolDatabase extends Command
{
    protected $signature = 'school:backup';

    protected $description = 'Genera una copia privada de MySQL usando mysqldump sin exponer la contraseña en argumentos';

    public function handle(): int
    {
        if (config('database.default') !== 'mysql') {
            $this->error('Esta copia requiere la conexión MySQL.');

            return self::FAILURE;
        }
        $binary = config('school.backup_binary');
        if (! $binary || ! is_file($binary)) {
            $this->error('Configura SCHOOL_BACKUP_BINARY con la ruta de mysqldump antes de programar copias.');

            return self::FAILURE;
        }
        $c = config('database.connections.mysql');
        $disk = Storage::disk('local');
        $disk->makeDirectory('backups');
        $name = 'backups/school-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
        $output = $disk->path($name);
        $escape = fn (string $v) => str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $v);
        $option = tempnam(storage_path('app/private'), 'backup-options-');
        try {
            file_put_contents($option, "[client]\nhost=\"".$escape((string) $c['host'])."\"\nport=".(int) $c['port']."\nuser=\"".$escape((string) $c['username'])."\"\npassword=\"".$escape((string) $c['password'])."\"\n");
            $process = new Process([$binary, '--defaults-extra-file='.$option, '--single-transaction', '--skip-lock-tables', '--set-gtid-purged=OFF', '--no-tablespaces', '--result-file='.$output, $c['database']]);
            $process->setTimeout(300);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($output) || filesize($output) === 0) {
                if (is_file($output)) {
                    unlink($output);
                }$this->error('No se pudo completar la copia. Revisa la conexión, la herramienta y los permisos.');

                return self::FAILURE;
            }
            $this->info('Copia privada creada: '.$name);

            return self::SUCCESS;
        } finally {
            if (is_file($option)) {
                unlink($option);
            }
        }
    }
}
