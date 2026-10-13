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
    # The Return & Refund form accepts up to 8 MB images and a 200 MB video.
    # PHP's CLI SAPI does not read public/.user.ini, so apply the same limits
    # explicitly when the built-in development server is used.
    & php -d upload_max_filesize=200M -d post_max_size=512M -d max_file_uploads=10 -d max_input_time=300 -d max_execution_time=300 artisan serve *>> $logPath
    $exitCode = $LASTEXITCODE
    "[$(Get-Date -Format s)] Laravel development services exited with code $exitCode. Restarting in 5 seconds." | Add-Content $logPath
    Start-Sleep -Seconds 5
}
