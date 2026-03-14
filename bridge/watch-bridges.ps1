$python = "C:\Users\jfnfi\AppData\Local\Programs\Python\Python313\python.exe"
$bridgeA = "C:\Users\jfnfi\Documents\AI\Zeus\wp_bridge_local.py"
$bridgeB = "C:\Users\jfnfi\Documents\AI\Zeus\bridge_b.py"
$router  = "C:\Users\jfnfi\Documents\AI\Zeus\bridge_router.py"
$tri     = "C:\Users\jfnfi\Documents\AI\Zeus\bridge_triadapter.py"
$log     = "C:\Users\jfnfi\Documents\AI\Zeus\watch-bridges.log"

function Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content -Path $log -Value "[$ts] $msg"
}

function Test-Url($url) {
    try {
        $r = Invoke-RestMethod -Uri $url -TimeoutSec 3
        return ($r.status -eq 'ok' -or $r.router -eq 'up' -or $r.triadapter -eq 'up')
    } catch {
        return $false
    }
}

function Ensure-Process($pattern, $scriptPath) {
    $exists = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^python(\.exe)?$' -and $_.CommandLine -match $pattern } |
        Select-Object -First 1

    if (-not $exists) {
        Log "Missing process for $scriptPath; starting"
        Start-Process -FilePath $python -ArgumentList "`"$scriptPath`"" -WorkingDirectory (Split-Path $scriptPath) -WindowStyle Hidden
        Start-Sleep -Seconds 4
    }
}

function Restart-Script($pattern, $scriptPath, $label) {
    Log "$label unhealthy; restarting"
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^python(\.exe)?$' -and $_.CommandLine -match $pattern } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Seconds 2
    Start-Process -FilePath $python -ArgumentList "`"$scriptPath`"" -WorkingDirectory (Split-Path $scriptPath) -WindowStyle Hidden
    Start-Sleep -Seconds 4
}

Log "Bridge supervisor started"
while ($true) {
    try {
        Ensure-Process 'wp_bridge_local\.py' $bridgeA
        Ensure-Process 'bridge_b\.py' $bridgeB
        Ensure-Process 'bridge_router\.py' $router
        Ensure-Process 'bridge_triadapter\.py' $tri

        if (-not (Test-Url 'http://127.0.0.1:8787/health')) { Restart-Script 'wp_bridge_local\.py' $bridgeA 'Bridge A' }
        if (-not (Test-Url 'http://127.0.0.1:8790/health')) { Restart-Script 'bridge_b\.py' $bridgeB 'Bridge B' }
        if (-not (Test-Url 'http://127.0.0.1:8786/health')) { Restart-Script 'bridge_router\.py' $router 'Router' }
        if (-not (Test-Url 'http://127.0.0.1:8785/health')) { Restart-Script 'bridge_triadapter\.py' $tri 'Triadapter' }
    } catch {
        Log "Supervisor error: $($_.Exception.Message)"
    }

    Start-Sleep -Seconds 10
}

