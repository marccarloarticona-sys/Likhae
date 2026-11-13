$ErrorActionPreference = 'Stop'
$statusFile = Join-Path (Split-Path -Parent $PSScriptRoot) 'storage\logs\carlo-tunnel-install.status'
$tokenFile = 'C:\ProgramData\cloudflared\token'
$backupFile = 'C:\ProgramData\cloudflared\token.previous'
try {
    $command = Get-Clipboard -Raw
    if ($command -notmatch '^\s*cloudflared(?:\.exe)?\s+service\s+install\s+([A-Za-z0-9+/=_-]+)\s*$') {
        throw 'Clipboard does not contain the expected Cloudflare installation command.'
    }
    $newToken = $Matches[1]
    $decoded = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($newToken)) | ConvertFrom-Json
    if ($decoded.t -ne '55257981-72a5-42ee-8b0c-b4175ca38222') {
        throw 'Clipboard token belongs to a different tunnel.'
    }
    $original = [IO.File]::ReadAllText($tokenFile)
    [IO.File]::WriteAllText($backupFile, $original)
    Set-Acl -LiteralPath $backupFile -AclObject (Get-Acl -LiteralPath $tokenFile)
    [IO.File]::WriteAllText($tokenFile, $newToken)
    try {
        Set-Service Cloudflared -StartupType Automatic
        Restart-Service Cloudflared
        (Get-Service Cloudflared).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
    } catch {
        [IO.File]::WriteAllText($tokenFile, $original)
        Start-Service Cloudflared -ErrorAction SilentlyContinue
        throw 'Service restart failed; restored the previous token.'
    }
    Set-Clipboard -Value ' ' -ErrorAction SilentlyContinue
    'SUCCESS: Connected service to the dedicated CARLO tunnel.' | Set-Content -LiteralPath $statusFile
} catch {
    'FAILED: ' + $_.Exception.Message | Set-Content -LiteralPath $statusFile
    exit 1
}
