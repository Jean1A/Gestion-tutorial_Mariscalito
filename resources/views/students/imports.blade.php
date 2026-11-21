@extends('layouts.app')
@section('title', 'Importar estudiantes')
@section('content')
<a class="back-link" href="{{ route('students.index') }}"><x-icon name="arrow-left"/> Volver a Estudiantes</a>
<h1>Importar estudiantes</h1>
<p class="portal-subtitle">Carga las matrículas y vincula a sus apoderados con un solo Excel.</p>

<section class="portal-panel">
    <div class="portal-panel__heading"><x-icon name="download"/><h2>1. Descarga y completa la plantilla</h2></div>
    <p>Completa una fila por estudiante. Si dos estudiantes comparten apoderado, repite el nombre y DNI del apoderado en ambas filas. Usa los nombres de grado y sección del colegio.</p>
    <p>Los estudiantes y apoderados con cuenta nueva ingresan con su propio DNI como usuario y contraseña inicial. El sistema les pedirá cambiarla al ingresar.</p>
    <a href="{{ route('imports.template') }}" class="btn btn-outline-primary"><x-icon name="download"/> Descargar formato Excel</a>
    <details class="mt-3">
        <summary class="portal-action">Ver las ocho columnas</summary>
        <p class="mt-2">{{ implode(' · ', $headers) }}</p>
    </details>
    @if($sections->isNotEmpty())
        <details class="mt-2">
        <summary class="portal-action">Consultar grados y secciones habilitados</summary>
            <ul class="mt-2 mb-0">
                @foreach($sections as $section)
                    <li>{{ $section->schoolYear->year }} · {{ $section->grade->educationLevel->name }} · {{ $section->grade->name }} · {{ $section->name }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</section>

<section class="portal-panel">
    <div class="portal-panel__heading"><x-icon name="upload"/><h2>2. Elige el año y nivel, y sube el archivo</h2></div>
    @if($sections->isEmpty())
        <p class="soft-note mb-0">Habilita los grados y secciones de un año escolar antes de importar.</p>
    @else
        <form method="post" enctype="multipart/form-data" action="{{ route('imports.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label" for="school_year_id">Año escolar</label>
                    <select class="form-select" name="school_year_id" id="school_year_id" required>
                        @foreach($years as $year)
                            <option value="{{ $year->id }}" @selected((string) old('school_year_id') === (string) $year->id)>{{ $year->year }} · {{ $year->is_active ? 'Activo' : 'En preparación' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="education_level_id">Nivel educativo</label>
                    <select class="form-select" name="education_level_id" id="education_level_id" required>
                        <option value="">Selecciona el nivel</option>
                        @foreach($levels as $level)
                            <option value="{{ $level->id }}" @selected((string) old('education_level_id') === (string) $level->id)>{{ $level->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="file">Archivo de Excel</label>
                    <input class="form-control" name="file" id="file" type="file" accept=".xlsx" required>
                    <div class="form-text">Hasta {{ \App\Services\StudentWorkbookImport::MAX_ROWS }} filas y 5 MB. Si una fila tiene errores, podrás corregirla y volver a subirla.</div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input id="identity_verified" name="identity_verified" value="1" class="form-check-input" type="checkbox" @checked(old('identity_verified')) required>
                        <label class="form-check-label" for="identity_verified">Verifiqué los DNI y el vínculo de cada apoderado con su estudiante.</label>
                    </div>
                </div>
                <div class="col-12"><button class="btn btn-orange"><x-icon name="upload"/> Importar estudiantes</button></div>
            </div>
        </form>
    @endif
</section>

<section class="portal-panel">
    <div class="portal-panel__heading"><x-icon name="clock"/><h2>Historial de importaciones</h2></div>
    <div class="table-responsive">
        <table class="portal-table">
            <thead><tr><th scope="col">Importación</th><th scope="col">Estado</th><th scope="col">Filas correctas</th><th scope="col">Errores</th></tr></thead>
            <tbody>
                @forelse($imports as $import)
                    <tr><td><a class="portal-action" href="{{ route('imports.show', $import) }}">Ver importación #{{ $import->id }}</a></td><td>{{ ['queued' => 'En cola', 'processing' => 'Procesando', 'completed' => 'Completada', 'failed' => 'Fallida'][$import->status] }}</td><td>{{ $import->imported_count }}</td><td>{{ $import->error_count }}</td></tr>
                @empty
                    <tr><td colspan="4">Aún no hay importaciones.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($imports->hasPages())<div class="mt-3">{{ $imports->links() }}</div>@endif
</section>
@endsection
