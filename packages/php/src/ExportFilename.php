<?php

namespace Srd;

use Illuminate\Validation\ValidationException;

final class ExportFilename
{
    public static function xlsx(string $type, ?string $requested, bool $confirmed): string
    {
        $name = trim($requested ?? '');
        if ($name === '') {
            return $type.'_'.now('America/Bogota')->format('Y-m-d').'.xlsx';
        }
        if (!$confirmed) {
            throw ValidationException::withMessages(['confirm_filename' => 'Confirma el nombre antes de descargar.']);
        }

        // Keep one portable filename segment; the client never supplies a path or extension.
        $name = preg_replace('/\.xlsx$/i', '', $name);
        $ascii = \Illuminate\Support\Str::ascii($name ?? '');
        $safe = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $ascii);
        $safe = trim(preg_replace('/[._ -]{2,}/', '-', $safe ?? ''), ' ._-');
        if ($safe === '') {
            throw ValidationException::withMessages(['filename' => 'Escribe un nombre con letras o números.']);
        }
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $safe)) {
            $safe = 'archivo-'.$safe;
        }
        return $safe.'.xlsx';
    }

    public static function pdf(string $type, ?string $requested, bool $confirmed): string
    {
        $name = trim($requested ?? '');
        if ($name === '') {
            return $type.'_'.now('America/Bogota')->format('Y-m-d').'.pdf';
        }
        if (!$confirmed) {
            throw ValidationException::withMessages(['confirm_filename' => 'Confirma el nombre antes de descargar.']);
        }
        $name = preg_replace('/(?:\.(?:xlsx|pdf))+$/i', '', $name);
        $ascii = \Illuminate\Support\Str::ascii($name ?? '');
        $safe = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $ascii);
        $safe = trim(preg_replace('/[._ -]{2,}/', '-', $safe ?? ''), ' ._-');
        if ($safe === '') {
            throw ValidationException::withMessages(['filename' => 'Escribe un nombre con letras o números.']);
        }
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $safe)) {
            $safe = 'archivo-'.$safe;
        }
        return $safe.'.pdf';
    }
}
