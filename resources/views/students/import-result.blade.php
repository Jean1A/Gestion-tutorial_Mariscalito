@extends('layouts.app')
@section('title', 'Resultado de importación')
@section('content')
<a class="back-link" href="{{ route('imports.index') }}"><x-icon name="arrow-left"/> Volver a Importar estudiantes</a>
<h1>Importación #{{ $import->id }}</h1>
<p class="portal-subtitle">{{ ['queued' => 'En cola', 'processing' => 'Procesando', 'completed' => 'Completada', 'failed' => 'Fallida'][$import->status] }} · {{ $import->imported_count }} filas correctas · {{ $import->error_count }} con errores</p>
@if($import->failure_message)
    <x-notice tone="warning" title="Revisa la importación">
        <p>{{ $import->failure_message }}</p>
    </x-notice>
@endif

@if($hasAccess)
    <section class="portal-panel">
        <div class="portal-panel__heading"><x-icon name="lock"/><h2>Accesos de las cuentas nuevas</h2></div>
        <p>Esta lista está disponible durante {{ \App\Models\StudentImport::ACCESS_HOURS }} horas para la cuenta que hizo la carga. Entrega a cada persona solo su acceso. Deberá cambiar su contraseña al ingresar.</p>
        <a class="btn btn-orange" href="{{ route('imports.access', $import) }}"><x-icon name="download"/> Descargar accesos temporales</a>
    </section>
@endif

<section class="portal-panel">
    <div class="panel-title-row">
        <div class="portal-panel__heading"><x-icon name="file-earmark-text"/><h2>Resultado por fila</h2></div>
        @if(in_array($import->status, ['queued', 'processing'], true))
            <a class="btn btn-outline-primary" href="{{ route('imports.show', $import) }}"><x-icon name="arrow-repeat"/> Actualizar estado</a>
        @endif
    </div>
    @if($rows->isNotEmpty())
        <div class="table-responsive">
            <table class="portal-table">
                <thead><tr><th scope="col">Fila en Excel</th><th scope="col">Qué revisar</th></tr></thead>
                <tbody>@foreach($rows as $row)<tr><td>{{ $row->row_number }}</td><td>{{ $row->error }}</td></tr>@endforeach</tbody>
            </table>
        </div>
        @if($rows->hasPages())<div class="mt-3">{{ $rows->links() }}</div>@endif
    @elseif($import->status === 'completed')
        <p class="mb-0">Todas las filas se procesaron correctamente.</p>
    @elseif($import->status !== 'failed')
        <p class="mb-0">Puedes actualizar esta página para ver el avance.</p>
    @endif
</section>
@endsection
