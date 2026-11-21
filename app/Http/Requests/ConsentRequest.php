<?php

namespace App\Http\Requests;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;

class ConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->activeRole() === RoleName::Guardian;
    }

    public function rules(): array
    {
        return ['consent' => ['accepted']];
    }
}
