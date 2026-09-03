# Local development

```powershell
cd C:\Code\filament-accounting
composer install
vendor\bin\pint
vendor\bin\phpunit
vendor\bin\phpstan analyse --memory-limit=1G
```

Workbench (Orchestra Testbench):

```powershell
vendor\bin\testbench serve
```

Panel path: `/admin`. Register `FilamentAccountingPlugin::make()` is already done in `workbench/app/Providers/Filament/AdminPanelProvider.php`.

The local Herd demo is the existing app at `%USERPROFILE%\Herd\filament-fints-demo`
(not part of this repository). `scripts/setup-herd-demo.ps1` updates it with a
path repository (`symlink: true` / junction) for the one Accounting product
package. The protocol library is transitive and the bridge is not installed.
Do not copy package source into the demo. Panel path: `/admin`.
