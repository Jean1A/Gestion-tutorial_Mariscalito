<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Caso de tutoría</title>
    @vite(['resources/css/app.css'])
</head>
<body class="portal-body">
    <main class="portal-main mx-auto">
        <x-back-link :href="route('student-tutoring.staff.activity', $activity->id)">Volver a la actividad</x-back-link>
        <section class="portal-panel mt-4">
            <h1>Caso para conversar</h1>
            <p class="content-copy fs-3 mt-4">{{ $activity->prompt }}</p>
        </section>
    </main>
</body>
</html>
