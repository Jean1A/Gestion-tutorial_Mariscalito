$taskComposer = Join-Path $env:USERPROFILE 'composer\composer.phar'
if (-not (Test-Path -LiteralPath $taskComposer)) { throw 'No se encontró composer.phar. Ajusta la ruta en scripts/composer.ps1.' }
& "$PSScriptRoot\..\.runtime\php\php.exe" $taskComposer @args
exit $LASTEXITCODE
