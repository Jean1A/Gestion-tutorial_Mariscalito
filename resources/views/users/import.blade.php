@extends('layouts.app')
@section('title', 'Importar tutores')
@section('content')
<a href="{{ route('users.index', ['role' => 'tutor']) }}" class="back-link"><x-icon name="arrow-left"/> Volver a Tutores</a>
<h1>Importar tutores desde Excel</h1>
<p class="portal-subtitle">Crea las cuentas y registra sus vínculos en un solo paso.</p>

<section class="portal-panel">
    <div class="portal-panel__heading"><x-icon name="download"/><h2>1. Descarga y completa la plantilla</h2></div>
    <p>Conserva los encabezados y escribe los DNI como texto de 8 dígitos. Para cada cuenta nueva, escribe una contraseña temporal de al menos 12 caracteres con mayúsculas, minúsculas y números. Las cuentas existentes conservan su contraseña.</p>
    <p>Usa el nombre del nivel, grado y sección tal como aparecen en la estructura del año elegido.</p>
    <a href="{{ route('users.import.template', 'tutor') }}" class="btn btn-outline-primary"><x-icon name="download"/> Descargar plantilla Excel</a>
    <details class="mt-3">
        <summary class="portal-action">Ver columnas de la plantilla</summary>
        <p class="mt-2">{{ implode(' · ', $columns) }}</p>
    </details>
    @if($sections->isNotEmpty())
        <details class="mt-2">
        <summary class="portal-action">Ver nombres de niveles, grados y secciones</summary>
            <ul class="mt-2">
                @foreach($sections as $section)
                    <li>{{ $section->schoolYear->year }} · {{ $section->grade->educationLevel->name }} · {{ $section->grade->name }} · {{ $section->name }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</section>

<section class="portal-panel">
    <div class="portal-panel__heading"><x-icon name="upload"/><h2>2. Elige el año y sube el archivo</h2></div>
    @if($years->isEmpty())
        <p class="soft-note">Abre o prepara un año escolar antes de importar.</p>
    @else
        <form method="post" action="{{ route('users.import.store', 'tutor') }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-3">
                <div class="col-md-5">
                    <label for="school_year_id" class="form-label">Año escolar</label>
                    <select name="school_year_id" id="school_year_id" class="form-select" required>
                        @foreach($years as $year)
                            <option value="{{ $year->id }}" @selected((string) old('school_year_id') === (string) $year->id)>{{ $year->year }} · {{ $year->is_active ? 'Activo' : 'En preparación' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-7">
                    <label for="file" class="form-label">Archivo de Excel</label>
                    <input type="file" id="file" name="file" class="form-control" accept=".xlsx" required>
                    <div class="form-text">Hasta {{ \App\Services\AccountImportService::MAX_ROWS }} filas y 5 MB. Si alguna fila falla, se cancela la importación completa.</div>
                </div>
                <div class="col-12"><button class="btn btn-orange"><x-icon name="upload"/> Importar cuentas</button></div>
            </div>
        </form>
    @endif
</section>
@endsection
