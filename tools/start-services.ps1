param([string]$LaragonRoot = 'C:\laragon')

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$runtimeDir = Join-Path $projectRoot '.local'
New-Item -ItemType Directory -Force -Path $runtimeDir | Out-Null
$phpDir = Get-ChildItem "$LaragonRoot/bin/php" -Directory | Sort-Object Name -Descending | Select-Object -First 1
$apacheDir = Get-ChildItem "$LaragonRoot/bin/apache" -Directory | Sort-Object Name -Descending | Select-Object -First 1
if (!$phpDir -or !$apacheDir) { throw 'PHP dan Apache Laragon tidak ditemukan.' }
$php = Join-Path $phpDir.FullName 'php.exe'
$apache = Join-Path $apacheDir.FullName 'bin/httpd.exe'
$python = Join-Path $projectRoot 'ml-service/.venv/Scripts/python.exe'
foreach ($required in @('vendor/autoload.php', 'public/build/manifest.json', '.env', 'database/database.sqlite', 'ml-service/.venv/Scripts/python.exe', 'ml-service/.venv/Lib/site-packages/uvicorn', 'ml-service/.venv/Lib/site-packages/ultralytics')) {
    if (!(Test-Path (Join-Path $projectRoot $required))) { throw "Instalasi belum lengkap: $required" }
}

# Apache on Windows serves concurrent MJPEG streams and dashboard requests.
$rootUnix = $projectRoot.Replace('\', '/')
$apacheUnix = $apacheDir.FullName.Replace('\', '/')
$phpUnix = $phpDir.FullName.Replace('\', '/')
$configFile = Join-Path $runtimeDir 'httpd.conf'
$apacheConfig = @"
ServerRoot "$apacheUnix"
Listen 127.0.0.1:8080
ServerName 127.0.0.1
PidFile "$rootUnix/.local/httpd.pid"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule mime_module modules/mod_mime.so
LoadModule dir_module modules/mod_dir.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule php_module "$phpUnix/php8apache2_4.dll"
PHPIniDir "$phpUnix"
TypesConfig conf/mime.types
DirectoryIndex index.php
DocumentRoot "$rootUnix/public"
ErrorLog "$rootUnix/.local/apache-error.log"
LogLevel warn
ThreadsPerChild 64
<Directory "$rootUnix/public">
    Options FollowSymLinks
    AllowOverride All
    Require local
</Directory>
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
"@
[IO.File]::WriteAllText($configFile, $apacheConfig, (New-Object Text.UTF8Encoding $false))

$env:PATH = $phpDir.FullName + ';' + $env:PATH
$env:YOLO_CONFIG_DIR = Join-Path $runtimeDir 'ultralytics'
$env:MPLCONFIGDIR = Join-Path $runtimeDir 'matplotlib'
New-Item -ItemType Directory -Force -Path $env:YOLO_CONFIG_DIR, $env:MPLCONFIGDIR | Out-Null
& $apache -t -f $configFile
if ($LASTEXITCODE -ne 0) { throw 'Konfigurasi Apache tidak valid.' }

function Start-LocalService($Name, $Executable, $Arguments, $Directory, $Port = 0) {
    $pidFile = Join-Path $runtimeDir "$Name.pid"
    if (Test-Path $pidFile) {
        $servicePid = [int](Get-Content $pidFile)
        $running = Get-Process -Id $servicePid -ErrorAction SilentlyContinue
        if ($running -and $running.Path -eq $Executable) {
            Write-Host "$Name sudah berjalan (PID $servicePid)."
            return
        }
    }
    if ($Port) {
        $probe = New-Object Net.Sockets.TcpClient
        try {
            $probe.Connect('127.0.0.1', $Port)
            throw "Port $Port sudah digunakan. Hentikan server sebelumnya dahulu."
        } catch [Net.Sockets.SocketException] {
            # Port free.
        } finally { $probe.Dispose() }
    }
    $process = Start-Process -FilePath $Executable -ArgumentList $Arguments -WorkingDirectory $Directory -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDir "$Name.log") -RedirectStandardError (Join-Path $runtimeDir "$Name-error.log")
    $process.Id | Set-Content -LiteralPath $pidFile
    if ($process.WaitForExit(1500)) { throw "$Name berhenti saat startup. Periksa .local/$Name-error.log" }
    Write-Host "$Name dimulai (PID $($process.Id))."
}

Start-LocalService 'web' $apache @('-f', "`"$configFile`"") $projectRoot 8080
Start-LocalService 'ml' $python @('-m', 'uvicorn', 'main:app', '--host', '127.0.0.1', '--port', '8002') (Join-Path $projectRoot 'ml-service') 8002
Start-LocalService 'queue' $php @('artisan', 'queue:work', '--sleep=3', '--tries=1') $projectRoot
Write-Host 'Web: http://127.0.0.1:8080 | Log: .local/'
