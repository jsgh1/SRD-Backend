<?php
namespace SrdFiles;

use Illuminate\Validation\ValidationException;

/** A narrow passive WAV policy: PCM 16-bit, mono/stereo, fmt and data chunks only. */
final class AudioGate
{
    public function __construct(private Scanner $scanner, private string $quarantine) {}

    private function reject(): never
    {
        throw ValidationException::withMessages(['content' => 'Elige un WAV PCM de 16 bits, mono o estéreo, de hasta 20 MB.']);
    }

    public function inspect(string $name, string $bytes): array
    {
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'wav' || strlen($bytes) < 46
            || strlen($bytes) > OfficeDocumentGate::MAX_BYTES || substr($bytes, 0, 4) !== 'RIFF'
            || substr($bytes, 8, 4) !== 'WAVE' || unpack('V', substr($bytes, 4, 4))[1] !== strlen($bytes) - 8) $this->reject();
        $length = strlen($bytes); $offset = 12; $format = null; $dataBytes = null;
        while ($offset + 8 <= $length) {
            $id = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $offset += 8;
            if ($size > $length - $offset) $this->reject();
            if ($id === 'fmt ' && $format === null && $size === 16) {
                $format = unpack('vencoding/vchannels/Vrate/Vbyte_rate/valign/vbits', substr($bytes, $offset, 16));
            } elseif ($id === 'data' && $dataBytes === null && $size > 0) {
                $dataBytes = $size;
            } else $this->reject();
            $offset += $size;
            if ($size % 2) {
                if ($offset >= $length || $bytes[$offset] !== "\0") $this->reject();
                $offset++;
            }
        }
        if ($offset !== $length || $format === null || $dataBytes === null
            || $format['encoding'] !== 1 || !in_array($format['channels'], [1, 2], true)
            || $format['rate'] < 8000 || $format['rate'] > 48000 || $format['bits'] !== 16
            || $format['align'] !== $format['channels'] * 2
            || $format['byte_rate'] !== $format['rate'] * $format['align']
            || $dataBytes % $format['align'] !== 0) $this->reject();
        if (!is_dir($this->quarantine) || is_link($this->quarantine)) throw new ScannerUnavailable;
        $path = tempnam($this->quarantine, 'audio-');
        if ($path === false) throw new ScannerUnavailable;
        chmod($path, 0600);
        try {
            if (file_put_contents($path, $bytes, LOCK_EX) !== $length) throw new ScannerUnavailable;
            $this->scanner->assertClean($path);
        } finally { if (is_file($path)) unlink($path); }
        return ['mime' => 'audio/wav', 'sha256' => hash('sha256', $bytes)];
    }
}
