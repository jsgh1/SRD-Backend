<?php
namespace SrdFiles;

/** Internal preparation only. This is not an upload endpoint or a resource authorization. */
final class ImageGate
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_PIXELS = 20_000_000;
    public function __construct(private string $quarantine, private Scanner $scanner) {}

    /** Consumer must persist approved bytes privately and enforce resource authorization/quota. */
    public function prepare(string $originalName, string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) throw new ImageRejected('La fotografía debe pesar como máximo 5 MB.');
        if (strlen($originalName) > 255 || preg_match('/[\x00-\x1f\x7f\\\\\/]/', $originalName)) throw new ImageRejected('Nombre de archivo inválido.');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$extension] ?? null;
        if (!$mime || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $mime) throw new ImageRejected('El contenido no coincide con una fotografía JPEG, PNG o WebP.');
        $size = @getimagesizefromstring($bytes);
        if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS || ($size['mime'] ?? '') !== $mime) throw new ImageRejected('Dimensiones de imagen inválidas o excesivas.');
        if (!extension_loaded('gd')) throw new ScannerUnavailable('El decodificador de imágenes no está disponible.');
        if (!is_dir($this->quarantine) || is_link($this->quarantine)) throw new ScannerUnavailable('La cuarentena privada no está preparada.');
        $path = $this->quarantine.DIRECTORY_SEPARATOR.bin2hex(random_bytes(24)).'.pending';
        $file = @fopen($path, 'xb');
        if (!$file) throw new ScannerUnavailable('No se pudo preparar la cuarentena.');
        try {
            if (!chmod($path, 0600) || fwrite($file, $bytes) !== strlen($bytes) || !fflush($file)) throw new ScannerUnavailable('No se pudo escribir la cuarentena.');
            fclose($file); $file = null;
            // Never decode or return content before a real, positive scanner result.
            $this->scanner->assertClean($path);
            $orientation = 1;
            if ($mime === 'image/jpeg') {
                if (!function_exists('exif_read_data')) throw new ScannerUnavailable('El lector de orientación no está disponible.');
                // Read only after the original has passed antivirus. Do not retain metadata.
                $exif = @exif_read_data($path, 'IFD0', true, false);
                $value = $exif['IFD0']['Orientation'] ?? 1;
                if (is_int($value) && $value >= 1 && $value <= 8) $orientation = $value;
            }
            $image = @imagecreatefromstring($bytes);
            if (!$image) throw new ImageRejected('La imagen está dañada o no se puede decodificar.');
            try {
                if (in_array($orientation, [2,5,7], true) && !imageflip($image, IMG_FLIP_HORIZONTAL)) throw new ImageRejected('No se pudo orientar la imagen.');
                if ($orientation === 4 && !imageflip($image, IMG_FLIP_VERTICAL)) throw new ImageRejected('No se pudo orientar la imagen.');
                $angle = match ($orientation) { 3 => 180, 5,8 => 90, 6,7 => -90, default => 0 };
                if ($angle !== 0) {
                    $rotated = imagerotate($image, $angle, 0);
                    if (!$rotated) throw new ImageRejected('No se pudo orientar la imagen.');
                    imagedestroy($image); $image = $rotated;
                }
                $width = imagesx($image); $height = imagesy($image);
                // Re-encode to PNG: remove EXIF, ancillary content and original filename/path.
                imagesavealpha($image, true);
                ob_start();
                try { $ok = imagepng($image, null, 6); $normalized = ob_get_contents(); }
                finally { ob_end_clean(); }
                if (!$ok || !is_string($normalized) || strlen($normalized) > self::MAX_BYTES) throw new ImageRejected('La imagen normalizada supera 5 MB o no pudo generarse.');
            } finally { imagedestroy($image); }
            // Only the normalized bytes returned here may become a private object.
            if (file_put_contents($path, $normalized, LOCK_EX) !== strlen($normalized)) throw new ScannerUnavailable('No se pudo validar la imagen normalizada.');
            $this->scanner->assertClean($path);
            return ['content'=>$normalized, 'mime'=>'image/png', 'extension'=>'png', 'size'=>strlen($normalized), 'sha256'=>hash('sha256',$normalized), 'width'=>$width, 'height'=>$height];
        } finally {
            if (is_resource($file)) fclose($file);
            if (is_file($path) && !unlink($path)) throw new ScannerUnavailable('No se pudo retirar la cuarentena; se requiere revisión local.');
        }
    }
}
