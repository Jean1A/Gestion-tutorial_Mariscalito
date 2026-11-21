import 'bootstrap';

document.querySelectorAll('[data-coordination-filters]').forEach(form => {
    form.querySelector('[data-coordination-year]')?.addEventListener('change', event => {
        const option = event.target.selectedOptions[0];
        for (const name of ['section_id', 'period_id']) {
            const select = form.querySelector(`[name="${name}"]`);
            if (select) select.value = '';
        }
        for (const name of ['from', 'to']) {
            const input = form.querySelector(`[name="${name}"]`);
            if (!input) continue;
            input.min = option.dataset.start;
            input.max = option.dataset.end;
            input.value = name === 'from' ? option.dataset.start : option.dataset.end;
        }
        form.requestSubmit();
    });
});

document.querySelectorAll('[data-enrollment-batch]').forEach(form => {
    const selectAll = form.querySelector('[data-select-batch]');
    const rows = [...form.querySelectorAll('[name="enrollments[]"]')];
    const updateSelection = () => {
        const selected = rows.filter(row => row.checked).length;
        selectAll.checked = selected === rows.length;
        selectAll.indeterminate = selected > 0 && selected < rows.length;
    };
    selectAll.addEventListener('change', () => {
        rows.forEach(row => { row.checked = selectAll.checked; });
    });
    rows.forEach(row => row.addEventListener('change', updateSelection));
    updateSelection();
});

document.querySelectorAll('[data-toggle-password]').forEach(button => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.togglePassword);
        if (!input) return;
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
});

const loginForm = document.querySelector('[data-login-form]');
if (loginForm) {
    const dni = loginForm.querySelector('#dni');
    const remember = loginForm.querySelector('#remember-dni');
    try {
        const saved = localStorage.getItem('mariscalito.remembered-dni');
        if (saved && /^[0-9]{8}$/.test(saved) && !dni.value) { dni.value = saved; remember.checked = true; }
        remember.addEventListener('change', () => { if (!remember.checked) localStorage.removeItem('mariscalito.remembered-dni'); });
        loginForm.addEventListener('submit', () => {
            if (remember.checked && /^[0-9]{8}$/.test(dni.value)) localStorage.setItem('mariscalito.remembered-dni', dni.value);
            else localStorage.removeItem('mariscalito.remembered-dni');
        });
    } catch { /* El acceso sigue funcionando si el navegador bloquea el almacenamiento local. */ }
}

const loginLockout = document.querySelector('[data-login-lockout]');
if (loginLockout) {
    const form = document.querySelector('#login-credentials');
    const dni = form.elements.dni;
    const submit = form.querySelector('[type="submit"]');
    const seconds = loginLockout.querySelector('[data-login-lockout-seconds]');
    const lockedDni = dni.defaultValue;
    const deadline = performance.now() + Number(loginLockout.dataset.loginLockout) * 1000;

    const updateLockout = () => {
        const remaining = Math.max(0, Math.ceil((deadline - performance.now()) / 1000));
        const isLocked = remaining > 0 && dni.value.trim() === lockedDni;
        seconds.textContent = remaining;
        loginLockout.hidden = !isLocked;
        submit.disabled = isLocked;

        if (remaining === 0) {
            window.clearInterval(countdown);
            dni.removeEventListener('input', updateLockout);
        }
    };

    const countdown = window.setInterval(updateLockout, 250);
    dni.addEventListener('input', updateLockout);
    updateLockout();
}

document.querySelectorAll('[data-private-upload]').forEach(input => input.addEventListener('change', () => {
    const label = input.closest('.upload-zone')?.querySelector('[data-upload-name]');
    const file = input.files?.[0];
    input.setCustomValidity(file && file.size > 5 * 1024 * 1024 ? 'El archivo no puede superar los 5 MB.' : '');
    if (label) label.textContent = file ? file.name : '';
    input.reportValidity();
}));

document.querySelector('[data-add-lesson]')?.addEventListener('click', () => {
    const container = document.querySelector('[data-lessons]');
    const index = container.querySelectorAll('[data-lesson]').length;
    if (index >= 20) return;
    const copy = container.querySelector('[data-lesson]').cloneNode(true);
    const title = copy.querySelector('[data-lesson-title]');
    const content = copy.querySelector('[data-lesson-content]');
    title.value = ''; title.name = `lessons[${index}][title]`; title.id = `lesson-title-${index}`;
    content.value = ''; content.name = `lessons[${index}][content]`; content.id = `lesson-content-${index}`;
    copy.querySelectorAll('label')[0].htmlFor = title.id;
    copy.querySelectorAll('label')[0].textContent = `Título del contenido ${index + 1}`;
    copy.querySelectorAll('label')[1].htmlFor = content.id;
    container.append(copy); title.focus();
});

document.querySelectorAll('[data-family-forum]').forEach(form => {
    const select = form.querySelector('[data-forum-topic]');
    select?.addEventListener('change', () => {
        const option = select.selectedOptions[0];
        if (!option?.dataset.postUrl) return;
        form.action = option.dataset.postUrl;
        form.querySelector('[data-forum-conversation]').href = option.dataset.conversationUrl;
    });
});

document.querySelectorAll('[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});

document.querySelectorAll('[data-attendance-form]').forEach(form => {
    form.querySelector('[data-mark-present]')?.addEventListener('click', () => {
        const time = form.querySelector('#base-time').value;
        if (!time) { window.alert('Indica una hora de ingreso.'); return; }
        form.querySelectorAll('[data-attendance-row]').forEach(row => {
            const status = row.querySelector('[data-status]');
            if (!status.value) { status.value = 'present'; row.querySelector('[data-arrival]').value = time; }
        });
    });
    form.querySelectorAll('[data-status]').forEach(status => status.addEventListener('change', () => {
        const time = status.closest('tr').querySelector('[data-arrival]');
        if (!['present', 'late'].includes(status.value)) time.value = '';
    }));
});

document.querySelectorAll('[data-communication-form]').forEach(form => {
    const type = form.querySelector('#type');
    const target = form.querySelector('#target');
    const updateFields = () => {
        const appointment = type.value === 'appointment';
        if (appointment) target.value = 'student';
        Array.from(target.options).forEach(option => { option.disabled = appointment && option.value !== 'student'; });
        form.querySelectorAll('[data-target-field]').forEach(group => {
            const active = group.dataset.targetField === target.value;
            group.hidden = !active;
            group.querySelectorAll('input,select').forEach(input => { input.disabled = !active; input.required = active; });
        });
        form.querySelectorAll('[data-appointment-field]').forEach(group => {
            group.hidden = !appointment;
            group.querySelectorAll('input').forEach(input => { input.disabled = !appointment; input.required = appointment; });
        });
    };
    type.addEventListener('change', updateFields);
    target.addEventListener('change', updateFields);
    updateFields();
});

// Ficha tutorial: enviar al estudiante seleccionado sin incluir identificadores ajenos.
document.querySelectorAll('[data-support-profile]').forEach(form => {
 form.addEventListener('submit', () => { form.action = form.querySelector('[data-profile-student]').value; });
});

document.querySelectorAll('[data-session-roster]').forEach(form => {
 const section = form.querySelector('[data-section-select]');
 const updateRoster = () => form.querySelectorAll('[data-session-attendee]').forEach(label => {
  const active = JSON.parse(label.dataset.sections).includes(Number(section.value));
  label.hidden = !active;
  const input = label.querySelector('input');
  input.disabled = !active;
  if (!active) input.checked = false;
 });
 section.addEventListener('change', updateRoster);
 updateRoster();
});
