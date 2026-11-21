$ErrorActionPreference = 'Stop'
$taskRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskPhp = Join-Path $taskRoot '.runtime\php\php.exe'
$taskArtisan = Join-Path $taskRoot 'artisan'
$taskRegistry = Join-Path $taskRoot '.runtime\dev-processes.json'
if (!(Test-Path -LiteralPath $taskRegistry)) { exit 0 }
foreach ($taskEntry in (Get-Content -LiteralPath $taskRegistry -Raw | ConvertFrom-Json)) {
    $taskProcess = Get-CimInstance Win32_Process -Filter "ProcessId=$($taskEntry.pid)" -ErrorAction SilentlyContinue
    if ($taskProcess -and $taskProcess.ExecutablePath -eq $taskPhp -and $taskProcess.CommandLine.Contains($taskArtisan)) {
        & taskkill.exe /PID $taskProcess.ProcessId /T /F | Out-Null
    }
}
Write-Output 'Procesos locales del proyecto detenidos.'
