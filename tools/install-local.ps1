param(
    [string]$ModelZip,
    [string]$CameraUrl = 'rtsp://192.168.0.100:8550/video',
    [string]$LaragonRoot = 'C:\laragon',
    [string]$PythonExe = 'python.exe'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
if ($ModelZip) { $ModelZip = (Resolve-Path -LiteralPath $ModelZip).Path }
Set-Location $projectRoot

function Invoke-Checked([string]$Executable, [string[]]$Arguments) {
    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Perintah gagal: $Executable (exit $LASTEXITCODE)" }
}

$phpDir = Get-ChildItem "$LaragonRoot/bin/php" -Directory | Sort-Object Name -Descending | Select-Object -First 1
if (!$phpDir) { throw 'Pasang Laragon dengan PHP 8.2+ dan Apache terlebih dahulu.' }
$php = Join-Path $phpDir.FullName 'php.exe'
$composer = Join-Path $LaragonRoot 'bin/composer/composer.phar'
if (!(Test-Path $composer)) { throw "Composer Laragon tidak ditemukan: $composer" }
$npm = (Get-Command npm.cmd -ErrorAction Stop).Source
$python = (Get-Command $PythonExe -ErrorAction Stop).Source
$pythonVersion = & $python --version
if ($LASTEXITCODE -ne 0 -or $pythonVersion -notmatch '^Python 3\.(11|12)\.') {
    throw 'Gunakan Python 3.11 atau 3.12 untuk setup yang telah diuji.'
}

$runtimeDir = Join-Path $projectRoot '.local'
New-Item -ItemType Directory -Force -Path $runtimeDir | Out-Null
$env:PATH = $phpDir.FullName + ';' + $env:PATH
$env:COMPOSER_HOME = Join-Path $runtimeDir 'composer'
$env:YOLO_CONFIG_DIR = Join-Path $runtimeDir 'ultralytics'
$env:MPLCONFIGDIR = Join-Path $runtimeDir 'matplotlib'
New-Item -ItemType Directory -Force -Path $env:YOLO_CONFIG_DIR, $env:MPLCONFIGDIR | Out-Null

$prepareArgs = @((Join-Path $PSScriptRoot 'prepare-local.py'), '--camera-url', $CameraUrl)
if ($ModelZip) { $prepareArgs += @('--model-zip', $ModelZip) }
Invoke-Checked $python $prepareArgs

$phpOptions = @()
$modules = & $php -m
if ($modules -notcontains 'zip') { $phpOptions = @('-d', 'extension=zip') }
Invoke-Checked $php ($phpOptions + @($composer, 'install', '--no-interaction', '--prefer-dist'))
Invoke-Checked $npm @('ci', '--cache', '.local/npm-cache', '--no-audit', '--no-fund')
Invoke-Checked $npm @('run', 'build')

$venvPython = Join-Path $projectRoot 'ml-service/.venv/Scripts/python.exe'
if (!(Test-Path $venvPython)) { Invoke-Checked $python @('-m', 'venv', 'ml-service/.venv') }
Invoke-Checked $venvPython @('-m', 'pip', 'install', '--upgrade', 'pip', '--cache-dir', '.local/pip-cache', '--no-compile')
Invoke-Checked $venvPython @('-m', 'pip', 'install', 'torch', 'torchvision', '--index-url', 'https://download.pytorch.org/whl/cpu', '--cache-dir', '.local/pip-cache', '--no-compile')
Invoke-Checked $venvPython @('-m', 'pip', 'install', '-r', 'ml-service/requirements.txt', '--cache-dir', '.local/pip-cache', '--no-compile')
Invoke-Checked $venvPython @('-m', 'pip', 'check')

$appEnv = Get-Content .env -Raw
if ($appEnv -notmatch '(?m)^APP_KEY="?base64:') { Invoke-Checked $php @('artisan', 'key:generate') }
$database = Join-Path $projectRoot 'database/database.sqlite'
if (!(Test-Path $database)) { New-Item -ItemType File -Path $database | Out-Null }
Invoke-Checked $php @('artisan', 'config:clear')
Invoke-Checked $php @('artisan', 'migrate', '--force')

# Seed only an empty users table; never erase an existing database.
$userCount = & $venvPython (Join-Path $PSScriptRoot 'prepare-local.py') --user-count
if ($LASTEXITCODE -ne 0) { throw 'Gagal memeriksa akun database lokal.' }
if ([int]$userCount -eq 0) { Invoke-Checked $php @('artisan', 'db:seed', '--force') }
New-Item -ItemType Directory -Force -Path storage/app/public | Out-Null
if (!(Test-Path public/storage)) {
    New-Item -ItemType Junction -Path public/storage -Target (Join-Path $projectRoot 'storage/app/public') | Out-Null
}
Write-Host 'Instalasi selesai. Jalankan tools/start-services.ps1, lalu tools/show-xy.ps1.'
