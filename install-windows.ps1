[CmdletBinding()]
param(
    [string]$Distro = 'Ubuntu',
    [string]$WebDir = '',
    [string]$PythonDir = '/opt/mdbiot/python',
    [ValidateSet('DeployOnly', 'Full')]
    [string]$Mode = 'DeployOnly',
    [ValidateSet('ask', 'yes', 'no')]
    [string]$InstallMissing = 'ask',
    [ValidateSet('ask', 'yes', 'no')]
    [string]$PythonDeps = 'ask',
    [ValidateSet('ask', 'yes', 'no')]
    [string]$Service = 'ask',
    [switch]$Yes
)

$ErrorActionPreference = 'Stop'

function Confirm-Step([string]$Message, [bool]$DefaultYes = $false) {
    if ($Yes) { return $DefaultYes }
    $suffix = if ($DefaultYes) { '[Y/n]' } else { '[y/N]' }
    $answer = Read-Host "$Message $suffix"
    if ([string]::IsNullOrWhiteSpace($answer)) { return $DefaultYes }
    return $answer -match '^[Yy]'
}

function Convert-ToWslPath([string]$Path) {
    if ($Path.StartsWith('/')) { return $Path }
    $resolved = [System.IO.Path]::GetFullPath($Path)
    $converted = & wsl.exe -d $Distro -- wslpath -a $resolved
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($converted)) {
        throw "Cannot convert Windows path for WSL: $resolved"
    }
    return $converted.Trim()
}

function Quote-Bash([string]$Value) {
    $escaped = $Value.Replace('\', '\\').Replace('"', '\"').Replace('$', '\$').Replace('`', '\`')
    return '"' + $escaped + '"'
}

if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) {
    throw 'WSL is not installed. Run "wsl --install" in an Administrator PowerShell, reboot, then run this installer again.'
}

$distros = @(& wsl.exe --list --quiet) | ForEach-Object { $_.Trim("`0", ' ') } | Where-Object { $_ }
if ($distros -notcontains $Distro) {
    if (-not (Confirm-Step "WSL distribution '$Distro' is missing. Install it now?" $false)) {
        throw "WSL distribution not found: $Distro"
    }
    & wsl.exe --install -d $Distro
    if ($LASTEXITCODE -ne 0) { throw "Failed to install WSL distribution: $Distro" }
    Write-Host 'WSL installation may require a reboot and first-run Linux user setup.' -ForegroundColor Yellow
    Write-Host 'Complete those steps, then run this installer again.' -ForegroundColor Yellow
    exit 0
}

if ([string]::IsNullOrWhiteSpace($WebDir)) {
    $defaultWeb = 'C:\MDBIoT\web'
    if ($Yes) { $WebDir = $defaultWeb }
    else {
        $answer = Read-Host "Existing Windows/Linux web document root [$defaultWeb]"
        $WebDir = if ([string]::IsNullOrWhiteSpace($answer)) { $defaultWeb } else { $answer }
    }
}

$sourceWsl = Convert-ToWslPath $PSScriptRoot
$webWsl = Convert-ToWslPath $WebDir
$modeArg = if ($Mode -eq 'Full') { '--full' } else { '--deploy-only' }
$arguments = @(
    './install.sh', $modeArg,
    '--profile', 'wsl',
    '--web-dir', $webWsl,
    '--python-dir', $PythonDir,
    '--install-missing', $InstallMissing,
    '--python-deps', $PythonDeps,
    '--service', $Service,
    '--source-dir', $sourceWsl
)
if ($Yes) { $arguments += '--yes' }

$quotedArgs = ($arguments | ForEach-Object { Quote-Bash $_ }) -join ' '
$command = "cd $(Quote-Bash $sourceWsl) && sudo bash $quotedArgs"

Write-Host 'MDBIoT WSL deployment' -ForegroundColor Cyan
Write-Host "  Distribution : $Distro"
Write-Host "  Web root     : $WebDir ($webWsl)"
Write-Host "  Python       : $PythonDir"
Write-Host "  Mode         : $Mode"
Write-Host ''
Write-Host 'Existing Windows web servers and databases will not be reconfigured.' -ForegroundColor Yellow

& wsl.exe -d $Distro -- bash -lc $command
if ($LASTEXITCODE -ne 0) { throw "MDBIoT installation failed with exit code $LASTEXITCODE" }

Write-Host 'Deployment completed.' -ForegroundColor Green
Write-Host "Point the existing web server document root/alias to: $WebDir\meow"
