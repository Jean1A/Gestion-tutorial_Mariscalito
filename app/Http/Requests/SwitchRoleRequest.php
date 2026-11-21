<?php

namespace App\Http\Requests;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwitchRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = RoleName::tryFrom((string) $this->input('role'));

        return $role && $this->user()?->hasRole($role);
    }

    public function rules(): array
    {
        return ['role' => ['required', Rule::enum(RoleName::class)]];
    }
}
