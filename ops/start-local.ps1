$ErrorActionPreference = 'Stop'
$eqlRoot = Split-Path $PSScriptRoot -Parent
$eqlRuntime = Get-Content -LiteralPath "$PSScriptRoot\local-runtime.json" -Raw | ConvertFrom-Json
foreach ($eqlPathKey in @('databaseExecutable', 'databaseConfig', 'clientExecutable', 'clientConfig', 'logDirectory')) {
    if (-not [IO.Path]::IsPathRooted($eqlRuntime.$eqlPathKey)) {
        $eqlRuntime.$eqlPathKey = [IO.Path]::GetFullPath((Join-Path $eqlRoot $eqlRuntime.$eqlPathKey))
    }
}
if (-not (Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort $eqlRuntime.databasePort -State Listen -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath $eqlRuntime.databaseExecutable -ArgumentList "--defaults-file=$($eqlRuntime.databaseConfig)" -WindowStyle Hidden | Out-Null
}
if (Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort $eqlRuntime.httpPort -State Listen -ErrorAction SilentlyContinue) {
    Write-Output "A server is already listening at http://127.0.0.1:$($eqlRuntime.httpPort)"
    exit 0
}
$eqlPhp = (Get-Command php -ErrorAction Stop).Source
$env:EQL_WIKI_URL = "http://127.0.0.1:$($eqlRuntime.backendHttpPort)"
if (-not (Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort $eqlRuntime.backendHttpPort -State Listen -ErrorAction SilentlyContinue)) {
    $eqlBackend = Start-Process -FilePath $eqlPhp -ArgumentList @('-d', 'memory_limit=512M', '-d', 'max_execution_time=600', '-S', "127.0.0.1:$($eqlRuntime.backendHttpPort)", '-t', $eqlRoot, "$PSScriptRoot\local-router.php") -WorkingDirectory $eqlRoot -WindowStyle Hidden -RedirectStandardOutput "$($eqlRuntime.logDirectory)\php-backend-output.log" -RedirectStandardError "$($eqlRuntime.logDirectory)\php-backend-error.log" -PassThru
    $eqlBackend.Id | Set-Content -LiteralPath "$($eqlRuntime.logDirectory)\php-backend.pid"
}
$eqlPhpProcess = Start-Process -FilePath $eqlPhp -ArgumentList @('-d', 'memory_limit=512M', '-d', 'max_execution_time=600', '-S', "127.0.0.1:$($eqlRuntime.httpPort)", '-t', $eqlRoot, "$PSScriptRoot\local-router.php") -WorkingDirectory $eqlRoot -WindowStyle Hidden -RedirectStandardOutput "$($eqlRuntime.logDirectory)\php-output.log" -RedirectStandardError "$($eqlRuntime.logDirectory)\php-error.log" -PassThru
$eqlPhpProcess.Id | Set-Content -LiteralPath "$($eqlRuntime.logDirectory)\php.pid"
Write-Output "EQLWiki started at http://127.0.0.1:$($eqlRuntime.httpPort)"
