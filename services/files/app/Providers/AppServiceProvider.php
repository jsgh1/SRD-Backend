<?php
namespace App\Providers;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Srd\InternalClient;
use SrdFiles\{AssetPhotoStorage, ClamdScanner, IdentityPhotoAuthorizer, ImageGate, InventoryPhotoAuthorizer, PhotoStorage, PhotoStore, RecordsPhotoAuthorizer, UserPhotoStorage};

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\SrdFiles\OfficePreview::class, fn () => new \SrdFiles\OfficePreview(config('photos.quarantine')));
        $this->app->bind(\SrdFiles\PdfPreview::class, fn () => new \SrdFiles\PdfPreview(config('photos.quarantine')));
        $this->app->bind(\SrdFiles\ChatAttachmentStore::class, fn () => new \SrdFiles\ChatAttachmentStore(
            new ImageGate(config('photos.quarantine'), new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'), 15), config('photos.signature_marker'))),
            new \SrdFiles\PdfGate(new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'), 60), config('photos.signature_marker')), config('photos.quarantine')),
            new \SrdFiles\OfficeDocumentGate(new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'), 60), config('photos.signature_marker')), config('photos.quarantine')),
            new \SrdFiles\AudioGate(new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'), 60), config('photos.signature_marker')), config('photos.quarantine')),
            new \SrdFiles\Mp3Gate(new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'), 60), config('photos.signature_marker')), config('photos.quarantine')),
            config('photos.quota')));
        $this->app->bind(\SrdFiles\FolderDocumentStore::class, fn () => new \SrdFiles\FolderDocumentStore(
            // Large Office packages need more time than photos; remain below the gateway's 75-second deadline.
            new \SrdFiles\OfficeDocumentGate(new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'),60),config('photos.signature_marker')),config('photos.quarantine')),
            config('photos.objects'),config('photos.quota'),
            new ImageGate(config('photos.quarantine'),new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'),15),config('photos.signature_marker'))),
            new \SrdFiles\AudioGate(new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'),60),config('photos.signature_marker')),config('photos.quarantine')),
            new \SrdFiles\PdfGate(new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'),60),config('photos.signature_marker')),config('photos.quarantine')),
            new \SrdFiles\Mp3Gate(new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'),60),config('photos.signature_marker')),config('photos.quarantine'))));
        $this->app->bind(PhotoStorage::class, function () {
            return new PhotoStore(
                DB::connection()->getPdo(),
                config('photos.objects'),
                new ImageGate(config('photos.quarantine'), new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'), 15), config('photos.signature_marker'))),
                Closure::fromCallable(new RecordsPhotoAuthorizer(new InternalClient)),
                config('photos.quota'),
            );
        });
        $this->app->bind(AssetPhotoStorage::class, function () {
            return new PhotoStore(
                DB::connection()->getPdo(),
                config('photos.objects'),
                new ImageGate(config('photos.quarantine'), new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'), 15), config('photos.signature_marker'))),
                Closure::fromCallable(new InventoryPhotoAuthorizer(new InternalClient)),
                config('photos.quota'),
                'asset',
            );
        });
        $this->app->bind(UserPhotoStorage::class, function () {
            return new PhotoStore(
                DB::connection()->getPdo(),
                config('photos.objects'),
                new ImageGate(config('photos.quarantine'), new \SrdFiles\UpdatedScanner(new ClamdScanner(config('photos.scanner'), 15), config('photos.signature_marker'))),
                Closure::fromCallable(new IdentityPhotoAuthorizer(new InternalClient)),
                config('photos.quota'),
                'user',
            );
        });
    }
}
