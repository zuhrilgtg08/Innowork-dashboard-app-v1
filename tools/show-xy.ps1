param([string]$Url = '')

$ErrorActionPreference = 'Stop'
$Host.UI.RawUI.WindowTitle = 'YOLOv11 - Deteksi dan koordinat X/Y'
$projectRoot = Split-Path $PSScriptRoot -Parent
Set-Location $projectRoot
$python = Join-Path $projectRoot 'ml-service/.venv/Scripts/python.exe'
if (!(Test-Path $python)) { $python = (Get-Command python.exe).Source }
$monitorArgs = @('-u', (Join-Path $PSScriptRoot 'watch-detections.py'), '--robot')
if ($Url) { $monitorArgs += @('--url', $Url) }
& $python @monitorArgs
