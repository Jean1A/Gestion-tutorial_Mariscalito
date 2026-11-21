$ErrorActionPreference = 'Stop'
$taskRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskPhp = Join-Path $taskRoot '.runtime\php\php.exe'
$taskArtisan = Join-Path $taskRoot 'artisan'
$taskTempPath = Join-Path $taskRoot '.runtime\tmp'
$taskPhpIni = Join-Path $taskRoot '.runtime\php\php.ini'
[void][System.IO.Directory]::CreateDirectory($taskTempPath)
$taskIniContents = [System.IO.File]::ReadAllText($taskPhpIni)
$taskIniBase = [regex]::Replace($taskIniContents, '(?m)^[ \t]*(?:upload_tmp_dir|sys_temp_dir)[ \t]*=[^\r\n]*(?:\r?\n|$)', '')
$taskTempIniPath = $taskTempPath.Replace('\', '/')
$taskIniUpdated = @(
    $taskIniBase.TrimEnd()
    "upload_tmp_dir=""$taskTempIniPath"""
    "sys_temp_dir=""$taskTempIniPath"""
    ''
) -join [Environment]::NewLine
if ($taskIniUpdated -ne $taskIniContents) {
    [System.IO.File]::WriteAllText($taskPhpIni, $taskIniUpdated, [System.Text.UTF8Encoding]::new($false))
}
$taskRegistry = Join-Path $taskRoot '.runtime\dev-processes.json'
$taskOld = if (Test-Path -LiteralPath $taskRegistry) { Get-Content -LiteralPath $taskRegistry -Raw | ConvertFrom-Json } else { @() }
$taskServices = @(
    @{ Name = 'web'; Arguments = @('serve', '--host=127.0.0.1', '--port=8000') },
    @{ Name = 'queue'; Arguments = @('queue:work', '--sleep=2', '--tries=3', '--timeout=180') },
    @{ Name = 'scheduler'; Arguments = @('schedule:work') }
)
$taskRegistryEntries = @()
foreach ($taskService in $taskServices) {
    $taskPrevious = $taskOld | Where-Object { $_.name -eq $taskService.Name } | Select-Object -First 1
    $taskProcess = if ($taskPrevious) { Get-CimInstance Win32_Process -Filter "ProcessId=$($taskPrevious.pid)" -ErrorAction SilentlyContinue } else { $null }
    if ($taskProcess -and $taskProcess.ExecutablePath -eq $taskPhp -and $taskProcess.CommandLine.Contains($taskArtisan)) {
        $taskRegistryEntries += $taskPrevious
        continue
    }
    $taskCommandPattern = '(?i)(?:^|\s)(?:"[^"]*\\artisan"|artisan)\s+' + [regex]::Escape($taskService.Arguments[0]) + '(?=\s|$)'
    $taskExisting = Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
        $_.ExecutablePath -eq $taskPhp -and $_.CommandLine -match $taskCommandPattern -and
        ($taskService.Name -ne 'web' -or $_.CommandLine -match '(?:^|\s)--port=8000(?:\s|$)')
    } | Select-Object -First 1
    if ($taskExisting) {
        $taskRegistryEntries += @{ name = $taskService.Name; pid = $taskExisting.ProcessId }
        continue
    }
    if ($taskService.Name -eq 'web' -and (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue)) {
        throw 'El puerto 8000 ya está ocupado. No se inició otro servidor.'
    }
    $taskArguments = @('"' + $taskArtisan + '"') + $taskService.Arguments
    $taskStarted = Start-Process -FilePath $taskPhp -ArgumentList $taskArguments -WorkingDirectory $taskRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRoot ".runtime\dev-$($taskService.Name).log") -RedirectStandardError (Join-Path $taskRoot ".runtime\dev-$($taskService.Name)-error.log")
    $taskRegistryEntries += @{ name = $taskService.Name; pid = $taskStarted.Id }
    $taskRegistryEntries | ConvertTo-Json | Set-Content -LiteralPath $taskRegistry -Encoding utf8
}
Write-Output 'Entorno local iniciado: http://127.0.0.1:8000 · cola y scheduler activos.'
