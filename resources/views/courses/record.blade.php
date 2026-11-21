@extends(auth()->user()->activeRole()===\App\Enums\RoleName::Tutor ? 'layouts.advisor':'layouts.app')
@section('title','Registrar asistencia por curso')
@section('content')
<x-back-link :href="route('courses.mine')">Volver a mis cursos</x-back-link>
@can('grade',$offering)<details class="card mb-3"><summary class="card-body">Publicar contenido del curso</summary><div class="card-body border-top"><form method="post" action="{{ route('courses.content',$offering) }}">@csrf<label for="course-content" class="form-label">Contenido visible para las familias</label><textarea id="course-content" name="content" class="form-control mb-3" rows="6" maxlength="20000" required>{{ old('content',$offering->content) }}</textarea><button class="btn btn-primary">Guardar contenido</button></form></div></details>@endcan
<h1 class="h3">{{ $offering->course->name }} · {{ $offering->section->grade->name }} {{ $offering->section->name }}</h1>
<form method="get" class="d-flex flex-wrap gap-2 my-3"><label class="visually-hidden" for="date">Fecha</label><input id="date" class="form-control w-auto" type="date" name="date" value="{{ $date }}" max="{{ now()->toDateString() }}" required><button class="btn btn-outline-primary">Consultar</button></form>
@cannot('record', $offering)
    <x-notice>
        <p>Consulta de asistencia por curso. La jornada diaria aparece en una columna independiente.</p>
    </x-notice>
@endcannot
@can('exportResults',$offering)<a class="btn btn-outline-primary mb-3" href="{{ route('courses.results.export',$offering) }}">Exportar logros a Excel</a>@endcan
@if(in_array(auth()->user()->activeRole(), [\App\Enums\RoleName::Teacher,\App\Enums\RoleName::Tutor], true) && $offering->section->schoolYear->is_active)
    <a class="btn btn-outline-primary mb-3" href="{{ route('courses.contacts',$offering) }}">Contactos y mensajes</a>
@endif
@if(auth()->user()->activeRole() === \App\Enums\RoleName::Teacher && $offering->section->schoolYear->is_active)
    <a class="btn btn-outline-primary mb-3" href="{{ route('support.create',['course_offering_id'=>$offering->id,'section_id'=>$offering->section_id]) }}">Reportar incidencia al tutor</a>
