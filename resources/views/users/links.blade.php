@if($showTutorLinks)
    <section class="portal-panel mt-3">
        <div class="portal-panel__heading"><x-icon name="mortarboard"/><h2>Secciones del tutor</h2></div>
        @forelse($tutorSections as $section)
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                <span>{{ $section->schoolYear->year }} · {{ $section->grade->educationLevel->name }} · {{ $section->grade->name }} {{ $section->name }}</span>
                <form method="post" action="{{ route('assignments.save') }}" data-confirm="¿Quitar esta sección al tutor?">
                    @csrf
                    <input type="hidden" name="type" value="tutor">
                    <input type="hidden" name="user_dni" value="{{ $subject->dni }}">
                    <input type="hidden" name="section_id" value="{{ $section->id }}">
                    <input type="hidden" name="is_active" value="0">
                    <button class="btn btn-sm btn-outline-secondary">Quitar sección</button>
                </form>
            </div>
        @empty
            <p>Este tutor aún no tiene secciones asignadas.</p>
        @endforelse
        @if($sections->isNotEmpty())
            <form class="portal-filters mt-3" method="post" action="{{ route('assignments.save') }}" data-confirm="¿Asignar esta sección a este tutor? Si hay otro responsable, será reemplazado.">
                @csrf
                <input type="hidden" name="type" value="tutor">
                <input type="hidden" name="user_dni" value="{{ $subject->dni }}">
                <input type="hidden" name="is_active" value="1">
                <div><label for="tutor-section">Añadir sección</label><select name="section_id" id="tutor-section" class="form-select" required>
                    <option value="">Selecciona una sección</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}">{{ $section->schoolYear->year }} · {{ $section->grade->educationLevel->name }} · {{ $section->grade->name }} {{ $section->name }}</option>
                    @endforeach
                </select></div>
                <button class="btn btn-outline-primary">Asignar sección</button>
            </form>
        @endif
    </section>
@endif

@if($showGuardianLinks)
    <section class="portal-panel mt-3">
        <div class="portal-panel__heading"><x-icon name="people"/><h2>Estudiantes vinculados</h2></div>
        @forelse($linkedStudents as $student)
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <span>{{ $student->full_name }} · DNI {{ $student->dni }} · {{ $student->pivot->relationship }}</span>
                <form method="post" action="{{ route('assignments.save') }}" data-confirm="¿Retirar el vínculo de este apoderado con el estudiante?">
                    @csrf
                    <input type="hidden" name="type" value="guardian">
                    <input type="hidden" name="user_dni" value="{{ $subject->dni }}">
                    <input type="hidden" name="student_dni" value="{{ $student->dni }}">
                    <input type="hidden" name="relationship" value="{{ $student->pivot->relationship }}">
                    <input type="hidden" name="is_active" value="0">
                    <button class="btn btn-sm btn-outline-secondary">Retirar vínculo</button>
                </form>
            </div>
        @empty
            <p>Este apoderado aún no tiene estudiantes vinculados.</p>
        @endforelse
        <form method="post" action="{{ route('assignments.save') }}" class="row g-3 mt-1">
            @csrf
            <input type="hidden" name="type" value="guardian">
            <input type="hidden" name="user_dni" value="{{ $subject->dni }}">
            <input type="hidden" name="is_active" value="1">
            <div class="col-md-5"><label for="student_dni" class="form-label">DNI del estudiante</label><input id="student_dni" name="student_dni" class="form-control" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" required></div>
            <div class="col-md-5"><label for="relationship" class="form-label">Parentesco</label><input id="relationship" name="relationship" class="form-control" maxlength="60" required></div>
            <div class="col-12"><div class="form-check"><input id="link-verified" name="identity_verified" type="checkbox" value="1" class="form-check-input" required><label for="link-verified" class="form-check-label">Verifiqué la identidad y el vínculo.</label></div></div>
            <div class="col-12"><button class="btn btn-outline-primary">Vincular estudiante</button></div>
        </form>
    </section>
@endif
