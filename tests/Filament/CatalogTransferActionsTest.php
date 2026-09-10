<?php

namespace FilamentAccounting\Tests\Filament;

use Filament\Support\Livewire\Partials\DataStoreOverride;
use FilamentAccounting\Catalog\ImportExport\CatalogExporter;
use FilamentAccounting\Filament\Resources\CatalogItemResource\Pages\ListCatalogItems;
use FilamentAccounting\Filament\Support\CatalogTransferActions;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Livewire\Mechanisms\DataStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class CatalogTransferActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Action modals need Filament's partial-render-aware store, which the base fixture resets.
        $this->app->instance(DataStore::class, new DataStoreOverride);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $this->makeEntity();
        $this->actingAs($this->makeUser());
    }

    public static function formats(): array
    {
        return [['xlsx'], ['xls'], ['csv'], ['json']];
    }

    public static function ambiguousMimeTypes(): array
    {
        return [
            ['xlsx', 'application/zip'], ['xlsx', 'application/octet-stream'],
            ['xls', 'application/x-ole-storage'], ['xls', 'application/CDFV2'],
            ['csv', 'application/octet-stream'], ['json', 'text/plain'],
        ];
    }

    #[Test]
    #[DataProvider('ambiguousMimeTypes')]
    public function valid_catalog_files_are_validated_by_contents_despite_ambiguous_mime_types(string $format, string $mime): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-mime-');
        try {
            app(CatalogExporter::class)->export($path, $format, true);
            $upload = UploadedFile::fake()->createWithContent('catalog.'.$format, file_get_contents($path))->mimeType($mime);
            $page = Livewire::test(ListCatalogItems::class)->mountAction('importCatalog')
                ->setActionData(['file' => $upload, 'update_existing' => true])
                ->goToNextWizardStep()->assertHasNoActionErrors();
            $this->assertSame(0, CatalogItem::query()->count());
            $page->callMountedAction()->assertHasNoActionErrors();
            $this->assertSame(1, CatalogItem::query()->count());
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function a_binary_file_renamed_to_xlsx_is_rejected_by_the_reader(): void
    {
        $upload = UploadedFile::fake()->createWithContent('fake.xlsx', "not an Excel file\x00\xFF")->mimeType('application/octet-stream');
        Livewire::test(ListCatalogItems::class)->mountAction('importCatalog')
            ->setActionData(['file' => $upload, 'update_existing' => true])->goToNextWizardStep()
            ->assertNotified(__('filament-accounting::catalog_transfer.failed'));
        $this->assertSame(0, CatalogItem::query()->count());
    }

    #[Test]
    public function unsupported_filename_extensions_are_rejected_even_for_valid_catalog_contents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-extension-');
        try {
            app(CatalogExporter::class)->export($path, 'json', true);
            $upload = UploadedFile::fake()->createWithContent('catalog.php', file_get_contents($path))->mimeType('application/json');
            Livewire::test(ListCatalogItems::class)->mountAction('importCatalog')
                ->setActionData(['file' => $upload, 'update_existing' => true])->goToNextWizardStep()
                ->assertHasErrors();
            $this->assertSame(0, CatalogItem::query()->count());
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function catalog_actions_are_visible_and_default_export_is_xlsx(): void
    {
        Livewire::test(ListCatalogItems::class)
            ->assertActionVisible('importCatalog')->assertActionVisible('exportCatalog')->assertActionVisible('catalogTemplate')
            ->mountAction('exportCatalog')->assertActionDataSet(['format' => 'xlsx']);
    }

    #[Test]
    #[DataProvider('formats')]
    public function each_template_can_be_downloaded_and_uploaded_through_filament(string $format): void
    {
        Livewire::test(ListCatalogItems::class)->callAction('catalogTemplate', ['format' => $format])
            ->assertHasNoActionErrors()->assertFileDownloaded('catalog-template.'.$format);
        $path = tempnam(sys_get_temp_dir(), 'catalog-ui-');
        try {
            app(CatalogExporter::class)->export($path, $format, true);
            $upload = UploadedFile::fake()->createWithContent('catalog.'.$format, file_get_contents($path));
            $page = Livewire::test(ListCatalogItems::class)->mountAction('importCatalog')
                ->setActionData(['file' => $upload, 'update_existing' => true])
                ->goToNextWizardStep()->assertHasNoActionErrors();
            $this->assertSame(0, CatalogItem::query()->count());
            $this->assertStringContainsString('1', $page->get('catalogImportPreview'));
            $page->callMountedAction()
                ->assertHasNoActionErrors()->assertNotified(__('filament-accounting::catalog_transfer.success'));
            $this->assertSame(1, CatalogItem::query()->count());
            Livewire::test(ListCatalogItems::class)->callAction('exportCatalog', ['format' => $format])
                ->assertHasNoActionErrors()->assertFileDownloaded('catalog.'.$format);
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function malformed_upload_shows_translated_error_and_writes_nothing(): void
    {
        $upload = UploadedFile::fake()->createWithContent('bad.csv', "ARTIKELNR;KURZBEZEICHNUNG;VK PREIS\r\n1;Test;19.00\r\n");
        Livewire::test(ListCatalogItems::class)->callAction('importCatalog', ['file' => $upload, 'update_existing' => true])
            ->assertNotified(__('filament-accounting::catalog_transfer.failed'));
        $this->assertSame(0, CatalogItem::query()->count());
    }

    #[Test]
    public function unauthorized_actions_are_hidden(): void
    {
        Gate::define(config('filament-accounting.authorization.abilities.manage_catalog'), fn () => false);
        $this->assertTrue(CatalogTransferActions::import()->isHidden());
        $this->assertTrue(CatalogTransferActions::export()->isHidden());
        $this->assertTrue(CatalogTransferActions::export(true)->isHidden());
        Livewire::test(ListCatalogItems::class)->assertForbidden();
    }
}
