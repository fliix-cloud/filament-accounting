# Updates the existing Herd demo at %USERPROFILE%\Herd\filament-fints-demo.
# Uses Composer path repositories with symlink: true. Does not copy package source.
# Does not configure real FinTS credentials. Never commit the demo into a package repo.

$ErrorActionPreference = "Stop"

$demo = Join-Path $env:USERPROFILE "Herd\filament-fints-demo"
$accounting = "C:\Code\filament-accounting"

function Assert-Command($name) {
    if (-not (Get-Command $name -ErrorAction SilentlyContinue)) {
        throw "$name is required on PATH."
    }
}

Assert-Command php
Assert-Command composer

if (-not (Test-Path (Join-Path $demo "artisan"))) {
    throw "Expected existing Herd demo at $demo. Create it first or clone filament-fints-demo."
}

function Invoke-Checked($description, [scriptblock] $command) {
    & $command

    if ($LASTEXITCODE -ne 0) {
        throw "$description failed with exit code $LASTEXITCODE."
    }
}

foreach ($packagePath in @($accounting)) {
    if (-not (Test-Path (Join-Path $packagePath "composer.json"))) {
        throw "Expected a Composer package at $packagePath."
    }
}

Set-Location $demo

$accountingRepository = @{
    type = "path"
    url = $accounting
    options = @{ symlink = $true }
} | ConvertTo-Json -Compress
# PowerShell 5.1 strips unescaped quotes from native command arguments.
$accountingRepository = $accountingRepository.Replace('"', '\"')

$composerConfig = Get-Content (Join-Path $demo "composer.json") -Raw | ConvertFrom-Json
if ($null -ne $composerConfig.repositories) {
    Invoke-Checked "Removing existing Composer repositories" { composer config --unset repositories }
}

Invoke-Checked "Configuring the Accounting path repository" {
    composer config --json repositories.filament-accounting $accountingRepository
}
Invoke-Checked "Updating the Accounting packages" {
    composer require fliix-cloud/filament-accounting:dev-main --with-dependencies --no-interaction
}

foreach ($pkg in @("filament-accounting")) {
    $path = Join-Path $demo "vendor\fliix-cloud\$pkg"
    if (Test-Path $path) {
        $item = Get-Item $path
        if ($item.LinkType -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            Write-Host "$pkg vendor path is a symlink/junction."
        } else {
            Write-Warning "$pkg vendor path does not look like a symlink/junction."
        }
    }
}

Invoke-Checked "Recreating and seeding the demo database" { php artisan migrate:fresh --seed --force }
Invoke-Checked "Verifying Accounting integrity" { php artisan filament-accounting:verify }

Write-Host ""
Write-Host "Demo ready at $demo"
Write-Host "Panel: / (login: /login)"
Write-Host "FinTS banking and canonical Accounting bank transactions use the same plugin."
Write-Host "The demo database was recreated from the package's target schema."
