<?php
namespace SrdFiles;

final class ClamdScanner implements Scanner
{
    public function __construct(private string $address, private int $timeout = 15) {}

    public function assertClean(string $path): void
    {
        $socket = @stream_socket_client($this->address, $errno, $error, min(3, $this->timeout));
        if (!$socket) throw new ScannerUnavailable('No se pudo conectar con el analizador local.');
        $file = @fopen($path, 'rb');
        if (!$file) { fclose($socket); throw new ScannerUnavailable('No se pudo leer la cuarentena.'); }
        $deadline = microtime(true) + $this->timeout;
        try {
            $this->write($socket, "zINSTREAM\0", $deadline);
            while (!feof($file)) {
                $chunk = fread($file, 65536);
                if ($chunk === false) throw new ScannerUnavailable('Lectura incompleta.');
                if ($chunk !== '') $this->write($socket, pack('N', strlen($chunk)).$chunk, $deadline);
            }
            $this->write($socket, pack('N', 0), $deadline);
            $reply = '';
            while (!str_contains($reply, "\0") && strlen($reply) < 512) {
                $this->remaining($socket, $deadline);
                $part = fread($socket, 1);
                if ($part === false || $part === '') throw new ScannerUnavailable('Análisis sin confirmación.');
                $reply .= $part;
            }
            if ($reply === "stream: OK\0") return;
            if (preg_match('/^stream: [^\x00\r\n]+ FOUND\x00$/D', $reply)) throw new ImageRejected('El análisis antimalware rechazó el archivo.');
            throw new ScannerUnavailable('El analizador no confirmó un resultado limpio.');
        } finally { fclose($file); fclose($socket); }
    }

    private function remaining($socket, float $deadline): void {
        $left = $deadline - microtime(true);
        if ($left <= 0) throw new ScannerUnavailable('El análisis excedió el tiempo disponible.');
        stream_set_timeout($socket, (int)$left, (int)(($left - (int)$left) * 1000000));
    }
    private function write($socket, string $bytes, float $deadline): void {
        while ($bytes !== '') {
            $this->remaining($socket, $deadline);
            $written = @fwrite($socket, $bytes);
            if ($written === false || $written === 0) throw new ScannerUnavailable('Transmisión incompleta al analizador.');
            $bytes = substr($bytes, $written);
        }
    }
}
