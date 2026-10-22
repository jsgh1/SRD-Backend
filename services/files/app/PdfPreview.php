<?php
namespace SrdFiles;

use Closure;

/** Renders one page of an approved PDF as a bounded PNG. */
final class PdfPreview
{
    public function __construct(private string $quarantine, private ?Closure $runner = null) {}

    public function render(string $bytes, int $page = 1): array
    {
        abort_unless($page >= 1 && $page <= 1000, 422);
        abort_unless(strlen($bytes) > 0 && strlen($bytes) <= OfficeDocumentGate::MAX_BYTES
            && is_dir($this->quarantine) && !is_link($this->quarantine), 503);
        $input = tempnam($this->quarantine, 'pdf-view-');
        abort_unless($input !== false, 503);
        $prefix = $input.'-page'; $output = $prefix.'.png';
        try {
            abort_unless(chmod($input, 0600) && file_put_contents($input, $bytes, LOCK_EX) === strlen($bytes), 503);
            $run = $this->runner ?? $this->run(...);
            $info = $run(['/usr/bin/pdfinfo', $input]);
            abort_unless(is_string($info) && preg_match('/^Pages:\s*([1-9][0-9]*)\s*$/m', $info, $matches)
                && (int)$matches[1] <= 1000, 503);
            $pages = (int)$matches[1];
            abort_unless($page <= $pages, 422);
            $command = ['/usr/bin/pdftoppm', '-f', (string)$page, '-l', (string)$page, '-singlefile', '-scale-to', '1200',
                '-hide-annotations', '-png', $input, $prefix];
            $run($command);
            abort_unless(is_file($output) && !is_link($output) && filesize($output) > 0
                && filesize($output) <= 4 * 1024 * 1024, 503);
            $raw = file_get_contents($output);
            abort_unless(is_string($raw), 503);
            $size = @getimagesizefromstring($raw);
            abort_unless($size && $size[2] === IMAGETYPE_PNG && $size[0] >= 1 && $size[1] >= 1
                && $size[0] <= 1200 && $size[1] <= 1200, 503);
            $image = @imagecreatefrompng($output);
            abort_unless($image !== false, 503);
            $buffers = ob_get_level();
            try {
                ob_start();
                $encoded = imagepng($image, null, 6) ? ob_get_clean() : false;
            } finally { imagedestroy($image); while (ob_get_level() > $buffers) ob_end_clean(); }
            abort_unless(is_string($encoded) && strlen($encoded) <= 4 * 1024 * 1024, 503);
            return ['format' => 'image', 'mime' => 'image/png', 'width' => $size[0], 'height' => $size[1],
                'content' => base64_encode($encoded), 'page' => $page, 'pages' => $pages];
        } finally {
            if (is_file($output)) unlink($output);
            if (is_file($input)) unlink($input);
        }
    }

    private function run(array $command): string
    {
        abort_unless(is_executable($command[0]), 503);
        $process = @proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes,
            null, ['LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin']);
        abort_unless(is_resource($process), 503);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 12; $exit = null; $output = '';
        try {
            do {
                $output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
                abort_unless(strlen($output) <= 65536 && microtime(true) <= $deadline, 503);
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                usleep(10000);
            } while (true);
            $output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            abort_unless(strlen($output) <= 65536, 503);
        } finally {
            if ($exit === null) proc_terminate($process);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
        abort_unless($exit === 0, 503);
        return $output;
    }
}
