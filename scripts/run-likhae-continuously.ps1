$ErrorActionPreference = 'Continue'

$projectDir = Split-Path -Parent $PSScriptRoot
$logDir = Join-Path $projectDir 'storage\logs\dev'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null

Set-Location $projectDir

$env:SERVER_HOST = '0.0.0.0'
$env:SERVER_PORT = '8001'
$env:REVERB_SERVER_HOST = '0.0.0.0'
$env:REVERB_SERVER_PORT = '8080'

while ($true) {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $logPath = Join-Path $logDir "artisan-dev-$stamp.log"

    "[$(Get-Date -Format s)] Starting Laravel, Apache proxy, Reverb, and Vite." | Add-Content $logPath
    & php artisan serve *>> $logPath
    $exitCode = $LASTEXITCODE
    "[$(Get-Date -Format s)] Laravel development services exited with code $exitCode. Restarting in 5 seconds." | Add-Content $logPath
    Start-Sleep -Seconds 5
}
