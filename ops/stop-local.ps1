$ErrorActionPreference = 'Stop'
$eqlRoot = Split-Path $PSScriptRoot -Parent
$eqlRuntime = Get-Content -LiteralPath "$PSScriptRoot\local-runtime.json" -Raw | ConvertFrom-Json
$eqlPidFile = "$($eqlRuntime.logDirectory)\php.pid"
if (Test-Path -LiteralPath $eqlPidFile) {
    $eqlPhpPid = [int](Get-Content -LiteralPath $eqlPidFile)
    $eqlProcess = Get-CimInstance Win32_Process -Filter "ProcessId=$eqlPhpPid"
    if ($eqlProcess.Name -eq 'php.exe' -and $eqlProcess.CommandLine.Contains($eqlRoot + '\ops\local-router.php')) {
        Stop-Process -Id $eqlPhpPid
    }
}
& $eqlRuntime.clientExecutable "--defaults-extra-file=$($eqlRuntime.clientConfig)" -e 'SHUTDOWN'
Write-Output 'Local EQLWiki processes stopped.'