@endif
<section class="portal-panel">
    <div class="portal-panel__heading"><x-icon name="check-circle"/><h2>Asistencia por curso</h2></div>
    @php
        $canRecord = auth()->user()->can('record', $offering);
    @endphp
    <form method="post" action="{{ route('courses.attendance', $offering) }}" data-attendance-form>
        @csrf
        <input type="hidden" name="date" value="{{ $date }}">
        <input type="hidden" name="revision" value="{{ $session?->revision ?? 0 }}">
        <fieldset @disabled(!$canRecord)>
            @if($canRecord && $roster->isNotEmpty())
                <div class="portal-filters">
                    <div><label for="base-time">Hora de ingreso común</label><input class="form-control" id="base-time" type="time" value="{{ now()->format('H:i') }}"></div>
                    <button type="button" class="btn btn-outline-primary" data-mark-present>Marcar pendientes como presentes</button>
                </div>
            @endif
            <div class="card table-responsive">
                <table class="table attendance-roster mb-0">
                    <thead><tr><th scope="col">Estudiante</th><th scope="col">Jornada diaria</th><th scope="col">Asistencia del curso</th><th scope="col">Ingreso</th></tr></thead>
                    <tbody>
                        @foreach($roster as $enrollment)
                            @php
                                $record = $records->get($enrollment->id);
                                $field = 'records.'.$loop->index.'.';
                            @endphp
                            <tr data-attendance-row>
                                <th scope="row">{{ $enrollment->student->full_name }}<input type="hidden" name="records[{{ $loop->index }}][enrollment_id]" value="{{ $enrollment->id }}"></th>
                                <td data-label="Jornada diaria" data-wide>{{ $daily->get($enrollment->id)?->status->label() ?? 'Sin registro' }}</td>
                                <td data-label="Asistencia del curso">
                                    <select class="form-select" name="records[{{ $loop->index }}][status]" data-status aria-label="Asistencia de {{ $enrollment->student->full_name }}" required>
                                        <option value="">Pendiente</option>
                                        @foreach(\App\Enums\AttendanceStatus::cases() as $status)
                                            @continue($status->value === 'justified_absence' && $record?->status->value !== 'justified_absence')
                                            <option value="{{ $status->value }}" @selected(old($field.'status', $record?->status->value) === $status->value)>{{ $status->label() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td data-label="Ingreso"><input class="form-control" data-arrival type="time" name="records[{{ $loop->index }}][arrival_time]" value="{{ old($field.'arrival_time', $record?->arrival_time ? substr($record->arrival_time, 0, 5) : '') }}" aria-label="Hora de ingreso de {{ $enrollment->student->full_name }}"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($roster->isEmpty())
                <p class="empty-state">No hay estudiantes en la lista para esta fecha.</p>
            @elseif($canRecord)
                <button class="btn btn-orange mt-3"><x-icon name="check-circle"/> Guardar asistencia del curso</button>
            @endif
        </fieldset>
    </form>
</section>
@can('grade',$offering)<div class="card my-4"><div class="card-body"><h2 class="h5">Registrar nivel de logro</h2><form method="post" action="{{ route('courses.results',$offering) }}">@csrf<div class="row g-3"><div class="col-md-4"><label class="form-label" for="enrollment_id">Estudiante</label><select class="form-select" id="enrollment_id" name="enrollment_id" required>@foreach($roster as $enrollment)<option value="{{ $enrollment->id }}">{{ $enrollment->student->full_name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label" for="academic_period_id">Período</label><select class="form-select" id="academic_period_id" name="academic_period_id" required>@foreach($periods as $period)<option value="{{ $period->id }}">{{ $period->name }}</option>@endforeach</select></div><div class="col-md-2"><label class="form-label" for="achievement">Logro</label><select class="form-select" id="achievement" name="achievement">@foreach(['AD','A','B','C'] as $value)<option>{{ $value }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label" for="is_published">Visibilidad</label><select class="form-select" id="is_published" name="is_published"><option value="0">Borrador</option><option value="1">Publicado al apoderado</option></select></div><div class="col-12"><label class="form-label" for="grade-reason">Motivo al rectificar un logro publicado</label><input class="form-control mb-2" name="reason" id="grade-reason" maxlength="500" value="{{ old('reason') }}"><button class="btn btn-primary">Guardar nivel de logro</button></div></div></form></div></div>
<div class="card table-responsive"><table class="table mb-0"><thead><tr><th>Estudiante</th><th>Período</th><th>Logro</th><th>Estado</th></tr></thead><tbody>@forelse($results as $result)<tr><td>{{ $result->enrollment->student->full_name }}</td><td>{{ $result->period->name }}</td><td>{{ $result->achievement }}</td><td>{{ $result->is_published ? 'Publicado':'Borrador' }}</td></tr>@empty<tr><td colspan="4">No hay niveles registrados.</td></tr>@endforelse</tbody></table></div>
@endcan
@can('grade',$offering)<section class="portal-panel mt-3"><h2 class="h5">Importar niveles de logro</h2><p>Usa el DNI y el nombre exacto del período. Publicación 0 significa borrador y 1 significa visible a la familia. Si hay una fila inválida se rechaza el archivo completo.</p><a class="btn btn-outline-primary mb-3" href="{{ route('courses.results.template',$offering) }}">Descargar plantilla CSV</a><form method="post" enctype="multipart/form-data" action="{{ route('courses.results.import',$offering) }}">@csrf<label for="results-file" class="form-label">CSV o XLSX hasta 5 MB y 2000 filas</label><input class="form-control mb-3" id="results-file" type="file" name="file" required accept=".csv,.xlsx"><button class="btn btn-orange">Importar resultados</button></form></section>@endcan
@endsection
