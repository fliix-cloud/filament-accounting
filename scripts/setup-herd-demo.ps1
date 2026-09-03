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

Set-Location $demo

composer require fliix-cloud/filament-accounting:dev-main --no-interaction

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

php artisan migrate --force
php artisan db:seed --force --class=Database\\Seeders\\AccountingDemoSeeder
php artisan filament-accounting:consolidate-legacy --dry-run
php artisan filament-accounting:verify

Write-Host ""
Write-Host "Demo ready at $demo"
Write-Host "Panel: /admin (existing Filament login)"
Write-Host "FinTS banking and canonical Accounting bank transactions use the same plugin."
Write-Host "Run filament-accounting:consolidate-legacy only after its dry run has no blockers."
Write-Host "Rerun is non-destructive."
