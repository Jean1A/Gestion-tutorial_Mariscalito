<?php

namespace App\Services;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\SchoolDay;
use App\Models\SchoolYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicService
{
    public function save(string $resource, array $data, ?int $id): void
    {
        $definition = config('school-resources.'.$resource);
        abort_unless($definition, 404);
        DB::transaction(function () use ($resource, $data, $id, $definition) {
            $model = $id ? $definition['model']::whereKey($id)->lockForUpdate()->firstOrFail() : new $definition['model'];
            $before = $model->exists ? $model->only(array_keys($definition['fields'])) : null;
            if ($resource === 'years') {
                if ($model->closed_at) {
                    throw ValidationException::withMessages(['year' => 'Reabre el año antes de modificar su configuración.']);
                }
                if ($data['is_active'] && SchoolYear::where('is_active', true)->when($id, fn ($query) => $query->whereKeyNot($id))->lockForUpdate()->first()) {
                    throw ValidationException::withMessages(['is_active' => 'Cierra el año activo antes de activar otro.']);
                }
                if ($model->is_active && ! $data['is_active']) {
                    throw ValidationException::withMessages(['is_active' => 'Utiliza el cierre anual para desactivar el año vigente.']);
                }
            }
            if ($resource === 'periods') {
                $year = SchoolYear::whereKey($data['school_year_id'])->lockForUpdate()->firstOrFail();
                if ($year->closed_at || $data['starts_on'] < $year->starts_on->toDateString() || $data['ends_on'] > $year->ends_on->toDateString()) {
                    throw ValidationException::withMessages(['starts_on' => 'El período debe estar dentro del año escolar.']);
                }
                if (AcademicPeriod::where('school_year_id', $year->id)->where('is_active', true)->when($id, fn ($q) => $q->whereKeyNot($id))->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists() && $data['is_active']) {
                    throw ValidationException::withMessages(['starts_on' => 'Los períodos activos no pueden superponerse.']);
                }
            }
            if ($resource === 'years' && $id && (SchoolDay::where('school_year_id', $id)->where(fn ($q) => $q->where('date', '<', $data['starts_on'])->orWhere('date', '>', $data['ends_on']))->exists() || AcademicPeriod::where('school_year_id', $id)->where(fn ($q) => $q->where('starts_on', '<', $data['starts_on'])->orWhere('ends_on', '>', $data['ends_on']))->exists())) {
                throw ValidationException::withMessages(['starts_on' => 'Las nuevas fechas excluyen días o períodos existentes.']);
            }
            if ($resource === 'years' && $id && Enrollment::where('school_year_id', $id)->where(fn ($q) => $q->whereDate('starts_on', '<', $data['starts_on'])->orWhereDate('starts_on', '>', $data['ends_on'])->orWhereDate('ends_on', '>', $data['ends_on']))->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'Las nuevas fechas excluyen matrículas existentes.']);
            }
            if ($resource === 'sections' && $id && $model->enrollments()->exists() && ($model->school_year_id != $data['school_year_id'] || $model->grade_id != $data['grade_id'])) {
                throw ValidationException::withMessages(['school_year_id' => 'Una sección con matrículas conserva su año y grado.']);
            }
            if ($resource === 'sections' && SchoolYear::whereKey($data['school_year_id'])->whereNotNull('closed_at')->exists()) {
                throw ValidationException::withMessages(['school_year_id' => 'El año seleccionado está cerrado.']);
            }
            $model->fill($data)->save();
            app(AuditService::class)->record('academic.saved', $model, ['before' => $before, 'after' => $model->only(array_keys($definition['fields']))]);
        });
    }
}
