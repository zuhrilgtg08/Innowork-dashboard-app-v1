param([string]$LaragonRoot = 'C:\laragon')

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$runtimeDir = Join-Path $projectRoot '.local'
$phpDir = Get-ChildItem "$LaragonRoot/bin/php" -Directory | Sort-Object Name -Descending | Select-Object -First 1
$apacheDir = Get-ChildItem "$LaragonRoot/bin/apache" -Directory | Sort-Object Name -Descending | Select-Object -First 1
$env:PATH = $phpDir.FullName + ';' + $env:PATH
$expected = @{
    web = Join-Path $apacheDir.FullName 'bin/httpd.exe'
    ml = Join-Path $projectRoot 'ml-service/.venv/Scripts/python.exe'
    queue = Join-Path $phpDir.FullName 'php.exe'
}
foreach ($name in @('web', 'ml', 'queue')) {
    $pidFile = Join-Path $runtimeDir "$name.pid"
    if (!(Test-Path $pidFile)) { continue }
    $servicePid = [int](Get-Content $pidFile)
    $process = Get-Process -Id $servicePid -ErrorAction SilentlyContinue
    if ($process) {
        $recordedAt = (Get-Item $pidFile).LastWriteTime
        if ($process.Path -ne $expected[$name] -or [Math]::Abs(($process.StartTime - $recordedAt).TotalSeconds) -gt 30) {
            throw "PID $servicePid bukan proses $name yang tercatat; tidak dihentikan."
        }
        # These are console processes, not installed Windows services.
        # Include Apache's worker and the venv launcher's Python child.
        & taskkill.exe /PID $servicePid /T /F | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "$name gagal dihentikan." }
        Write-Host "$name dihentikan."
    }
    Remove-Item -LiteralPath $pidFile
}
