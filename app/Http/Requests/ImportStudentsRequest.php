<?php

namespace App\Http\Requests;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Student::class) && (! $this->filled('school_year_id')
            || ($this->user()->can('create', User::class) && $this->user()->can('manage-assignments')));
    }

    public function rules(): array
    {
        if ($this->filled('section_id') && ! $this->filled('school_year_id')) {
            return ['section_id' => ['required', 'integer', Rule::exists('sections', 'id')->where('is_active', true)], 'file' => ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:10240']];
        }

        return [
            'school_year_id' => ['required', 'integer', Rule::exists('school_years', 'id')->whereNull('closed_at')],
            'education_level_id' => ['required', 'integer', Rule::exists('education_levels', 'id')->where('is_active', true)],
            'identity_verified' => ['required', 'accepted'],
            'file' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:5120'],
        ];
    }
}
