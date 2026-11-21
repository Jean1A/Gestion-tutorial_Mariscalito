<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAcademicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-academics');
    }

    public function rules(): array
    {
        $resource = $this->route('resource');
        abort_unless(config('school-resources.'.$resource), 404);
        $id = $this->route('id');
        $name = ['required', 'string', 'max:60'];
        $rules = ['is_active' => ['required', 'boolean']];

        return $rules + match ($resource) {
            'years' => ['year' => ['required', 'integer', 'between:2000,2100', Rule::unique('school_years')->ignore($id)], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']],
            'levels' => ['name' => [...$name, Rule::unique('education_levels')->ignore($id)]],
            'grades' => ['education_level_id' => ['required', Rule::exists('education_levels', 'id')->where('is_active', true)], 'name' => [...$name, Rule::unique('grades')->where('education_level_id', $this->input('education_level_id'))->ignore($id)]],
            'sections' => ['school_year_id' => ['required', Rule::exists('school_years', 'id')->whereNull('closed_at')], 'grade_id' => ['required', Rule::exists('grades', 'id')->where('is_active', true)], 'name' => ['required', 'string', 'max:30', Rule::unique('sections')->where('school_year_id', $this->input('school_year_id'))->where('grade_id', $this->input('grade_id'))->ignore($id)]],
            'periods' => ['school_year_id' => ['required', Rule::exists('school_years', 'id')->whereNull('closed_at')], 'name' => [...$name, Rule::unique('academic_periods')->where('school_year_id', $this->input('school_year_id'))->ignore($id)], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']],
        };
    }
}
