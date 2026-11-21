<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CreateSchoolDemoUsers extends Command
{
    protected $signature = 'school:create-demo-users';

    protected $description = 'Crea cinco cuentas ficticias para probar los roles operativos en local';

    public function handle(AuditService $audit): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('El comando está disponible en local y pruebas.');

            return self::FAILURE;
        }

        $accounts = [
            '90000012' => RoleName::Coordinator,
            '90000013' => RoleName::Tutor,
            '90000014' => RoleName::Assistant,
            '90000015' => RoleName::Teacher,
            '90000016' => RoleName::Guardian,
        ];
        if (User::withTrashed()->whereIn('dni', array_keys($accounts))->exists() || Storage::disk('local')->exists('accesos-demo-cinco-roles.txt')) {
            $this->error('Hay DNI reservados o un archivo de acceso existente. Conserva las cuentas actuales.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($accounts, $audit): void {
            $credentials = ['Cuentas ficticias de uso local. Cambio de contraseña obligatorio al ingresar.', ''];
            foreach ($accounts as $dni => $roleName) {
                $role = Role::where('name', $roleName->value)->firstOrFail();
                $password = Str::password(18);
                $user = User::create([
                    'name' => $roleName->label().' de demostración',
                    'dni' => (string) $dni,
                    'password' => $password,
                    'is_active' => true,
                    'must_change_password' => true,
                ]);
                $user->roles()->attach($role);
                $audit->record('school.demo_account_created', $user, ['role' => $roleName->value]);
                $credentials[] = $roleName->label().' | DNI '.$dni.' | '.$password;
            }
            if (! Storage::disk('local')->put('accesos-demo-cinco-roles.txt', implode(PHP_EOL, $credentials).PHP_EOL)) {
                throw new RuntimeException('No se pudo guardar el archivo privado de credenciales.');
            }
        });

        $this->info('Cinco cuentas creadas. Credenciales temporales en storage/app/private/accesos-demo-cinco-roles.txt.');

        return self::SUCCESS;
    }
}
