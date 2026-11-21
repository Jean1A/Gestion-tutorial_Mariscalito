<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    public function rules(): array
    {
        return ['login_attempts' => ['required', 'integer', 'between:1,100'], 'lock_minutes' => ['required', 'integer', 'between:1,1440'], 'idle_minutes' => ['required', 'integer', 'between:1,1440'], 'edit_hours' => ['required', 'integer', 'between:1,168'], 'consecutive_days' => ['required', 'integer', 'between:1,30'], 'percentage_thresholds' => ['required', 'array', 'min:1', 'max:10'], 'percentage_thresholds.*' => ['required', 'numeric', 'gt:0', 'lte:100', 'distinct']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('percentage_thresholds'))) {
            $this->merge(['percentage_thresholds' => array_map('trim', explode(',', $this->input('percentage_thresholds')))]);
        }
    }
}
