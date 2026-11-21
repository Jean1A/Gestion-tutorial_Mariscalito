<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class RestoreSchoolBackup extends Command
{
    protected $signature = 'school:restore-test {backup : Nombre del archivo dentro de backups}';

    protected $description = 'Valida una copia restaurándola exclusivamente en school_tutoring_restore_test';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || config('database.default') !== 'mysql') {
            $this->error('Prueba de restauración disponible solo en MySQL local.');

            return self::FAILURE;
        }
        $name = (string) $this->argument('backup');
        if (basename($name) !== $name || ! str_ends_with($name, '.sql')) {
            $this->error('Indica únicamente el nombre de una copia SQL.');

            return self::FAILURE;
        }
        $path = Storage::disk('local')->path('backups/'.$name);
        $binary = config('school-delivery.restore_binary');
        if (! is_file($path) || ! $binary || ! is_file($binary)) {
            $this->error('Revisa el archivo privado y SCHOOL_RESTORE_BINARY.');

            return self::FAILURE;
        }
        $sql = file_get_contents($path);
        if (preg_match('/^\s*(USE\s|(?:CREATE|ALTER|DROP)\s+DATABASE\b)/im', $sql) || preg_match('/SET\s+(?:@@GLOBAL\.|GLOBAL\s)/i', $sql)) {
            $this->error('La copia no puede contener instrucciones que cambien la base de destino.');

            return self::FAILURE;
        }
        $c = config('database.connections.mysql');
        $option = tempnam(storage_path('app/private'), 'restore-options-');
        $escape = fn (string $v) => str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $v);
        try {
            DB::statement('CREATE DATABASE IF NOT EXISTS `school_tutoring_restore_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            file_put_contents($option, "[client]\nhost=\"".$escape((string) $c['host'])."\"\nport=".(int) $c['port']."\nuser=\"".$escape((string) $c['username'])."\"\npassword=\"".$escape((string) $c['password'])."\"\n");
            $process = new Process([$binary, '--defaults-extra-file='.$option, '--database=school_tutoring_restore_test']);
            $process->setInput($sql);
            $process->setTimeout(300);
            $process->run();
            if (! $process->isSuccessful()) {
                Storage::disk('local')->put('backups/restore-last-error.log', $process->getErrorOutput());
                $this->error('La restauración de prueba no terminó correctamente.');

                return self::FAILURE;
            }
            $tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema='school_tutoring_restore_test'");
            if (count($tables) === 0) {
                $this->error('La copia no contenía tablas.');

                return self::FAILURE;
            }
            $this->info('Restauración completada en school_tutoring_restore_test con '.count($tables).' tablas. La base principal no se modificó.');

            return self::SUCCESS;
        } finally {
            if (is_file($option)) {
                unlink($option);
            }
        }
    }
}
