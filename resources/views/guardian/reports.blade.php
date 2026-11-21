@extends('layouts.guardian')
@section('title','Boletas de notas')
@section('content')
<x-back-link/>
<h1>Boletas de notas</h1><p class="portal-subtitle">Consulta y descarga el historial académico de tu hijo.</p><x-child-banner :child="$child" :enrollment="$enrollment"/>
<section class="portal-panel"><div class="portal-panel__heading"><x-icon name="file-earmark-text"/><div><h2>Historial de boletas</h2><p>Información de los niveles de logro publicados.</p></div></div><div class="report-list">@forelse($enrollments as $item)<article class="report-row"><div class="course-symbol"><x-icon name="file-earmark-text"/></div><div class="report-row__title"><h3>{{ $item->section->grade->name }}</h3><p>{{ $item->section->schoolYear->year }} · Boleta informativa</p></div><div class="report-row__actions"><a class="btn btn-outline-primary" href="{{ route('portal.report',[$child,$item->section->schoolYear]) }}"><x-icon name="eye"/>Ver boleta</a><a class="btn btn-orange" href="{{ route('portal.report.pdf',[$child,$item->section->schoolYear]) }}"><x-icon name="download"/>Descargar PDF</a></div></article>@empty<div class="empty-state">Todavía no hay matrículas para mostrar.</div>@endforelse</div><p class="print-note">Este documento es informativo. No reemplaza SIAGIE ni las libretas oficiales.</p></section>
@endsection
