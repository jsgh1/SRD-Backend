<?php
namespace Tests\Feature;

use SrdFiles\AntivirusStatus;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class AntivirusStatusTest extends TestCase
{
    use SignedRequests;

    public function test_probe_requires_gateway_signature_and_returns_only_availability(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'srd-av-');
        try {
            file_put_contents($marker, (string)time()."\n");
            config()->set('photos.signature_marker', $marker);
            config()->set('photos.scanner', 'tcp://127.0.0.1:1');
            $this->getJson('/internal/v1/antivirus-status')->assertUnauthorized();
            $this->internal('GET', 'antivirus-status', [], [], 'identity')->assertForbidden();
            $this->internal('GET', 'antivirus-status')->assertOk()
                ->assertJsonPath('data.available', false)->assertJsonCount(1, 'data');
        } finally {
            unlink($marker);
        }
    }

    public function test_expired_missing_and_future_markers_are_not_ready(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'srd-av-');
        try {
            foreach ([time() - 172801, time() + 301, 'invalid'] as $value) {
                file_put_contents($marker, (string)$value."\n");
                $this->assertFalse((new AntivirusStatus($marker, 'tcp://127.0.0.1:1'))->ready());
            }
            unlink($marker);
            $this->assertFalse((new AntivirusStatus($marker, 'tcp://127.0.0.1:1'))->ready());
        } finally {
            if (is_file($marker)) unlink($marker);
        }
    }

    public function test_recent_marker_still_needs_daemon_response(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'srd-av-');
        try {
            file_put_contents($marker, (string)time()."\n");
            $this->assertFalse((new AntivirusStatus($marker, 'tcp://127.0.0.1:1'))->ready());
        } finally {
            unlink($marker);
        }
    }
}
