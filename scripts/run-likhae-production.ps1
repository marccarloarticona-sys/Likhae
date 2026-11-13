$ErrorActionPreference = 'Stop'

$projectDir = Split-Path -Parent $PSScriptRoot
$php = 'C:\xampp\php\php.exe'
$apache = 'C:\xampp\apache\bin\httpd.exe'
$node = 'C:\Program Files\nodejs\node.exe'
$logDir = Join-Path $projectDir 'storage\logs'
$supervisorLog = Join-Path $logDir 'likhae-origin-supervisor.log'
$viteHotFile = Join-Path $projectDir 'storage\framework\vite.hot'
$ownedProcesses = @{}
$failureCounts = @{ '8000' = 0; '8001' = 0 }
$lastRecovery = @{}

New-Item -ItemType Directory -Force -Path $logDir | Out-Null
Set-Location $projectDir

$lock = $null
try {
    $lock = New-Object System.Threading.Mutex($false, 'Local\LikhaeOriginSupervisor')
    if (-not $lock.WaitOne(0)) { exit 0 }
} catch [System.Threading.AbandonedMutexException] {
    # An interrupted supervisor released its mutex during process teardown.
} catch {
    exit 0
}

function Write-SupervisorLog([string] $message) {
    "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $message" | Add-Content -LiteralPath $supervisorLog
}

function Test-TcpPort([int] $port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $connect = $client.ConnectAsync('127.0.0.1', $port)
        return $connect.Wait(1200) -and $client.Connected
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}

function Get-HttpStatus([int] $port) {
    $request = [System.Net.HttpWebRequest]::Create("http://127.0.0.1:$port/up")
    $request.Proxy = $null
    $request.Timeout = 5000
    $request.ReadWriteTimeout = 5000

    try {
        $response = $request.GetResponse()
        try { return [int] $response.StatusCode } finally { $response.Dispose() }
    } catch [System.Net.WebException] {
        if ($_.Exception.Response) {
            try { return [int] $_.Exception.Response.StatusCode } finally { $_.Exception.Response.Dispose() }
        }
        return 0
    } catch {
        return 0
    }
}

function Test-WebPort([int] $port) {
    return (Get-HttpStatus $port) -eq 200
}

function Get-ListeningProcess([int] $port) {
    foreach ($line in (netstat -ano -p tcp)) {
        if ($line -match "^\s*TCP\s+\S+:$port\s+\S+\s+LISTENING\s+(\d+)\s*$") {
            $processId = [int] $Matches[1]
            $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
            if ($process) {
                return [pscustomobject]@{ Id = $processId; Name = $process.ProcessName; Path = $process.Path }
            }
        }
    }
    return $null
}

function Start-ManagedProcess([string] $name, [string] $file, [string[]] $arguments) {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $stdout = Join-Path $logDir "likhae-$name-$stamp.out.log"
    $stderr = Join-Path $logDir "likhae-$name-$stamp.err.log"
    $process = Start-Process -FilePath $file -ArgumentList $arguments -WorkingDirectory $projectDir `
        -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
    $ownedProcesses[$name] = $process
    Write-SupervisorLog "Started $name (PID $($process.Id))."
    return $process
}

function Ensure-Process([string] $name, [int] $port, [string] $launchFile, [string] $expectedListenerPath, [string[]] $arguments, [bool] $http) {
    $healthy = if ($http) { Test-WebPort $port } else { Test-TcpPort $port }
    if ($healthy) {
        $failureCounts[[string] $port] = 0
        return
    }

    $listener = Get-ListeningProcess $port
    if ($listener) {
        $failureCounts[[string] $port] += 1
        if ($failureCounts[[string] $port] -lt 3) { return }

        $expectedPath = [System.IO.Path]::GetFullPath($expectedListenerPath)
        $actualPath = if ($listener.Path) { [System.IO.Path]::GetFullPath($listener.Path) } else { '' }
        if ($listener.Name -notin @('php', 'httpd') -or $actualPath -ne $expectedPath) {
            Write-SupervisorLog "Port $port is occupied by an unexpected process; leaving it untouched."
            $failureCounts[[string] $port] = 0
            return
        }

        $last = $lastRecovery[[string] $port]
        if ($last -and ((Get-Date) - $last).TotalSeconds -lt 60) { return }

        Write-SupervisorLog "Port $port failed its health check three times; restarting the expected $($listener.Name) listener (PID $($listener.Id))."
        & taskkill.exe /PID $listener.Id /T /F 2>$null | Out-Null
        $lastRecovery[[string] $port] = Get-Date
        $failureCounts[[string] $port] = 0
        return
    }

    $failureCounts[[string] $port] = 0
    $managed = $ownedProcesses[$name]
    if ($managed -and -not $managed.HasExited) {
        return
    }

    $last = $lastRecovery[[string] $port]
    if ($last -and ((Get-Date) - $last).TotalSeconds -lt 15) { return }
    $lastRecovery[[string] $port] = Get-Date
    [void] (Start-ManagedProcess $name $launchFile $arguments)
}

if (-not (Test-Path -LiteralPath $php)) {
    throw "PHP was not found at $php."
}
if (-not (Test-Path -LiteralPath $apache)) {
    throw "Apache was not found at $apache."
}

$laravelArgs = @('artisan', 'serve:uploads', '--host=127.0.0.1', '--port=8001', '--tries=1', '--no-reload')
$reverbArgs = @('artisan', 'reverb:start', '--host=0.0.0.0', '--port=8080')
$apacheArgs = @('scripts/run-apache-proxy.php')
$viteArgs = @('node_modules/vite/bin/vite.js', '--host=0.0.0.0', '--port=5173', '--strictPort')

Write-SupervisorLog 'Supervisor started. Cloudflare Tunnel public origin remains http://127.0.0.1:8000; Laravel and Reverb are private upstreams.'

while ($true) {
    Ensure-Process 'laravel' 8001 $php $php $laravelArgs $true
    Ensure-Process 'reverb' 8080 $php $php $reverbArgs $false
    if ((Test-Path -LiteralPath $viteHotFile) -and (Test-Path -LiteralPath $node)) {
        Ensure-Process 'vite' 5173 $node $node $viteArgs $false
    }
    if (Test-WebPort 8001) {
        Ensure-Process 'apache' 8000 $php $apache $apacheArgs $true
    } else {
        Write-SupervisorLog 'Waiting for the Laravel upstream on port 8001 before checking Apache on public port 8000.'
    }
    Start-Sleep -Seconds 10
}
