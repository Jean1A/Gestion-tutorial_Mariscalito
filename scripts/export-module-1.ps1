$taskRoot = Split-Path -Parent $PSScriptRoot
$taskDocument = Join-Path $taskRoot 'docs\phase-1-module-1.md'
$taskText = [System.Text.StringBuilder]::new()
[void]$taskText.AppendLine((Get-Content -LiteralPath (Join-Path $taskRoot 'docs\phase-1-module-1-intro.md') -Raw))
$taskGroups = @(
    @{ Title = '3. Migraciones, modelos, seeders, factories, Policies y rutas'; Paths = @('database\migrations', 'database\seeders', 'database\factories', 'app\Enums', 'app\Models', 'app\Policies', 'routes') },
    @{ Title = '4. Controladores, Services, Form Requests, middleware y vistas Blade'; Paths = @('app\Http', 'app\Services', 'app\Exceptions', 'app\Console', 'app\Providers', 'resources\views\layouts', 'resources\views\auth', 'resources\views\users', 'resources\views\students', 'resources\views\errors', 'resources\views\dashboard.blade.php', 'lang', 'bootstrap\app.php', 'config\app.php', '.env.example', 'composer.json') },
    @{ Title = '5. JavaScript, estilos y herramientas'; Paths = @('resources\js\app.js', 'resources\css\app.css', 'vite.config.js', 'package.json', 'scripts\php.ps1', 'scripts\composer.ps1', '.runtime\php\php.ini') },
    @{ Title = '6. Pruebas automatizadas'; Paths = @('tests', 'phpunit.xml') }
)
foreach ($taskGroup in $taskGroups) {
    [void]$taskText.AppendLine('## ' + $taskGroup.Title)
    [void]$taskText.AppendLine()
    foreach ($taskRelative in $taskGroup.Paths) {
        $taskPath = Join-Path $taskRoot $taskRelative
        $taskItem = Get-Item -LiteralPath $taskPath
        $taskFiles = if ($taskItem.PSIsContainer) { Get-ChildItem -LiteralPath $taskPath -File -Recurse | Sort-Object FullName } else { @($taskItem) }
        foreach ($taskFile in $taskFiles) {
            $taskLanguage = switch ($taskFile.Extension) { '.php' { 'php' } '.js' { 'javascript' } '.css' { 'css' } '.json' { 'json' } '.xml' { 'xml' } '.ps1' { 'powershell' } default { 'text' } }
            [void]$taskText.AppendLine('### ' + $taskFile.FullName)
            [void]$taskText.AppendLine()
            [void]$taskText.AppendLine('```' + $taskLanguage)
            [void]$taskText.AppendLine((Get-Content -LiteralPath $taskFile.FullName -Raw).TrimEnd())
            [void]$taskText.AppendLine('```')
            [void]$taskText.AppendLine()
        }
    }
}
[void]$taskText.AppendLine((Get-Content -LiteralPath (Join-Path $taskRoot 'docs\phase-1-module-1-outro.md') -Raw))
[System.IO.File]::WriteAllText($taskDocument, $taskText.ToString(), [System.Text.UTF8Encoding]::new($false))
Write-Output "Entrega completa del módulo: $taskDocument"
