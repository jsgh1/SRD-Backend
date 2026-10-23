<?php
namespace SrdFiles;

/** Require a successful local signature update check within the past 48 hours. */
final class UpdatedScanner implements Scanner
{
    public function __construct(private Scanner $scanner, private string $marker) {}

    public function assertClean(string $path): void
    {
        $value = @file_get_contents($this->marker);
        $timestamp = is_string($value) && preg_match('/^[0-9]{10}\n?$/D', $value) ? (int) $value : 0;
        if ($timestamp < time() - 172800 || $timestamp > time() + 300) {
            throw new ScannerUnavailable('No hay confirmación reciente de actualización del antivirus.');
        }
        $this->scanner->assertClean($path);
    }
}
