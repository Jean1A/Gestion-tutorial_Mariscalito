<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ConfigureSchoolSecurity extends Command
{
    protected $signature = 'school:configure-security {--attempts=} {--lock-minutes=} {--idle-minutes=}';

    protected $description = 'Configura valores de seguridad explícitos, sin utilizar valores predeterminados';

    public function handle(): int
    {
        $data = ['attempts' => $this->option('attempts'), 'lock_minutes' => $this->option('lock-minutes'), 'idle_minutes' => $this->option('idle-minutes')];
        $validator = Validator::make($data, [
            'attempts' => ['required', 'integer', 'min:1', 'max:100'],
            'lock_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'idle_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($data) {
            foreach (['attempts' => 'security.login_attempts', 'lock_minutes' => 'security.lock_minutes', 'idle_minutes' => 'security.idle_minutes'] as $field => $key) {
                Setting::updateOrCreate(['key' => $key], ['value' => (int) $data[$field]]);
            }
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'security.configured_cli',
                'subject_type' => Setting::class,
                'subject_id' => null,
                'changes' => json_encode(['after' => array_map('intval', $data)], JSON_THROW_ON_ERROR),
                'ip_address' => null,
                'created_at' => now(),
            ]);
        });
        $this->info('Parámetros guardados. El acceso ya puede utilizar estos valores.');

        return self::SUCCESS;
    }
}
