<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class PasswordRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->routeIs('password.update')) {
            return [
                'email' => ['required', 'email'],
                'token' => ['required', 'string', 'max:200'],
                'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            ];
        }

        return ['email' => ['required', 'email', 'max:254']];
    }
}
