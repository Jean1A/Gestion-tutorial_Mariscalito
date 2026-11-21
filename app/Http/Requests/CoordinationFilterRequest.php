<?php

namespace App\Http\Requests;

use App\Models\AcademicPeriod;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use App\Services\StudentSupportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CoordinationFilterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('coordinate-tutors');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'school_year_id' => ['nullable', 'integer', Rule::exists('school_years', 'id')->whereNull('closed_at')],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'dimension' => ['nullable', Rule::in(array_keys(StudentSupportService::DIMENSIONS))],
            'grade_id' => ['nullable', 'integer', 'exists:grades,id'],
            'period_id' => ['nullable', 'integer', 'exists:academic_periods,id'],
            'tutor_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'account_status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $year = $this->filled('school_year_id') ? SchoolYear::available()->find($this->integer('school_year_id')) : SchoolYear::available()->orderByDesc('is_active')->orderByDesc('year')->first();
            if ($this->filled('period_id') && (! $year || ! AcademicPeriod::whereKey($this->integer('period_id'))->where('school_year_id', $year->id)->exists())) {
                $validator->errors()->add('period_id', 'El período debe pertenecer al año seleccionado.');
            }
            if ($this->filled('from') && $this->filled('to') && $this->input('to') < $this->input('from')) {
                $validator->errors()->add('to', 'La fecha final no puede ser anterior a la inicial.');
            }
            if ($this->filled('section_id') && (! $year || ! Section::whereKey($this->integer('section_id'))->where('school_year_id', $year->id)->exists())) {
                $validator->errors()->add('section_id', 'La sección debe pertenecer al año seleccionado.');
            }
            if ($this->filled('tutor_id') && ! User::whereKey($this->integer('tutor_id'))->whereHas('roles', fn ($query) => $query->where('name', 'tutor'))->exists()) {
                $validator->errors()->add('tutor_id', 'Selecciona una cuenta con el rol Tutor.');
            }
            foreach (['from', 'to'] as $field) {
                if ($this->filled($field) && (! $year || $this->input($field) < $year->starts_on->toDateString() || $this->input($field) > $year->ends_on->toDateString())) {
                    $validator->errors()->add($field, 'La fecha debe estar dentro del año escolar seleccionado.');
                }
            }
        }];
    }
}
