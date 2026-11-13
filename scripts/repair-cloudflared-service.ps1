$ErrorActionPreference = 'Stop'

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from PowerShell as Administrator.'
}

$serviceKey = 'HKLM:\SYSTEM\CurrentControlSet\Services\Cloudflared'
$original = (Get-ItemProperty -LiteralPath $serviceKey).ImagePath
$executable = 'C:\Program Files (x86)\cloudflared\cloudflared.exe'
$tokenFile = 'C:\ProgramData\cloudflared\token'
$logFile = 'C:\ProgramData\cloudflared\cloudflared.log'
if (-not (Test-Path -LiteralPath $executable)) { throw 'Cloudflared executable is missing.' }
if (-not (Test-Path -LiteralPath $tokenFile)) { throw 'Existing tunnel token file is missing.' }
if ($original -notlike '*--token-file*C:\ProgramData\cloudflared\token*') {
    throw 'Unexpected service configuration. Refusing to replace it.'
}

$newPath = '"{0}" tunnel --loglevel info --logfile "{1}" run --token-file "{2}"' -f $executable, $logFile, $tokenFile
try {
    Set-ItemProperty -LiteralPath $serviceKey -Name ImagePath -Value $newPath
    Restart-Service -Name Cloudflared
    (Get-Service Cloudflared).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
    Write-Output "Tunnel restarted. Persistent logs: $logFile"
} catch {
    Set-ItemProperty -LiteralPath $serviceKey -Name ImagePath -Value $original
    Start-Service -Name Cloudflared -ErrorAction SilentlyContinue
    throw
}
