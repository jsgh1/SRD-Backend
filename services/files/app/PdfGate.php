<?php
namespace SrdFiles;

use Closure;
use Illuminate\Validation\ValidationException;

/** Bounded PDF upload policy. The approved original remains private. */
final class PdfGate
{
    public function __construct(private Scanner $scanner, private string $quarantine,
        private ?Closure $runner = null) {}

    private function reject(): never
    {
        throw ValidationException::withMessages(['content' => 'Elige un PDF válido, sin cifrado, formularios, scripts ni archivos adjuntos, de hasta 20 MB.']);
    }

    public function inspect(string $name, string $bytes): array
    {
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf' || strlen($bytes) < 100
            || strlen($bytes) > OfficeDocumentGate::MAX_BYTES
            || !preg_match('/^%PDF-(?:1\.[0-7]|2\.0)\r?\n/D', $bytes)
            || !preg_match('/%%EOF[ \t\r\n]*\z/D', substr($bytes, -32))) $this->reject();
        // Reject plainly declared active features before invoking an external parser.
        if (preg_match('/\/(?:JavaScript|JS|Launch|OpenAction|AA|RichMedia|SubmitForm|GoToR|GoToE|EmbeddedFiles|Filespec|AcroForm|XFA|Encrypt)\b/', $bytes)) $this->reject();
        if (!is_dir($this->quarantine) || is_link($this->quarantine)) throw new ScannerUnavailable;
        $path = tempnam($this->quarantine, 'pdf-');
        if ($path === false) throw new ScannerUnavailable;
        try {
            if (!chmod($path, 0600) || file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new ScannerUnavailable;
            $this->scanner->assertClean($path);
            $run = $this->runner ?? $this->run(...);
            $info = $run(['/usr/bin/pdfinfo', $path]);
            if (!preg_match('/^Pages:\s*([1-9][0-9]*)\s*$/m', $info, $pages) || (int)$pages[1] > 1000
                || !preg_match('/^Encrypted:\s*no\s*$/m', $info)
                || !preg_match('/^JavaScript:\s*no\s*$/m', $info)
                || !preg_match('/^Form:\s*none\s*$/m', $info)) $this->reject();
            if (trim($run(['/usr/bin/pdfinfo', '-js', $path])) !== '') $this->reject();
            if (!preg_match('/^0 embedded files\s*$/D', trim($run(['/usr/bin/pdfdetach', '-list', $path])))) $this->reject();
            return ['mime' => 'application/pdf', 'sha256' => hash('sha256', $bytes)];
        } finally {
            if (is_file($path) && !unlink($path)) throw new ScannerUnavailable('No se pudo retirar la cuarentena.');
        }
    }

    private function run(array $command): string
    {
        if (!is_executable($command[0])) throw new ScannerUnavailable('El validador de PDF no está disponible.');
        $process = @proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes,
            null, ['LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin']);
        if (!is_resource($process)) throw new ScannerUnavailable('El validador de PDF no está disponible.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $errors = ''; $deadline = microtime(true) + 10; $exit = null;
        try {
            do {
                $output .= stream_get_contents($pipes[1]);
                $errors .= stream_get_contents($pipes[2]);
                if (strlen($output) > 65536 || strlen($errors) > 65536 || microtime(true) > $deadline)
                    throw new ScannerUnavailable('La validación de PDF excedió el límite.');
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                usleep(10000);
            } while (true);
            $output .= stream_get_contents($pipes[1]);
            if (strlen($output) > 65536) throw new ScannerUnavailable('La validación de PDF excedió el límite.');
        } finally {
            if ($exit === null) proc_terminate($process);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
        if ($exit !== 0) $this->reject();
        return $output;
    }
}
