<?php

namespace App\Services;

use App\Exceptions\SetupIncomplete;
use App\Models\Setting;

class SettingsService
{
    public function required(string $key): mixed
    {
        $setting = Setting::find($key);
        if (! $setting || $setting->value === null) {
            throw new SetupIncomplete('Falta configurar el parámetro: '.$key);
        }

        return $setting->value;
    }
}
