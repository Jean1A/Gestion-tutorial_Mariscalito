$taskRoot = Split-Path -Parent $PSScriptRoot
$taskDocument = Join-Path $taskRoot 'docs\portal-parents.md'
$taskText = [System.Text.StringBuilder]::new()
[void]$taskText.AppendLine((Get-Content -LiteralPath (Join-Path $taskRoot 'docs\portal-parents-intro.md') -Raw))
$taskGroups = @(
    @{ Title = '3. Migraciones, modelos, seeders, factories, Policies y rutas'; Paths = @('database\migrations', 'database\seeders', 'database\factories', 'app\Enums', 'app\Models', 'app\Policies', 'routes') },
    @{ Title = '4. Controladores, Services, Form Requests, vistas y configuración'; Paths = @('app\Http', 'app\Services', 'app\Exceptions', 'app\Console', 'app\Providers', 'app\Jobs', 'app\Notifications', 'resources\views', 'lang', 'bootstrap\app.php', 'bootstrap\providers.php', 'config', '.env.example', 'composer.json') },
    @{ Title = '5. JavaScript, estilos y herramientas'; Paths = @('resources\js\app.js', 'resources\css\app.css', 'resources\css\portal.css', 'public\brand', 'public\icons', 'vite.config.js', 'package.json', 'scripts\php.ps1', 'scripts\composer.ps1', 'scripts\start-local.ps1', 'scripts\stop-local.ps1', 'scripts\initialize-demo.php', 'scripts\prepare-test-database.php', '.gitignore') },
    @{ Title = '6. Pruebas automatizadas'; Paths = @('tests', 'phpunit.xml', 'phpunit.mysql.xml') }
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
[void]$taskText.AppendLine((Get-Content -LiteralPath (Join-Path $taskRoot 'docs\portal-parents-validation.md') -Raw))
[System.IO.File]::WriteAllText($taskDocument, $taskText.ToString(), [System.Text.UTF8Encoding]::new($false))
Write-Output ('Entrega completa generada: ' + $taskDocument)
