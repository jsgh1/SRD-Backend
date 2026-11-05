<?php
namespace Tests\Feature;

use SrdFiles\{Scanner, ScannerUnavailable, UpdatedScanner};
use Tests\TestCase;

final class UpdatedScannerTest extends TestCase
{
    public function test_only_recent_successful_update_markers_allow_scanning(): void
    {
        $marker = storage_path('framework/cache/update-probe-'.bin2hex(random_bytes(10)));
        $scanner = new class implements Scanner {
            public int $calls = 0;
            public function assertClean(string $path): void { $this->calls++; }
        };
        $guard = new UpdatedScanner($scanner, $marker);
        try {
            foreach ([null, '', 'garbage', (string)(time()-172801), (string)(time()+600)] as $value) {
                if ($value !== null) file_put_contents($marker, $value);
                try { $guard->assertClean('synthetic'); $this->fail('An update check is required'); }
                catch (ScannerUnavailable) { $this->assertSame(0, $scanner->calls); }
            }
            file_put_contents($marker, time()."\n");
            $guard->assertClean('synthetic');
            $this->assertSame(1, $scanner->calls);
            // The marker is re-read on every scan; no cached grant after expiry.
            file_put_contents($marker, (string)(time()-172801));
            try { $guard->assertClean('synthetic'); $this->fail('Expired update accepted'); }
            catch (ScannerUnavailable) { $this->assertSame(1, $scanner->calls); }
        } finally {
            if (is_file($marker)) unlink($marker);
        }
    }
}
