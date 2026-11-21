<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalendarFilterRequest;
use App\Http\Requests\CalendarRequest;
use App\Models\SchoolDay;
use App\Models\SchoolYear;
use App\Services\CalendarService;
use Carbon\Carbon;

class CalendarController extends Controller
{
    public function index(CalendarFilterRequest $request)
    {
        $years = SchoolYear::available()->orderByDesc('year')->get();
        $yearId = $request->validated('school_year_id') ?? $years->first()?->id;
        $month = $request->validated('month');
        $days = SchoolDay::where('school_year_id', $yearId)->when($month, fn ($q) => $q->whereBetween('date', [$month.'-01', Carbon::parse($month.'-01')->endOfMonth()->toDateString()]))->orderBy('date')->paginate(31)->withQueryString();

        return view('academics.calendar', compact('years', 'yearId', 'days', 'month'));
    }

    public function generate(CalendarRequest $request, CalendarService $service)
    {
        $service->generate(SchoolYear::findOrFail($request->validated('school_year_id')), $request->validated('week_days'));

        return back()->with('status', 'Calendario generado. Los días personalizados existentes se conservaron. Revisa y marca los feriados.');
    }

    public function save(CalendarRequest $request, CalendarService $service)
    {
        $service->save($request->validated());

        return back()->with('status', 'Día actualizado. Los cálculos excluirán los días no lectivos sin borrar la asistencia histórica.');
    }
}
