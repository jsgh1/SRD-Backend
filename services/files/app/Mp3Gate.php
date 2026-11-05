<?php
namespace SrdFiles;

use Closure;
use Illuminate\Validation\ValidationException;

/** Accepts a narrow MPEG-1 Layer III profile with structural and decoder checks. */
final class Mp3Gate
{
    public function __construct(private Scanner $scanner, private string $quarantine,
        private ?Closure $decoder = null) {}

    private function reject(): never
    {
        throw ValidationException::withMessages(['content' => 'Elige un MP3 MPEG-1 Layer III válido de hasta 20 MB.']);
    }

    public function prepare(string $name, string $bytes): array
    {
        $length = strlen($bytes);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'mp3' || $length < 8
            || $length > OfficeDocumentGate::MAX_BYTES) $this->reject();
        $start = 0;
        if (substr($bytes, 0, 3) === 'ID3') {
            if ($length < 10 || !in_array(ord($bytes[3]), [3, 4], true) || ord($bytes[4]) !== 0
                || ord($bytes[5]) !== 0) $this->reject();
            $size = 0;
            for ($i = 6; $i < 10; $i++) {
                $part = ord($bytes[$i]);
                if ($part > 127) $this->reject();
                $size = ($size << 7) | $part;
            }
            if ($size > 1024 * 1024 || 10 + $size > $length) $this->reject();
            $start = 10 + $size;
        }
        $end = $length;
        if ($end - $start >= 128 && substr($bytes, $end - 128, 3) === 'TAG') $end -= 128;
        $rates = [44100, 48000, 32000];
        $bitrates = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
        $offset = $start; $frames = 0; $rate = null;
        while ($offset < $end) {
            if ($end - $offset < 4) $this->reject();
            $a = ord($bytes[$offset]); $b = ord($bytes[$offset + 1]);
            $c = ord($bytes[$offset + 2]); $d = ord($bytes[$offset + 3]);
            $bitrateIndex = ($c >> 4) & 15; $rateIndex = ($c >> 2) & 3;
            if ($a !== 255 || ($b & 0xfe) !== 0xfa || ($c & 1) !== 0
                || $bitrateIndex < 1 || $bitrateIndex > 14 || $rateIndex > 2
                || ($d & 3) !== 0) $this->reject();
            $frameRate = $rates[$rateIndex];
            if ($rate !== null && $rate !== $frameRate) $this->reject();
            $rate = $frameRate;
            $frameSize = intdiv(144000 * $bitrates[$bitrateIndex], $frameRate) + (($c >> 1) & 1);
            if ($frameSize > $end - $offset) $this->reject();
            $offset += $frameSize; $frames++;
        }
        if ($frames < 2 || $offset !== $end) $this->reject();
        if (!is_dir($this->quarantine) || is_link($this->quarantine)) throw new ScannerUnavailable;
        $path = tempnam($this->quarantine, 'mp3-');
        if ($path === false) throw new ScannerUnavailable;
        chmod($path, 0600);
        try {
            if (file_put_contents($path, $bytes, LOCK_EX) !== $length) throw new ScannerUnavailable;
            $this->scanner->assertClean($path);
            $content = substr($bytes, $start, $end - $start);
            if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) throw new ScannerUnavailable;
            $this->scanner->assertClean($path);
            ($this->decoder ?? $this->decode(...))($path);
        } finally { if (is_file($path)) unlink($path); }
        return ['content' => $content, 'mime' => 'audio/mpeg', 'sha256' => hash('sha256', $content)];
    }

    private function decode(string $path): void
    {
        if (!is_executable('/usr/bin/ffmpeg')) throw new ScannerUnavailable('El decodificador MP3 no está disponible.');
        $command = ['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-xerror',
            '-err_detect', 'explode', '-threads', '1', '-f', 'mp3', '-i', $path,
            '-map', '0:a:0', '-f', 'null', '-'];
        $process = @proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes,
            null, ['LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin']);
        if (!is_resource($process)) throw new ScannerUnavailable('El decodificador MP3 no está disponible.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 15; $exit = null; $output = '';
        try {
            do {
                $output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
                if (strlen($output) > 65536 || microtime(true) > $deadline)
                    throw new ScannerUnavailable('El análisis MP3 excedió el límite.');
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                usleep(10000);
            } while (true);
            $output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            if (strlen($output) > 65536) throw new ScannerUnavailable('El análisis MP3 excedió el límite.');
        } finally {
            if ($exit === null) proc_terminate($process);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
        if ($exit !== 0) $this->reject();
    }
}
