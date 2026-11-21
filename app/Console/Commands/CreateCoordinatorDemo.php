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

class CreateCoordinatorDemo extends Command
{
    protected $signature = 'school:create-coordinator-demo';

    protected $description = 'Crea una cuenta ficticia local de coordinación sin modificar cuentas existentes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Esta cuenta de demostración solo se permite en local o pruebas.');

            return self::FAILURE;
        }
        if (User::withTrashed()->where('dni', '90000012')->exists()) {
            $this->error('La cuenta 90000012 ya existe. No se modificó su contraseña. Consulta el acceso temporal en storage/app/private/coordinator-role-access.txt.');

            return self::FAILURE;
        }
        $role = Role::where('name', RoleName::Coordinator->value)->firstOrFail();
        if ($role->permissions()->whereIn('name', ['tutors.coordinate', 'students.view', 'attendance.view'])->count() !== 3) {
            $this->error('El rol Coordinador de tutoría debe tener habilitados los permisos de coordinación, estudiantes y asistencia.');

            return self::FAILURE;
        }
        $password = Str::password(18);
        DB::transaction(function () use ($role, $password): void {
            $user = User::create(['dni' => '90000012', 'name' => 'Coordinador de Tutoría · Demostración', 'email' => 'coordinator-role@example.test', 'password' => $password, 'is_active' => true, 'must_change_password' => true]);
            $user->roles()->attach($role);
            app(AuditService::class)->record('coordination.demo_account_created', $user);
        });
        Storage::disk('local')->put('coordinator-role-access.txt', "Cuenta ficticia local de coordinación\nDNI: 90000012\nContraseña temporal: ".$password."\nCambio obligatorio al ingresar.\n");
        $this->info('Usuario (DNI): 90000012');
        $this->info('Contraseña temporal: '.$password);
        $this->info('Debe cambiar la contraseña en el primer ingreso.');

        return self::SUCCESS;
    }
}
