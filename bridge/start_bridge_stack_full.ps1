$python = "C:\Users\jfnfi\AppData\Local\Programs\Python\Python313\python.exe"
$hub     = "C:\Users\jfnfi\Documents\AI\Zeus\bridge_state_hub.py"
$bridgeA = "C:\Users\jfnfi\Documents\AI\Zeus\wp_bridge_local.py"
$bridgeB = "C:\Users\jfnfi\Documents\AI\Zeus\bridge_b.py"
$router  = "C:\Users\jfnfi\Documents\AI\Zeus\bridge_router.py"
$tri     = "C:\Users\jfnfi\Documents\AI\Zeus\bridge_triadapter.py"
$log     = "C:\Users\jfnfi\Documents\AI\Zeus\start_bridge_stack_full.log"

function Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content -Path $log -Value "[$ts] $msg"
}

function Kill-Matching($pattern) {
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object {
            $_.Name -match '^python(\.exe)?$' -and $_.CommandLine -match $pattern
        } |
        ForEach-Object {
            try {
                Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
                Log "Stopped PID $($_.ProcessId) for pattern $pattern"
            } catch {}
        }
}

Log "Starting full bridge stack"
Kill-Matching 'bridge_state_hub\.py'
Kill-Matching 'bridge_triadapter\.py'
Kill-Matching 'bridge_router\.py'
Kill-Matching 'bridge_b\.py'
Kill-Matching 'wp_bridge_local\.py'
Start-Sleep -Seconds 2

Start-Process -FilePath $python -ArgumentList "`"$hub`"" -WorkingDirectory (Split-Path $hub) -WindowStyle Hidden
Log "Started state hub on 8784"
Start-Sleep -Seconds 2

Start-Process -FilePath $python -ArgumentList "`"$bridgeA`"" -WorkingDirectory (Split-Path $bridgeA) -WindowStyle Hidden
Log "Started bridge A on 8787"
Start-Sleep -Seconds 3

Start-Process -FilePath $python -ArgumentList "`"$bridgeB`"" -WorkingDirectory (Split-Path $bridgeB) -WindowStyle Hidden
Log "Started bridge B on 8790"
Start-Sleep -Seconds 3

Start-Process -FilePath $python -ArgumentList "`"$router`"" -WorkingDirectory (Split-Path $router) -WindowStyle Hidden
Log "Started router on 8786"
Start-Sleep -Seconds 3

Start-Process -FilePath $python -ArgumentList "`"$tri`"" -WorkingDirectory (Split-Path $tri) -WindowStyle Hidden
Log "Started triadapter on 8785"

