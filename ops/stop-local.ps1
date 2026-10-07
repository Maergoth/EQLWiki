$ErrorActionPreference = 'Stop'
$eqlRoot = Split-Path $PSScriptRoot -Parent
$eqlRuntime = Get-Content -LiteralPath "$PSScriptRoot\local-runtime.json" -Raw | ConvertFrom-Json
foreach ($eqlPathKey in @('databaseExecutable', 'databaseConfig', 'clientExecutable', 'clientConfig', 'logDirectory')) {
    if (-not [IO.Path]::IsPathRooted($eqlRuntime.$eqlPathKey)) {
        $eqlRuntime.$eqlPathKey = [IO.Path]::GetFullPath((Join-Path $eqlRoot $eqlRuntime.$eqlPathKey))
    }
}
foreach ($eqlPidFile in @("$($eqlRuntime.logDirectory)\php.pid", "$($eqlRuntime.logDirectory)\php-backend.pid")) {
 if (Test-Path -LiteralPath $eqlPidFile) {
    $eqlPhpPid = [int](Get-Content -LiteralPath $eqlPidFile)
    $eqlProcess = Get-CimInstance Win32_Process -Filter "ProcessId=$eqlPhpPid"
    if ($eqlProcess.Name -eq 'php.exe' -and $eqlProcess.CommandLine.Contains($eqlRoot + '\ops\local-router.php')) {
        Stop-Process -Id $eqlPhpPid
    }
 }
}
& $eqlRuntime.clientExecutable "--defaults-extra-file=$($eqlRuntime.clientConfig)" -e 'SHUTDOWN'
Write-Output 'Local EQLWiki processes stopped.'
