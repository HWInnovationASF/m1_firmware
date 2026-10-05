param([string]$SevenZip = "C:\Program Files\7-Zip\7z.exe")

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$htmlArchive = Join-Path $projectRoot 'html.7z'
$pythonArchive = Join-Path $projectRoot 'python.7z'

if (-not (Test-Path -LiteralPath $SevenZip -PathType Leaf)) { throw "7-Zip not found: $SevenZip" }
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'html') -PathType Container)) { throw 'HTML source directory not found' }
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'python') -PathType Container)) { throw 'Python source directory not found' }

$commonExcludes = @(
    '-xr!.git', '-xr!.github', '-xr!.vscode', '-xr!.claude', '-xr!node_modules',
    '-xr!__pycache__', '-xr!*.pyc', '-xr!*.bak', '-xr!*.bak_*',
    '-xr!*.backup', '-xr!*.backup_*', '-xr!*.log', '-xr!*.tmp'
)

Push-Location $projectRoot
try {
    Remove-Item -LiteralPath $htmlArchive, $pythonArchive -Force -ErrorAction SilentlyContinue
    & $SevenZip a -t7z -mx=9 -mmt=on $htmlArchive '.\html' @commonExcludes `
        '-xr!html\meow\config' '-xr!html\meow\data' '-xr!html\meow\userauth.json' `
        '-xr!html\meow\log_device' '-xr!html\meow\log_err' '-xr!html\meow\dlog' '-xr!html\meow\textfile'
    if ($LASTEXITCODE -ne 0) { throw 'Failed to build html.7z' }
    & $SevenZip a -t7z -mx=9 -mmt=on $pythonArchive '.\python' @commonExcludes `
        '-xr!python\VPN' '-xr!python\log_action' '-xr!python\log_err' '-xr!python\dlog' `
        '-xr!python\battery_control_logs' '-xr!python\mqtt_command_history.json' `
        '-xr!python\automation_last_commands.json' '-xr!python\battery_last_good.json' `
        '-xr!python\soc_last_good.json' '-xr!python\*.json.lock'
    if ($LASTEXITCODE -ne 0) { throw 'Failed to build python.7z' }

    & $SevenZip t $htmlArchive
    if ($LASTEXITCODE -ne 0) { throw 'html.7z integrity test failed' }
    & $SevenZip t $pythonArchive
    if ($LASTEXITCODE -ne 0) { throw 'python.7z integrity test failed' }

    $checksums = @(
        "$(Get-FileHash -LiteralPath $htmlArchive -Algorithm SHA256 | Select-Object -ExpandProperty Hash) *html.7z",
        "$(Get-FileHash -LiteralPath $pythonArchive -Algorithm SHA256 | Select-Object -ExpandProperty Hash) *python.7z"
    ) | ForEach-Object { $_.ToLowerInvariant() }
    $manifestPath = Join-Path $projectRoot 'SHA256SUMS'
    [System.IO.File]::WriteAllText($manifestPath, (($checksums -join "`n") + "`n"), [System.Text.Encoding]::ASCII)
    Write-Host 'Installer package built successfully.' -ForegroundColor Green
    Get-Item $htmlArchive, $pythonArchive | Select-Object Name, Length, LastWriteTime
}
finally { Pop-Location }
