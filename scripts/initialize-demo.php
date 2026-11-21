<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local')) {
    throw new RuntimeException('Solo disponible en local.');
}
$password = env('DEMO_PASSWORD');
if (! $password) {
    $password = 'McA9'.bin2hex(random_bytes(12));
    file_put_contents(base_path('.env'), PHP_EOL.'DEMO_PASSWORD='.$password.PHP_EOL, FILE_APPEND | LOCK_EX);
}
if (! is_dir(storage_path('app/private'))) {
    mkdir(storage_path('app/private'), 0700, true);
}
$existingUsers = User::withTrashed()->whereIn('dni', array_keys(DemoSeeder::ACCOUNTS))->get()->keyBy('dni');
$lines = ['MARISCAL CASTILLA — cuentas ficticias locales', ''];
$usesInitialPassword = false;
foreach (DemoSeeder::ACCOUNTS as $dni => $role) {
    $user = $existingUsers->get($dni);
    $available = ! $user || ($user->email === $role->value.'@example.test' && $user->hasRole($role));
    $usesInitialPasswordForAccount = $available && (! $user || Hash::check($password, $user->password));
    $usesInitialPassword = $usesInitialPassword || $usesInitialPasswordForAccount;
    $status = match (true) {
        ! $available => 'cuenta existente fuera de esta preparación',
        $usesInitialPasswordForAccount => 'usa la contraseña inicial',
        default => 'usa su contraseña actual',
    };
    $lines[] = $dni.' '.$role->label().' — '.$status;
}
if ($usesInitialPassword) {
    $lines[] = '';
    $lines[] = 'Contraseña inicial: '.$password;
}
$lines[] = '';
$lines[] = 'Las cuentas nuevas exigen cambio de contraseña en el primer ingreso.';
file_put_contents(storage_path('app/private/demo-access.txt'), implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX);
echo 'Listado de demostración preparado en almacenamiento privado. Ejecuta el DemoSeeder para crear las cuentas pendientes.'.PHP_EOL;
