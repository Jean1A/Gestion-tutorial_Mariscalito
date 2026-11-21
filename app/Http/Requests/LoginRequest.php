<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['dni' => ['required', 'regex:/^[0-9]{8}$/'], 'password' => ['required', 'string', 'max:200']];
    }

    public function messages(): array
    {
        return ['dni.required' => 'Ingresa tu DNI.', 'dni.regex' => 'El DNI debe tener 8 dígitos.', 'password.required' => 'Ingresa tu contraseña.'];
    }
}
