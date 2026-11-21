<?php

namespace App\Http\Requests;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->route('user');

        return $subject ? $this->user()->can('update', $subject) : $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        $subject = $this->route('user');

        $assignableRoles = $this->user()->activeRole() === RoleName::Coordinator
            ? RoleName::delegatedAccountRoles()
            : array_diff(array_column(RoleName::cases(), 'value'), [RoleName::Student->value]);
        $allowedRoles = Rule::exists('roles', 'id')->whereIn('name', $assignableRoles);

        return [
            'name' => ['required', 'string', 'max:150'],
            'dni' => ['required', 'regex:/^[0-9]{8}$/', Rule::unique('users', 'dni')->ignore($subject)],
            'email' => ['nullable', 'email', 'max:254', Rule::unique('users', 'email')->ignore($subject)],
            'phone' => ['nullable', 'regex:/^\+?[0-9]{7,15}$/'],
            'roles' => ['required', 'array', 'min:1', 'max:'.count($assignableRoles)],
            'roles.*' => ['required', 'integer', 'distinct', $allowedRoles],
            'password' => [$subject ? 'nullable' : 'required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'identity_verified' => [$subject && $this->filled('password') ? 'accepted' : 'nullable'],
        ];
    }
}
