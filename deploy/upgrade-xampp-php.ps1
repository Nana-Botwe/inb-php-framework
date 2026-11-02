<#
.SYNOPSIS
    Upgrades the PHP inside XAMPP (Windows) to the latest patch release of a supported branch,
    keeping the old PHP folder for an instant rollback.

.DESCRIPTION
    1. Reads the official build list (downloads.php.net/~windows/releases/releases.json)
    2. Downloads the Thread-Safe x64 build of the branch (what Apache's php8apache2_4.dll needs)
       and checks its SHA-256 against the official list
    3. Renames  <XAMPP>\php  to  <XAMPP>\php-<old version>-backup-<date>
    4. Unpacks the new PHP into <XAMPP>\php, copies XAMPP's extras\ and pear\ folders and your php.ini,
       and comments out extensions that the new version no longer ships (each one is reported)
    5. Checks the new php.exe starts and loads the extensions the framework needs

    Apache's configuration does not change: PHP 8.x always uses php8ts.dll / php8apache2_4.dll.
    Stop Apache (XAMPP Control Panel) before running it, start it again afterwards.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\upgrade-xampp-php.ps1 -DryRun
    powershell -ExecutionPolicy Bypass -File deploy\upgrade-xampp-php.ps1                # PHP 8.4
    powershell -ExecutionPolicy Bypass -File deploy\upgrade-xampp-php.ps1 -Branch 8.3
    powershell -ExecutionPolicy Bypass -File deploy\upgrade-xampp-php.ps1 -Rollback      # back to the old PHP
#>
param(
    [string]$Xampp = 'H:\xampp',
    [ValidatePattern('^8\.[2-9]$')][string]$Branch = '8.4',
    [switch]$DryRun,
    [switch]$Rollback
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # Windows PowerShell's progress bar makes downloads very slow
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$php = Join-Path $Xampp 'php'
$required = @('openssl', 'pdo_mysql', 'pdo_sqlite', 'mbstring', 'fileinfo', 'curl', 'gd', 'zlib', 'sodium')

function Assert-ApacheStopped {
    if (Get-Process -Name httpd -ErrorAction SilentlyContinue) {
        throw 'Apache is running. Stop it in the XAMPP Control Panel first (its PHP files are locked while it runs).'
    }
}

# ------------------------------------------------------------------ rollback
if ($Rollback) {
    $backup = Get-ChildItem $Xampp -Directory -Filter 'php-*-backup-*' | Sort-Object Name -Descending | Select-Object -First 1
    if (-not $backup) { throw "No PHP backup folder found in $Xampp." }
    Assert-ApacheStopped
    $failed = Join-Path $Xampp ('php-failed-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
    Rename-Item $php $failed
    Rename-Item $backup.FullName $php
    Write-Host "Restored $($backup.Name). The upgraded PHP was moved to $failed." -ForegroundColor Green
    & (Join-Path $php 'php.exe') -v
    exit 0
}

# ------------------------------------------------------------------ what to install
$current = (& (Join-Path $php 'php.exe') -r 'echo PHP_VERSION;')
Write-Host "Current XAMPP PHP: $current"

$feed = Invoke-WebRequest -UseBasicParsing -TimeoutSec 60 'https://downloads.php.net/~windows/releases/releases.json' | ConvertFrom-Json
$release = $feed.$Branch
if (-not $release) { throw "PHP $Branch is not in the official Windows build list." }
$buildKey = ($release.PSObject.Properties.Name | Where-Object { $_ -match '^ts-vs\d+-x64$' } | Select-Object -First 1)
$zip = $release.$buildKey.zip
$url = 'https://downloads.php.net/~windows/releases/' + $zip.path
Write-Host "Latest PHP $Branch : $($release.version)  ($buildKey, $($zip.size))"
Write-Host "Download         : $url"

if ($current -eq $release.version) { Write-Host 'Already up to date.' -ForegroundColor Green; exit 0 }
if ($DryRun) { Write-Host 'Dry run: nothing changed.' -ForegroundColor Yellow; exit 0 }

Assert-ApacheStopped

# ------------------------------------------------------------------ download + verify
$tmp = Join-Path $env:TEMP ('php-upgrade-' + [guid]::NewGuid())
New-Item -ItemType Directory $tmp | Out-Null
$file = Join-Path $tmp $zip.path
Invoke-WebRequest -UseBasicParsing -TimeoutSec 600 $url -OutFile $file
$hash = (Get-FileHash $file -Algorithm SHA256).Hash.ToLower()
if ($hash -ne $zip.sha256.ToLower()) { throw "SHA-256 mismatch for $($zip.path) - download rejected." }
Write-Host 'Download verified (SHA-256 matches the official list).' -ForegroundColor Green

# ------------------------------------------------------------------ swap folders
$backupDir = Join-Path $Xampp ("php-$current-backup-" + (Get-Date -Format 'yyyyMMdd-HHmmss'))
Rename-Item $php $backupDir
try {
    Expand-Archive $file -DestinationPath $php
    foreach ($folder in 'extras', 'pear') {
        $source = Join-Path $backupDir $folder
        if (Test-Path $source) { Copy-Item $source (Join-Path $php $folder) -Recurse -Force }
    }

    # Keep your php.ini, minus extensions that no longer exist in this version.
    $ini = Get-Content (Join-Path $backupDir 'php.ini')
    $ext = Join-Path $php 'ext'
    $out = foreach ($line in $ini) {
        if ($line -match '^\s*(zend_)?extension\s*=\s*"?([^";\s]+)"?') {
            $name = $Matches[2]
            $dll = if ($name -like '*.dll') { $name } else { "php_$name.dll" }
            if (-not (Test-Path (Join-Path $ext (Split-Path $dll -Leaf)))) {
                Write-Host "  disabled (not shipped with PHP $Branch): $name" -ForegroundColor Yellow
                "; removed by upgrade-xampp-php.ps1 (not in PHP $Branch): $line"
                continue
            }
        }
        $line
    }
    Set-Content -Path (Join-Path $php 'php.ini') -Value $out -Encoding ASCII

    # ------------------------------------------------------------------ check
    $newPhp = Join-Path $php 'php.exe'
    $version = & $newPhp -r 'echo PHP_VERSION;'
    if ($LASTEXITCODE -ne 0 -or -not $version) { throw 'The new php.exe does not start.' }
    $modules = (& $newPhp -m) | ForEach-Object { $_.Trim().ToLower() }
    $missing = $required | Where-Object { $modules -notcontains $_ }
    if ($missing) { throw ('The new PHP is missing extensions the framework needs: ' + ($missing -join ', ') + '. Enable them in php.ini.') }
    if (-not (Test-Path (Join-Path $php 'php8apache2_4.dll'))) { throw 'php8apache2_4.dll missing - this is not a Thread-Safe build.' }
}
catch {
    Write-Host "Upgrade failed: $($_.Exception.Message)" -ForegroundColor Red
    if (Test-Path $php) { Remove-Item $php -Recurse -Force }
    Rename-Item $backupDir $php
    Write-Host 'The previous PHP was put back. Nothing changed.' -ForegroundColor Yellow
    exit 1
}
finally {
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host ''
Write-Host "PHP upgraded: $current -> $version" -ForegroundColor Green
Write-Host "Old PHP kept in: $backupDir   (undo with -Rollback)"
Write-Host 'Next: start Apache in the XAMPP Control Panel, then in the project folder run:'
Write-Host '  php console test'
Write-Host '  php console security:check'
Write-Host 'If Apache does not start, install the latest "Microsoft Visual C++ Redistributable (x64)" or run -Rollback.'
