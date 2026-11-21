# MARISCAL CASTILLA · tutoría escolar

Sistema web de tutoría y orientación educativa de la I.E.I. Mariscal Castilla. Incluye siete perfiles: administrador, coordinador de tutoría, tutor, docente, auxiliar, apoderado y estudiante.

Permite gestionar años escolares, grados y secciones, matrículas e importaciones Excel, asistencia, calificaciones, justificaciones, citaciones, intervenciones, sesiones de tutoría, comunicaciones y reportes. El portal del estudiante incorpora actividades de tutoría, bitácora personal, buzón de orientación y metas de estudio.

Desarrollado con Laravel 13, PHP 8.5, Blade, Bootstrap 5.3, Vite y MySQL.

MySQL local: `127.0.0.1:3308`, base exclusiva `school_tutoring`. Credenciales únicamente en `.env`, excluido de Git. Workbench administra el servidor; la aplicación se conecta directamente a MySQL.

## Instalación

Instala PHP 8.5 con las extensiones requeridas por Composer, Composer, Node.js compatible con Vite 8 y MySQL. Los ejecutables locales, las dependencias instaladas, los documentos de tesis y los datos de la aplicación no se incluyen en el repositorio.

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
npm ci --ignore-scripts
npm run build
```

Crea una base MySQL y configura su conexión en `.env` antes de ejecutar:

```powershell
php artisan migrate
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=ApprovedSettingsSeeder
php artisan db:seed --class=PortalPermissionSeeder
```

Los usuarios y sus contraseñas se administran en cada instalación. El repositorio no incluye cuentas de acceso de la base local.

## Ejecutar en desarrollo

En terminales separadas:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work
php artisan schedule:work
```

Abre http://127.0.0.1:8000. El administrador configura días lectivos y feriados antes de registrar asistencia. El apoderado acepta expresamente el consentimiento.

En la instalación original de Windows, que dispone de PHP portable en `.runtime/php`, también se puede iniciar con:

```powershell
.\scripts\start-local.ps1
```

El script inicia servidor, cola y scheduler en segundo plano; logs e identificadores en `.runtime`. Para detener solo los procesos del proyecto:

```powershell
.\scripts\stop-local.ps1
```

## Verificar

```powershell
php artisan test --compact
php scripts/prepare-test-database.php
php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml
npm run build
```

Las pruebas MySQL reinician exclusivamente `school_tutoring_test`; una protección rechaza otras bases. La suite ordinaria usa SQLite en memoria. No se reinicia la base principal.

El correo local usa `MAIL_MAILER=log`. Para una instalación operativa, configura SMTP y los servicios externos contratados por el colegio. La política de privacidad requiere adopción institucional antes de producción.
